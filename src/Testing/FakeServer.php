<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Testing;

use RuntimeException;

/**
 * Boots {@see server.php} in a child PHP process and tells you which port it
 * landed on, so a test can drive the real client over a real socket.
 *
 * CONFORMANCE MODE. The fake is a reimplementation of the protocol by the same
 * hand that wrote the client, so a shared misreading of the wire would pass
 * every test here and fail in production - the blind spots are correlated. Set
 * ROSTAM_TEST_SERVER=host:port and every test that only needs a working server
 * runs against a REAL rostam-server instead, which is the only way to find that
 * class of divergence.
 *
 *     rostam-server -tcp 127.0.0.1:7411 -insecure
 *     ROSTAM_TEST_SERVER=127.0.0.1:7411 vendor/bin/phpunit
 *
 * Two things the fake can do that a real server cannot be asked to do on
 * demand - dropping a connection after N ops, and pretending to predate v0.5.0
 * - so a test wanting either must skip itself in conformance mode. {@see
 * isExternal()} answers that.
 */
final class FakeServer
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct($process, array $pipes, public readonly int $port)
    {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Whether this server may be destroyed by the test using it.
     *
     * A fake is a fresh process per test and always is. A real one named by
     * ROSTAM_TEST_SERVER is shared and remembers, so a test that wipes the
     * keyspace - `flush` has no smaller unit - would take whatever else lives
     * there with it. Nothing on the wire says whether that server is a scratch
     * one, so the operator declares it and destructive tests skip until they
     * do. The conformance CI job starts its own server and sets the flag.
     *
     * Only an affirmative value counts. Reading "any value at all" would have
     * let somebody who wrote `=0` to mean no lose their keyspace, and this flag
     * exists for exactly that person; anything unrecognised skips the tests,
     * which costs a little coverage and nothing else.
     */
    public static function isDisposable(): bool
    {
        return ! self::isExternal()
            || filter_var((string) getenv('ROSTAM_TEST_SERVER_IS_DISPOSABLE'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Is the suite pointed at a real server rather than this fake?
     */
    public static function isExternal(): bool
    {
        $target = getenv('ROSTAM_TEST_SERVER');

        return is_string($target) && $target !== '';
    }

    /**
     * host:port of the real server, or null when there is none.
     */
    public static function externalTarget(): ?string
    {
        $target = getenv('ROSTAM_TEST_SERVER');

        return is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * @param  int  $dropAfter  close each connection after serving this many ops (0 = never)
     * @param  bool  $legacy  refuse every op a pre-v0.5.0 server would not have
     *                        had, `flush` (v0.6.0) included
     */
    public static function start(string $token = '', int $dropAfter = 0, float $lifetime = 60, bool $legacy = false): self
    {
        if ($target = self::externalTarget()) {
            if ($dropAfter > 0 || $legacy || $token !== '') {
                throw new RuntimeException(
                    'this scenario needs the fake server: a real one cannot be asked to '
                    .match (true) {
                        $legacy => 'predate v0.5.0',
                        $dropAfter > 0 => 'drop connections after N ops',
                        default => 'demand a token chosen per test - its auth is fixed at launch',
                    }
                    .'. Guard the test with FakeServer::isExternal().'
                );
            }

            $port = (int) (parse_url('tcp://'.$target, PHP_URL_PORT) ?: 0);

            if ($port === 0) {
                throw new RuntimeException('ROSTAM_TEST_SERVER must look like host:port, got '.$target);
            }

            return new self(null, [], $port);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [
            PHP_BINARY,
            __DIR__.'/server.php',
            '--token='.$token,
            '--drop-after='.$dropAfter,
            '--lifetime='.$lifetime,
        ];

        if ($legacy) {
            $command[] = '--legacy';
        }

        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('unable to start the fake Rostam server');
        }

        $line = fgets($pipes[1]);

        if ($line === false || ! is_numeric(trim($line))) {
            $error = stream_get_contents($pipes[2]);
            proc_terminate($process);

            throw new RuntimeException('the fake Rostam server did not report a port: '.$error);
        }

        return new self($process, $pipes, (int) trim($line));
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => $this->port,
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ], $overrides);
    }

    public function stop(): void
    {
        // Nothing to tear down when the server is somebody else's.
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
