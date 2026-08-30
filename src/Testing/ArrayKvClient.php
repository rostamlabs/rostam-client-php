<?php

declare(strict_types=1);

namespace Rostam\Testing;

use Closure;
use Rostam\Contracts\KvClient;
use Rostam\Exceptions\ServerException;
use Rostam\Kv\Protocol\Status;
use Rostam\TimeUnit;

/**
 * An in-memory KvClient with the server's exact semantics, for tests that are
 * about the store or the lock rather than the wire.
 *
 * The behaviours it reproduces on purpose: `increment` rejects a value that is
 * not eight bytes, its TTL applies only when it creates the key, and an expired
 * key is absent everywhere - which is what lets `setNx` re-acquire.
 */
final class ArrayKvClient implements KvClient
{
    /** @var array<string, array{value: string, expires: float|null}> */
    private array $store = [];

    /** @var list<string> */
    public array $ops = [];

    /** Fires just before a write lands - lets a test wedge a rival op into a window. */
    public ?Closure $beforeWrite = null;

    public function get(string $key): ?string
    {
        $this->ops[] = 'get';

        return $this->live($key)['value'] ?? null;
    }

    public function getMany(array $keys): array
    {
        $this->ops[] = 'getMany';

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->live($key)['value'] ?? null;
        }

        return $values;
    }

    public function put(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->ops[] = 'put';
        $this->announce($key);

        $this->write($key, $value, $unit->toMilliseconds($ttl));
    }

    public function putMany(array $entries, TimeUnit $unit = TimeUnit::Seconds): void
    {
        $this->ops[] = 'putMany';

        foreach ($entries as [$key, $value, $ttl]) {
            $this->write($key, $value, $unit->toMilliseconds($ttl));
        }
    }

    public function setNx(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->ops[] = 'setNx';
        $this->announce($key);

        if ($this->live($key) !== null) {
            return false;
        }

        $this->write($key, $value, $unit->toMilliseconds($ttl));

        return true;
    }

    public function cas(string $key, string $value, ?string $expected, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->ops[] = 'cas';

        $entry = $this->live($key);

        $matches = $expected === null
            ? $entry === null
            : ($entry !== null && $entry['value'] === $expected);

        if (! $matches) {
            return false;
        }

        $this->write($key, $value, $unit->toMilliseconds($ttl));

        return true;
    }

    public function cad(string $key, string $expected): bool
    {
        $this->ops[] = 'cad';

        $entry = $this->live($key);

        if ($entry === null || $entry['value'] !== $expected) {
            return false;
        }

        unset($this->store[$key]);

        return true;
    }

    public function caex(string $key, string $expected, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->ops[] = 'caex';

        $entry = $this->live($key);

        if ($entry === null || $entry['value'] !== $expected) {
            return false;
        }

        $this->store[$key]['expires'] = $this->deadline($unit->toMilliseconds($ttl));

        return true;
    }

    public function getdel(string $key): ?string
    {
        $this->ops[] = 'getdel';

        $entry = $this->live($key);
        unset($this->store[$key]);

        return $entry['value'] ?? null;
    }

    public function getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): ?string
    {
        $this->ops[] = 'getset';

        $previous = $this->live($key);
        $this->write($key, $value, $unit->toMilliseconds($ttl));

        return $previous['value'] ?? null;
    }

    public function exists(string $key): bool
    {
        $this->ops[] = 'exists';

        return $this->live($key) !== null;
    }

    public function del(string $key): bool
    {
        $this->ops[] = 'del';

        $existed = $this->live($key) !== null;
        unset($this->store[$key]);

        return $existed;
    }

    public function delMany(array $keys): array
    {
        $this->ops[] = 'delMany';

        $existed = [];

        foreach ($keys as $key) {
            $existed[$key] = $this->live($key) !== null;
            unset($this->store[$key]);
        }

        return $existed;
    }

    public function increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $this->ops[] = 'increment';

        $entry = $this->live($key);

        if ($entry !== null && strlen($entry['value']) !== 8) {
            throw new ServerException(Status::ERROR, 'ops: incr_ex value is not 8 bytes', 'incr_ex');
        }

        $next = ($entry === null ? 0 : unpack('J', $entry['value'])[1]) + $delta;

        // Like the server: the TTL is stamped on create only, and an existing
        // counter keeps the deadline it already had.
        $this->store[$key] = [
            'value' => pack('J', $next),
            'expires' => $entry === null ? $this->deadline($unit->toMilliseconds($ttl)) : $entry['expires'],
        ];

        return $next;
    }

    public function expire(string $key, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool
    {
        $this->ops[] = 'expire';

        if ($this->live($key) === null) {
            return false;
        }

        $this->store[$key]['expires'] = $this->deadline($unit->toMilliseconds($ttl));

        return true;
    }

    public function persist(string $key): bool
    {
        $this->ops[] = 'persist';

        $entry = $this->live($key);

        if ($entry === null || $entry['expires'] === null) {
            return false;
        }

        $this->store[$key]['expires'] = null;

        return true;
    }

    public function ttl(string $key, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $this->ops[] = 'ttl';

        $entry = $this->live($key);

        if ($entry === null) {
            return -2;
        }

        return $entry['expires'] === null
            ? -1
            : $unit->fromMilliseconds((int) max(0, ($entry['expires'] - microtime(true)) * 1000));
    }

    public function ping(): bool
    {
        $this->ops[] = 'ping';

        return true;
    }

    public function disconnect(): void
    {
        $this->ops[] = 'disconnect';
    }

    /**
     * @return array<string, array{value: string, expires: float|null}>
     */
    public function all(): array
    {
        return $this->store;
    }

    public function expiresAt(string $key): ?float
    {
        return $this->store[$key]['expires'] ?? null;
    }

    public function ageOut(string $key): void
    {
        if (isset($this->store[$key])) {
            $this->store[$key]['expires'] = microtime(true) - 1;
        }
    }

    private function write(string $key, string $value, int $ttlMilliseconds): void
    {
        $this->store[$key] = ['value' => $value, 'expires' => $this->deadline($ttlMilliseconds)];
    }

    private function deadline(int $ttlMilliseconds): ?float
    {
        return $ttlMilliseconds > 0 ? microtime(true) + $ttlMilliseconds / 1000 : null;
    }

    private function announce(string $key): void
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($key, $this);
        }
    }

    /**
     * @return array{value: string, expires: float|null}|null
     */
    private function live(string $key): ?array
    {
        if (! isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];

        if ($entry['expires'] !== null && $entry['expires'] <= microtime(true)) {
            unset($this->store[$key]);

            return null;
        }

        return $entry;
    }
}
