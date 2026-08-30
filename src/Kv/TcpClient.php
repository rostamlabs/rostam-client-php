<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv;

use Rostam\Contracts\KvClient;
use Rostam\Exceptions\ConnectionException;
use Rostam\Exceptions\ServerException;
use Rostam\Exceptions\StaleConnectionException;
use Rostam\Exceptions\UnsupportedOperationException;
use Rostam\Kv\Protocol\Connection;
use Rostam\Kv\Protocol\ConnectionConfig;
use Rostam\Kv\Protocol\ConnectionPool;
use Rostam\Kv\Protocol\Response;
use Rostam\Kv\Protocol\Status;
use Rostam\Kv\Protocol\Wire;
use Rostam\TimeUnit;
use Throwable;

/**
 * The key-value client, speaking Rostam's native binary TCP protocol.
 *
 * Batch methods pipeline: every frame goes out in one write and the answers are
 * read back in request order (the server answers a connection strictly FIFO),
 * so `many()` of 50 keys costs one round trip rather than fifty.
 */
class TcpClient implements KvClient
{
    protected ConnectionPool $pool;

    public function __construct(protected readonly ConnectionConfig $config, ?ConnectionPool $pool = null)
    {
        $this->pool = $pool ?? new ConnectionPool($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(ConnectionConfig::fromArray($config));
    }

    public function get(string $key): ?string
    {
        $response = $this->call(new Command(Wire::OP_GET, Wire::keyArgs($key), idempotent: true));

        return $response->isMiss() ? null : $response->payload;
    }

    public function getMany(array $keys): array
    {
        $keys = array_values($keys);

        if ($keys === []) {
            return [];
        }

        // Not the server's `mget`: that op is routed to a single shard by its
        // first key, so on a cluster it would answer "missing" for every key
        // another shard owns. Pipelined gets are routed per key and cost the
        // same one round trip.
        $responses = $this->pipeline(array_map(
            static fn (string $key) => new Command(Wire::OP_GET, Wire::keyArgs($key), idempotent: true),
            $keys
        ));

        $values = [];

        foreach ($keys as $index => $key) {
            $response = $responses[$index];
            $values[$key] = $response->isMiss() ? null : $response->payload;
        }

        return $values;
    }

    public function put(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->call(new Command(Wire::OP_PUT, Wire::putArgs($key, $value, $unit->toMilliseconds($ttl))));
    }

    public function putMany(array $entries, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $entries = array_values($entries);

        if ($entries === []) {
            return;
        }

        $this->pipeline(array_map(
            static fn (array $entry) => new Command(
                Wire::OP_PUT,
                Wire::putArgs($entry[0], $entry[1], $unit->toMilliseconds($entry[2] ?? 0))
            ),
            $entries
        ));
    }

    public function setNx(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $response = $this->call(new Command(Wire::OP_SET_NX, Wire::putArgs($key, $value, $unit->toMilliseconds($ttl))));

        return Wire::decodeFlag($response->payload);
    }

    public function cas(string $key, string $value, ?string $expected, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $response = $this->call(new Command(
            Wire::OP_CAS,
            Wire::casArgs($key, $value, $expected, $unit->toMilliseconds($ttl))
        ));

        return Wire::decodeFlag($response->payload);
    }

    public function cad(string $key, string $expected): bool
    {
        $response = $this->call(new Command(Wire::OP_CAD, Wire::compareArgs($key, $expected)));

        return Wire::decodeFlag($response->payload);
    }

    public function caex(string $key, string $expected, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $response = $this->call(new Command(
            Wire::OP_CAEX,
            Wire::compareExpireArgs($key, $expected, $unit->toMilliseconds($ttl))
        ));

        return Wire::decodeFlag($response->payload);
    }

    public function getdel(string $key): ?string
    {
        $response = $this->call(new Command(Wire::OP_GETDEL, Wire::keyArgs($key)));

        return $response->isMiss() ? null : Wire::decodeFoundValue($response->payload);
    }

    public function getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): ?string
    {
        $response = $this->call(new Command(Wire::OP_GETSET, Wire::putArgs($key, $value, $unit->toMilliseconds($ttl))));

        return $response->isMiss() ? null : Wire::decodeFoundValue($response->payload);
    }

