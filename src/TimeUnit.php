<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam;

/**
 * The unit a TTL is expressed in at the call site.
 *
 * Rostam's wire protocol speaks milliseconds everywhere, and this client used
 * to as well. That is faithful to the server and wrong for the caller: every
 * other key-value client a PHP developer has used - Redis, Memcached, Laravel's
 * own cache - takes seconds, so `expire($key, 60)` reads as a minute and used
 * to mean a sixteenth of one. A wrong unit does not fail; it silently expires
 * data a thousand times too early, which is the worst way for a mistake to
 * behave.
 *
 * So seconds are the default and milliseconds are something you ask for by
 * name:
 *
 *     $client->expire('session', 60);                        // a minute
 *     $client->expire('lease', 250, TimeUnit::Milliseconds); // a quarter second
 *
 * Nothing is lost: milliseconds remain reachable for the sub-second work the
 * engine is fast enough to make worth doing, and `pexpire()`/`pttl()` are there
 * for the muscle memory of anyone arriving from Redis.
 */
enum TimeUnit
{
    case Seconds;
    case Milliseconds;

    /**
     * Convert a TTL expressed in this unit into the milliseconds the wire wants.
     *
     * Zero is passed through untouched because it is not a duration: every op
     * that takes a TTL reads 0 as "no expiry", and multiplying that by a
     * thousand would still be zero but would invite a reader to wonder.
     */
    public function toMilliseconds(int $ttl): int
    {
        if ($ttl === 0) {
            return 0;
        }

        return $this === self::Seconds ? $ttl * 1000 : $ttl;
    }

    /**
     * Convert milliseconds from the wire into this unit.
     *
     * Negative values are the protocol's sentinels - -2 for an absent key, -1
     * for one with no expiry - and are returned as they are rather than being
     * divided into nonsense.
     *
     * A remainder rounds UP, so a key with 1 ms left reports 1 second rather
     * than 0. Reporting 0 would read as "expired" to the caller of a `ttl()`
     * that is really answering "still alive, briefly".
     */
    public function fromMilliseconds(int $milliseconds): int
    {
        if ($milliseconds < 0 || $this === self::Milliseconds) {
            return $milliseconds;
        }

        return (int) ceil($milliseconds / 1000);
    }
}
