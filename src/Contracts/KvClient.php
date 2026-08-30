<?php

declare(strict_types=1);

namespace Rostam\Contracts;

use Rostam\TimeUnit;

/**
 * The key-value surface of a Rostam server, in raw bytes.
 *
 * Nothing here knows about PHP values or cache prefixes - a cache store built
 * on top owns all of that. Keeping the transport at this level is what makes
 * such a store testable without a server.
 *
 * TTLs are in SECONDS unless a call says otherwise with {@see TimeUnit}. The
 * wire itself speaks milliseconds; that conversion happens here, at the edge,
 * so no caller has to remember which side of it they are on.
 *
 * Requires Rostam v0.5.0 or newer: the conditional writes and `incr_ex` are
 * what let this package offer an atomic `add()`, a Redis-grade lock, and an
 * increment that keeps its window.
 */
interface KvClient
{
    /**
     * Raw value bytes, or null when the key is absent or expired.
     */
    public function get(string $key): ?string;

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null> every requested key, in the order asked
     */
    public function getMany(array $keys): array;

    /**
     * @param  int  $ttl  0 means no expiry
     */
    public function put(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): void;

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $entries  [key, value, ttl] triples,
     *                                                              every ttl in $unit
     */
    public function putMany(array $entries, TimeUnit $unit = TimeUnit::Seconds): void;

    /**
     * Store only if the key is absent or expired - one atomic server-side op.
     *
     * @return bool whether this call is the one that stored the value
     */
    public function setNx(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool;

    /**
     * Store only if the key still holds $expected. A null $expected means
     * "only if absent", which is {@see self::setNx()} by another name.
     */
    public function cas(string $key, string $value, ?string $expected, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): bool;

    /**
     * Delete only if the key still holds $expected - a safe unlock.
     */
    public function cad(string $key, string $expected): bool;

    /**
     * Refresh a TTL only if the key still holds $expected - a safe lease renewal.
     */
    public function caex(string $key, string $expected, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool;

    /**
     * Read a key and delete it in one atomic op; null when it was absent.
     */
    public function getdel(string $key): ?string;

    /**
     * Replace a key's value and return the previous one; null when there was none.
     */
    public function getset(string $key, string $value, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): ?string;

    /**
     * Is the key present? Cheaper than get() when the value is not wanted.
     */
    public function exists(string $key): bool;

    /**
     * @return bool whether the key existed
     */
    public function del(string $key): bool;

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function delMany(array $keys): array;

    /**
     * Atomically add $delta to an 8-byte counter and return the new value.
     *
     * A missing key counts as 0. $ttl applies only when this call
     * creates the key - an existing counter keeps the deadline it already had,
     * so a fixed window neither slides nor is lost. Matches Redis's INCRBY,
     * plus the option to open the window in the same round trip.
     */
    public function increment(string $key, int $delta = 1, int $ttl = 0, TimeUnit $unit = TimeUnit::Seconds): int;

    /**
     * Refresh a key's TTL without rewriting its value.
     *
     * @return bool whether the key was there to expire
     */
    public function expire(string $key, int $ttl, TimeUnit $unit = TimeUnit::Seconds): bool;

    /**
     * Drop a key's expiry so it never ages out.
     *
     * @return bool whether an expiry was actually removed
     */
    public function persist(string $key): bool;

    /**
     * Remaining time to live, Redis-style: -2 absent, -1 present with no
     * expiry, otherwise the time left in $unit (seconds by default, rounded
     * up so a key with any life left never reports 0). {@see pttl()} on the
     * client is the millisecond shorthand.
     */
    public function ttl(string $key, TimeUnit $unit = TimeUnit::Seconds): int;

    /**
     * Round-trip a heartbeat.
     */
    public function ping(): bool;

    public function disconnect(): void;
}