    public function exists(string $key): bool
    {
        $response = $this->call(new Command(Wire::OP_EXISTS, Wire::keyArgs($key), idempotent: true));

        return ! $response->isMiss() && Wire::decodeFlag($response->payload);
    }

    public function del(string $key): bool
    {
        $response = $this->call(new Command(Wire::OP_DEL, Wire::keyArgs($key)));

        return ! $response->isMiss() && Wire::decodeFlag($response->payload);
    }

    public function delMany(array $keys): array
    {
        $keys = array_values($keys);

        if ($keys === []) {
            return [];
        }

        $responses = $this->pipeline(array_map(
            static fn (string $key) => new Command(Wire::OP_DEL, Wire::keyArgs($key)),
            $keys
        ));

        $existed = [];

        foreach ($keys as $index => $key) {
            $response = $responses[$index];
            $existed[$key] = ! $response->isMiss() && Wire::decodeFlag($response->payload);
        }

        return $existed;
    }

    public function increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $response = $this->call(new Command(
            Wire::OP_INCR_EX,
            Wire::incrExArgs($key, $delta, $unit->toMilliseconds($ttl))
        ));

        return Wire::decodeCounter($response->payload);
    }

    public function expire(string $key, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $response = $this->call(new Command(Wire::OP_EXPIRE, Wire::expireArgs($key, $unit->toMilliseconds($ttl))));

        return ! $response->isMiss();
    }

    public function persist(string $key): bool
    {
        $response = $this->call(new Command(Wire::OP_PERSIST, Wire::keyArgs($key)));

        return ! $response->isMiss() && Wire::decodeFlag($response->payload);
    }

    public function ttl(string $key, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $response = $this->call(new Command(Wire::OP_TTL, Wire::keyArgs($key), idempotent: true));

        return $unit->fromMilliseconds($response->isMiss() ? -2 : Wire::decodeCounter($response->payload));
    }

    // ---------------------------------------------------------------------
    // Coming from Redis
    //
    // These are aliases, not new behaviour: each forwards to the method above
    // that carries the documentation. They exist because the names below are
    // muscle memory for anyone who has used predis, and a client you have to
    // look up is a client you get wrong.
    //
    // Only names whose MEANING matches are aliased. There is deliberately no
    // `mget` (Redis fans a key list out across the cluster; Rostam's mget is
    // routed to one shard by its first key, so `getMany` here is a client-side
    // fan-out and naming it `mget` would promise the wrong thing) and no
    // `flushdb` (Rostam has no such op - the cache driver bumps a generation
    // counter instead).
    // ---------------------------------------------------------------------

    /**
     * Redis's name for {@see put()}. Seconds unless told otherwise.
     */
    public function set(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->put($key, $value, $ttl, $unit);
    }

    /**
     * Redis's SETEX: set with an expiry, TTL first.
     */
    public function setex(string $key, int $seconds, string $value): void
    {
        $this->put($key, $value, $seconds);
    }

    /**
     * Redis's PSETEX: SETEX in milliseconds.
     */
    public function psetex(string $key, int $milliseconds, string $value): void
    {
        $this->put($key, $value, $milliseconds, TimeUnit::Milliseconds);
    }

    // No setnx() alias: PHP method names are case-insensitive, so setNx() above
    // already answers to it.

    /**
     * Redis's name for {@see increment()}.
     */
    public function incrby(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int
    {
        return $this->increment($key, $delta, $ttl, $unit);
    }

    /**
     * Redis's INCR: add one.
     */
    public function incr(string $key): int
    {
        return $this->increment($key, 1);
    }

    /**
     * Redis's DECRBY, which Rostam expresses as a negative increment.
     */
    public function decrby(string $key, int $delta = 1): int
    {
        return $this->increment($key, -$delta);
    }

    /**
     * Redis's DECR: subtract one.
     */
    public function decr(string $key): int
    {
        return $this->increment($key, -1);
    }

    /**
     * Redis's PEXPIRE: {@see expire()} in milliseconds.
     *
     * The pair exists for the same reason Redis has it - so the unit is visible
     * in the call rather than carried by a convention the reader has to recall.
     */
    public function pexpire(string $key, int $milliseconds): bool
    {
        return $this->expire($key, $milliseconds, TimeUnit::Milliseconds);
    }

    /**
     * Redis's PTTL: {@see ttl()} in milliseconds. Same sentinels: -2 absent,
     * -1 present with no expiry.
     */
    public function pttl(string $key): int
    {
        return $this->ttl($key, TimeUnit::Milliseconds);
    }

    public function ping(): bool
    {
        $this->call(new Command(Wire::OP_PING, '', idempotent: true));

        return true;
    }

    public function disconnect(): void
    {
        $this->pool->close();
    }

    public function config(): ConnectionConfig
    {
        return $this->config;
    }

    /**
     * Send one command and return its response, raising on any non-OK status
     * other than "not found" - a miss is data, not a failure.
     */
    public function call(Command $command): Response
    {
        return $this->pipeline([$command])[0];
    }

    /**
     * Send every command in one write and read the answers back in order.
     *
     * @param  list<Command>  $commands
     * @return list<Response>
     */
    public function pipeline(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $responses = $this->dispatch($commands);

        foreach ($responses as $index => $response) {
            if ($response->isOk() || $response->isMiss()) {
                continue;
            }

            $op = $commands[$index]->op;

            // An op the server has never heard of means the binary predates the
            // conditional writes this package is built on. Say so, rather than
            // letting it surface as a generic server error the store swallows.
            if ($response->status === Status::ERROR
                && str_contains($response->payload, Wire::UNKNOWN_OP_MARKER)) {
                throw new UnsupportedOperationException($op, $response->payload);
            }

            throw new ServerException($response->status, $response->payload, $op);
        }

        return $responses;
    }

    /**
     * @param  list<Command>  $commands
     * @return list<Response>
     */
    protected function dispatch(array $commands): array
    {
        $frames = '';

        foreach ($commands as $command) {
            $frames .= Wire::frame($command->op, $command->args, $this->config->token);
        }

        // A whole pipeline is only retryable when every op in it is idempotent:
        // a re-send would otherwise repeat a write the server may already have
        // applied.
        $mayRetry = $this->config->retryOnStaleConnection
            && array_reduce($commands, static fn (bool $carry, Command $c) => $carry && $c->idempotent, true);

        $forceFresh = false;

        while (true) {
            [$connection, $reused] = $forceFresh
                ? [$this->pool->fresh(), false]
                : $this->pool->acquire();

            $forceFresh = false;
            $staleOk = $reused && $mayRetry;

            try {
                $responses = $this->exchange($connection, $frames, count($commands), $staleOk);
            } catch (StaleConnectionException $exception) {
                $this->pool->discard($connection);

                if ($staleOk) {
                    // Exactly one retry, and on a brand-new socket so the free
                    // list cannot hand back another idle-and-also-dead one.
                    $mayRetry = false;
                    $forceFresh = true;

                    continue;
                }

                throw new ConnectionException($exception->getMessage(), 0, $exception);
            } catch (Throwable $exception) {
                $this->pool->discard($connection);

                throw $exception;
            }

            // A well-formed answer means the socket is healthy even if the op
            // failed at the application level, so it goes back in the pool.
            $this->pool->release($connection);

            return $responses;
        }
    }

    /**
     * @return list<Response>
     */
    protected function exchange(Connection $connection, string $frames, int $expected, bool $staleOk): array
    {
        $connection->write($frames, $staleOk);

        $responses = [];

        for ($i = 0; $i < $expected; $i++) {
            // Only the very first read can still be a stale-idle-socket: once
            // any byte has arrived the server has seen (and run) the request.
            $responses[] = $connection->readResponse($staleOk && $i === 0);
        }

        return $responses;
    }
}
