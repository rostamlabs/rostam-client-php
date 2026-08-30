<?php

declare(strict_types=1);

namespace Rostam\Kv\Protocol;

use Rostam\Exceptions\ConnectionException;

/**
 * A small pool of sockets, handed out one at a time.
 *
 * A connection that errored mid-call is discarded rather than returned, so a
 * broken socket never poisons the next request.
 */
class ConnectionPool
{
    /** @var list<Connection> */
    protected array $free = [];

    protected bool $closed = false;

    public function __construct(protected readonly ConnectionConfig $config) {}

    /**
     * Take a connection out of the pool.
     *
     * The second element says whether it came off the free list. Only a reused
     * connection can have been closed by the peer while idle, so only that case
     * is a candidate for the stale-connection retry.
     *
     * @return array{0: Connection, 1: bool}
     */
    public function acquire(): array
    {
        if ($this->closed) {
            throw new ConnectionException('the connection pool is closed');
        }

        // Anything readable on an idle pooled socket means it is closed or out
        // of step with its answers; either way it must not carry a request.
        while (($connection = array_pop($this->free)) !== null) {
            if (! $connection->isDirty()) {
                return [$connection, true];
            }

            $connection->close();
        }

        return [$this->fresh(), false];
    }

    /**
     * Open a brand-new connection, bypassing the (possibly also-stale) free list.
     */
    public function fresh(): Connection
    {
        return new Connection($this->config);
    }

    public function release(Connection $connection): void
    {
        if ($this->closed || count($this->free) >= $this->config->poolSize) {
            $connection->close();

            return;
        }

        $this->free[] = $connection;
    }

    public function discard(Connection $connection): void
    {
        $connection->close();
    }

    public function close(): void
    {
        $this->closed = true;

        foreach ($this->free as $connection) {
            $connection->close();
        }

        $this->free = [];
    }

    public function idleCount(): int
    {
        return count($this->free);
    }
}
