<?php

declare(strict_types=1);

namespace Rostam\Kv\Protocol;

use Rostam\Exceptions\ConnectionException;
use Rostam\Exceptions\ProtocolException;
use Rostam\Exceptions\StaleConnectionException;

/**
 * One socket to a Rostam server, plus the framing that rides on it.
 *
 * Construction opens nothing: the stream is dialled on the first write, so a
 * store can be built (and a container resolved) with no server listening yet.
 */
class Connection
{
    /** @var resource|null */
    protected $stream = null;

    public function __construct(protected readonly ConnectionConfig $config) {}

    public function config(): ConnectionConfig
    {
        return $this->config;
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * Open the socket, and on a persistent one make sure it is actually clean.
     *
     * A persistent socket OUTLIVES the PHP request that opened it, so the one
     * handed back here may have been abandoned mid-exchange by a worker that
     * was killed - leaving a response nobody read sitting in its buffer. Using
     * it would pair this request with the previous request's answer: a `get`
     * returning another key's value, silently, with no error anywhere. That is
     * the failure isDirty() exists to prevent, and the pool alone cannot
     * prevent it: the pool only screens its own in-process free list, which is
     * EMPTY at the start of every request, precisely when the OS-level socket
     * is at its most suspect.
     *
     * fclose() drops the stream from PHP's persistent registry, so the second
     * dial really does get a new socket. If that one is dirty too, something is
     * speaking on a connection this protocol never speaks first on, and
     * refusing is the only safe answer left.
     */
    public function open(): void
    {
        if ($this->isOpen()) {
            return;
        }

        $this->dial();

        if (! $this->config->persistent || ! $this->isDirty()) {
            return;
        }

        $this->close();
        $this->dial();

        if ($this->isDirty()) {
            $this->close();

            throw new ConnectionException(
                $this->config->uri().' returned unread bytes on a freshly opened connection'
            );
        }
    }

    /**
     * Dial the socket once. Split out of open() so the persistent path can do
     * it twice without recursing.
     */
    protected function dial(): void
    {
        $flags = STREAM_CLIENT_CONNECT | ($this->config->persistent ? STREAM_CLIENT_PERSISTENT : 0);

        $context = stream_context_create([
            // Nagle off: these are small, latency-sensitive request/response frames.
            'socket' => ['tcp_nodelay' => true],
            'ssl' => $this->config->sslOptions,
        ]);

        $errorNumber = 0;
        $errorMessage = '';

        $stream = @stream_socket_client(
            $this->config->uri(),
            $errorNumber,
            $errorMessage,
            $this->config->connectTimeout,
            $flags,
            $context
        );

        if ($stream === false) {
            throw new ConnectionException(sprintf(
                'unable to connect to %s: %s (%d)',
                $this->config->uri(),
                $errorMessage !== '' ? $errorMessage : 'unknown error',
                $errorNumber
            ));
        }

        $seconds = (int) $this->config->timeout;
        stream_set_timeout($stream, $seconds, (int) round(($this->config->timeout - $seconds) * 1_000_000));

        $this->stream = $stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * Is this connection unsafe to reuse?
     *
     * A pooled socket has nothing to say until we ask it something, so anything
     * readable means one of two things: the peer closed it (EOF), or a previous
     * exchange was abandoned mid-flight and left a response behind. The second
     * is the dangerous one - reusing that socket would pair every later request
     * with the previous request's answer, which reads as silent data
     * corruption rather than an error. It matters most for `persistent`
     * sockets, which outlive the PHP request that could have been killed.
     *
     * Costs one non-blocking select, next to nothing against a network round
     * trip, and it also catches an idle-closed peer before we waste a write on
     * it.
     */
    public function isDirty(): bool
    {
        if (! $this->isOpen()) {
            return true;
        }

        $read = [$this->stream];
        $write = null;
        $except = null;

        return @stream_select($read, $write, $except, 0, 0) !== 0;
    }

    /**
     * Send raw bytes - one or many concatenated frames.
     *
     * @throws StaleConnectionException when $staleOk and a *reused* socket fails
     *                                  before the server can have seen anything
     */
    public function write(string $bytes, bool $staleOk = false): void
    {
        $this->open();

        $sent = 0;
        $total = strlen($bytes);

        while ($sent < $total) {
            // Only copy on a partial write, which is the rare case - the whole
            // frame usually goes out in one call, and a large value should not
            // pay for a substr() of itself.
            $written = @fwrite($this->stream, $sent === 0 ? $bytes : substr($bytes, $sent));

            if ($written === false || $written === 0) {
                if ($staleOk) {
                    throw new StaleConnectionException('write failed on a pooled connection');
                }

                throw new ConnectionException('failed writing to '.$this->config->uri());
            }

            $sent += $written;
        }
    }

    /**
     * Read exactly one response body off the wire.
     *
     * $staleOk only covers the very first byte: once the server has started
     * answering it has necessarily executed the request, so a failure from
     * there on is a real error and never a retry candidate.
     */
    public function readResponse(bool $staleOk = false): Response
    {
        $header = $this->readExactly(4, $staleOk);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        $bodyLength = $unpacked[1];

        if ($bodyLength < 5 || $bodyLength > Wire::MAX_FRAME) {
            throw new ProtocolException('invalid response frame length '.$bodyLength);
        }

        $body = $this->readExactly($bodyLength);

        /** @var array{1: int} $payloadHeader */
        $payloadHeader = unpack('N', substr($body, 1, 4));
        $payloadLength = $payloadHeader[1];

        if (5 + $payloadLength !== $bodyLength) {
            throw new ProtocolException('response payload length does not match the frame');
        }

        return new Response(ord($body[0]), substr($body, 5, $payloadLength));
    }

    protected function readExactly(int $length, bool $staleOk = false): string
    {
        if (! $this->isOpen()) {
            throw new ConnectionException('the connection is not open');
        }

        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($this->stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);

                if (! empty($meta['timed_out'])) {
                    throw new ConnectionException(sprintf(
                        'timed out after %.3fs waiting for %s',
                        $this->config->timeout,
                        $this->config->uri()
                    ));
                }

                if ($buffer === '' && $staleOk) {
                    throw new StaleConnectionException('the pooled connection was closed while idle');
                }

                throw new ConnectionException('connection closed by the server mid-response');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
