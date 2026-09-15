<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Testing;

use Closure;
use Rostam\Contracts\KvClient;
use Rostam\Contracts\ReportsKvMetrics;
use Rostam\Exceptions\ServerException;
use Rostam\Kv\Metrics\KvMetrics;
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
final class ArrayKvClient implements KvClient, ReportsKvMetrics
{
    /** @var array<string, array{value: string, expires: float|null}> */
    private array $store = [];

    /** Live records a test has said this "server" already lost to capacity. */
    private int $liveEvictions = 0;

    /** @var list<string> */
    public array $ops = [];

    /**
     * Called with ($key, $this) before every op that may change a key, whether
     * or not it then does: put, setNx, cas, cad, caex, getdel, getset, del,
     * increment, expire and persist, and putMany and delMany once per key.
     * flush names no key and does not call it. Lets a test wedge a rival op
     * into the window just before a write.
     *
     * Before v0.3.0 only put and setNx called it, so a test that hooked a
     * compare-and-swap never ran its rival and passed without proving anything.
     */
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
            $this->announce($key);
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
        $this->announce($key);

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
        $this->announce($key);

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
        $this->announce($key);

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
        $this->announce($key);

        $entry = $this->live($key);
        unset($this->store[$key]);

        return $entry['value'] ?? null;
    }

    public function getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): ?string
    {
        $this->ops[] = 'getset';
        $this->announce($key);

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
        $this->announce($key);

        $existed = $this->live($key) !== null;
        unset($this->store[$key]);

        return $existed;
    }

    public function delMany(array $keys): array
    {
        $this->ops[] = 'delMany';

        $existed = [];

        foreach ($keys as $key) {
            $this->announce($key);
            $existed[$key] = $this->live($key) !== null;
            unset($this->store[$key]);
        }

        return $existed;
    }

    public function increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int
    {
        $this->ops[] = 'increment';
        $this->announce($key);

        $entry = $this->live($key);

        if ($entry !== null && strlen($entry['value']) !== 8) {
            // The words rostam itself uses. It reports an unknown op, args it
            // could not decode and this - an ordinary miss - with one
            // indistinguishable message, so a fake that explains itself lets a
            // test rely on a distinction production never offers.
            throw new ServerException(Status::ERROR, 'internal error', 'incr_ex');
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
        $this->announce($key);

        if ($this->live($key) === null) {
            return false;
        }

        $this->store[$key]['expires'] = $this->deadline($unit->toMilliseconds($ttl));

        return true;
    }

    public function persist(string $key): bool
    {
        $this->ops[] = 'persist';
        $this->announce($key);

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

    public function flush(): void
    {
        $this->ops[] = 'flush';
        $this->store = [];
    }

    /**
     * Only what an in-memory map can report truthfully: how many keys it holds,
     * that it neither evicts nor refuses on its own, and any live evictions a
     * test has claimed. A real server reports some thirty-five counters; a fake
     * inventing the rest would let a test lean on numbers no server produced.
     * Built as Prometheus text and parsed, so the parser runs here too.
     */
    public function kvMetrics(): KvMetrics
    {
        $this->ops[] = 'kvMetrics';

        $samples = [
            'rostam_kv_evictions_total' => ['counter', $this->liveEvictions],
            'rostam_kv_evictions_live_total' => ['counter', $this->liveEvictions],
            'rostam_kv_rejects_total' => ['counter', 0],
            'rostam_kv_entries' => ['gauge', count($this->store)],
        ];

        $text = '';

        foreach ($samples as $name => [$type, $value]) {
            $text .= "# TYPE {$name} {$type}\n{$name} {$value}\n";
        }

        return KvMetrics::fromPrometheusText($text);
    }

    /**
     * Make this "server" report live records lost to capacity - the one thing
     * a queue refuses to run on, and which no in-memory map would ever do.
     */
    public function simulateLiveEvictions(int $count): void
    {
        $this->liveEvictions += max(0, $count);
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
