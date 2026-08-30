<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ConnectionException;
use Rostam\Kv\Protocol\Connection;
use Rostam\Kv\Protocol\ConnectionConfig;
use Rostam\Kv\TcpClient;
use Rostam\Testing\FakeServer;

/**
 * A persistent socket outlives the request that opened it, which is the whole
 * point of it and also its one danger: a worker killed mid-exchange hands the
 * next request a socket with an unread response still in the buffer. Reading
 * from it pairs this request with the previous one's answer - a `get` that
 * returns another key's value, with no error raised anywhere.
 *
 * The pool screens its free list for exactly this, but the free list lives in
 * PHP memory and is EMPTY at the start of every request, which is when the
 * OS-level socket is least trustworthy. So the check has to happen where the
 * socket is opened, not where it is pooled.
 */
class PersistentConnectionTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = FakeServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();

        parent::tearDown();
    }

    private function config(bool $persistent): ConnectionConfig
    {
        return ConnectionConfig::fromArray([
            'host' => '127.0.0.1',
            'port' => $this->server->port,
            'token' => '',
            'persistent' => $persistent,
        ]);
    }

    /**
     * The mechanism this guards against is real at the stream level: a second
     * stream_socket_client() with STREAM_CLIENT_PERSISTENT hands back a NEW
     * resource id over the SAME underlying connection, unread bytes and all.
     * (Comparing resource ids says "not reused" and is simply the wrong test.)
     *
     * Reproducing that end to end through FakeServer did not prove reliable, so
     * what is pinned here is the guard itself: a persistent connection whose
     * socket arrives dirty is thrown away and re-dialled rather than used.
     */
    public function test_a_dirty_persistent_socket_is_discarded_and_redialled(): void
    {
        $connection = new class($this->config(true)) extends Connection
        {
            public int $dials = 0;

            public int $dirtyChecks = 0;

            protected function dial(): void
            {
                $this->dials++;

                parent::dial();
            }

            public function isDirty(): bool
            {
                // Dirty exactly once: the socket PHP handed back carried the
                // previous request's answer; the replacement is clean.
                return ++$this->dirtyChecks === 1;
            }
        };

        $connection->open();

        $this->assertSame(2, $connection->dials, 'a dirty persistent socket must be re-dialled, not used');
        $this->assertTrue($connection->isOpen());

        $connection->close();
    }

    public function test_a_clean_persistent_socket_is_dialled_once(): void
    {
        $connection = new class($this->config(true)) extends Connection
        {
            public int $dials = 0;

            protected function dial(): void
            {
                $this->dials++;

                parent::dial();
            }

            public function isDirty(): bool
            {
                return false;
            }
        };

        $connection->open();

        $this->assertSame(1, $connection->dials, 'a clean socket must not pay for a second dial');

        $connection->close();
    }

    public function test_a_non_persistent_socket_pays_nothing_for_the_check(): void
    {
        // The guard is gated on the persistent flag, so an ordinary connection
        // never spends a select() on a socket that cannot have been reused.
        $client = new TcpClient($this->config(false));

        $client->put('k', 'v');

        $this->assertSame('v', $client->get('k'));

        $client->disconnect();
    }

    public function test_it_refuses_a_connection_that_keeps_talking_out_of_turn(): void
    {
        $connection = new class($this->config(true)) extends Connection
        {
            public int $dials = 0;

            protected function dial(): void
            {
                $this->dials++;

                parent::dial();
            }

            public function isDirty(): bool
            {
                // Always dirty: stands in for a socket that cannot be cleaned by
                // reopening, which the protocol has no safe answer for.
                return true;
            }
        };

        $this->expectException(ConnectionException::class);

        try {
            $connection->open();
        } finally {
            $this->assertSame(2, $connection->dials, 'it must try exactly one fresh dial before giving up');
        }
    }
}
