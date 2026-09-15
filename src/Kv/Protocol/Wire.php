<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv\Protocol;

use Rostam\Exceptions\ProtocolException;

/**
 * Encoders and decoders for Rostam's native binary TCP protocol.
 *
 * Everything is big-endian:
 *
 *     frame     [len u32][body]
 *     body v1   [opNameLen u8][opName][argsLen u32][args]
 *     body v2   [0x02][tokenLen u8][token][opNameLen u8][opName][argsLen u32][args]
 *     response  [bodyLen u32][status u8][payloadLen u32][payload]
 *
 * v2 is used when an auth token is configured, v1 otherwise - mirroring the Go
 * and Python clients. The key-value ops this package speaks, with the release
 * each first appears in (v0.5.0 unless noted):
 *
 *     get      [keyLen u16][key]                                      -> value (or NOT_FOUND)
 *     put      [keyLen u16][key][valLen u32][val][ttlMs u64]
 *     del      [keyLen u16][key]                                      -> one byte, 0 or 1
 *     expire   [keyLen u16][key][ttlMs u64]
 *     persist  [keyLen u16][key]                                      -> one byte
 *     exists   [keyLen u16][key]                                      -> one byte
 *     ttl      [keyLen u16][key]                                      -> i64 ms (-2 absent, -1 no expiry)
 *     incr_ex  [keyLen u16][key][delta i64][ttlMs u64]                -> new value, i64
 *     set_nx   [keyLen u16][key][valLen u32][val][ttlMs u64]          -> one byte, 1 = stored
 *     getset   [keyLen u16][key][valLen u32][val][ttlMs u64]          -> [found u8](+[len u32][old])
 *     getdel   [keyLen u16][key]                                      -> [found u8](+[len u32][val])
 *     cas      [keyLen u16][key][valLen u32][val][has u8][expLen u32][expected][ttlMs u64] -> one byte
 *     cad      [keyLen u16][key][expLen u32][expected]                -> one byte
 *     caex     [keyLen u16][key][expLen u32][expected][ttlMs u64]     -> one byte
 *     put_batch [count u32]{[keyLen u16][key][valLen u32][val][ttlMs u64]}* -> applied u32
 *     flush    (no args)                                              -> empty   (v0.6.0)
 *     __repl_metrics__ (no args)                                      -> JSON
 *     __kv_metrics__   (no args)                                      -> Prometheus text (v0.7.0-beta3)
 */
final class Wire
{
    /**
     * The largest body - everything after the four-byte length prefix - the
     * server accepts or sends: `server.MaxFrameSize`, 16 MiB, unchanged from
     * v0.5.0 through v0.7.0-beta6. A request over it is not refused with an
     * answer; the server drops the connection. Before v0.3.0 this said 64 MiB,
     * which let a request the server would never read leave this client.
     */
    public const MAX_FRAME = 16 * 1024 * 1024;

    public const PROTOCOL_V2 = 0x02;

    public const OP_GET = 'get';

    public const OP_PUT = 'put';

    public const OP_DEL = 'del';

    public const OP_EXPIRE = 'expire';

    public const OP_PERSIST = 'persist';

    public const OP_EXISTS = 'exists';

    public const OP_TTL = 'ttl';

    public const OP_INCR_EX = 'incr_ex';

    public const OP_SET_NX = 'set_nx';

    public const OP_GETSET = 'getset';

    public const OP_GETDEL = 'getdel';

    public const OP_CAS = 'cas';

    public const OP_CAD = 'cad';

    public const OP_CAEX = 'caex';

    public const OP_PING = '__ping__';

    /**
     * Wipe the whole keyspace. Rostam v0.6.0 and newer; takes no args.
     *
     * Passing key args does not scope it - measured against v0.6.0, a
     * `flush` carrying the key `app:` still removed `session:b`.
     */
    public const OP_FLUSH = 'flush';

    /**
     * Many puts as one op: `{count u32}` then that many single-put layouts.
     * Present since at least v0.5.0. The whole batch is ROUTED BY ITS FIRST KEY,
     * which is harmless on a single node and loses keys on a cluster - see
     * {@see Topology}.
     */
    public const OP_PUT_BATCH = 'put_batch';

    /**
     * `wire.MaxPutBatchSize`. The server does not refuse a larger batch - its
     * decoder never checks, and a 4097-entry batch applied in full on
     * v0.7.0-beta6 - but its own clients split at this size, because it bounds
     * how long one batch holds the shard write lock. This client splits at it
     * for the same reason.
     */
    public const MAX_PUT_BATCH_ENTRIES = 4096;

    /** This node's key-value counters as Prometheus text. v0.7.0-beta3 and newer. */
    public const OP_KV_METRICS = '__kv_metrics__';

    /**
     * Per hosted shard replication state, as JSON. An empty shard list on a single
     * node - and on a cluster member that hosts no shards, which is why it can
     * prove replication but never its absence. Present since at least v0.5.0.
     */
    public const OP_REPL_METRICS = '__repl_metrics__';

    private const MAX_KEY_LENGTH = 0xFFFF;

    /**
     * Wrap an op and its args into a complete, sendable frame.
     */
    public static function frame(string $op, string $args, string $token = ''): string
    {
        if ($op === '' || strlen($op) > 0xFF) {
            throw new ProtocolException('op name must be 1-255 bytes, got '.strlen($op));
        }

        $body = chr(strlen($op)).$op.pack('N', strlen($args)).$args;

        if ($token !== '') {
            if (strlen($token) > 0xFF) {
                throw new ProtocolException('auth token is longer than 255 bytes');
            }

            $body = chr(self::PROTOCOL_V2).chr(strlen($token)).$token.$body;
        }

        // The same bound the server reads with: the length prefix, which is the
        // body alone. Refused here, the caller gets a reason; sent, the server
        // would close the socket and the caller would get a dropped connection.
        if (strlen($body) > self::MAX_FRAME) {
            throw new ProtocolException(
                'request body of '.strlen($body).' bytes exceeds the server limit of '.self::MAX_FRAME
            );
        }

        return pack('N', strlen($body)).$body;
    }

    /**
     * [keyLen u16][key] - shared by get, del, exists, persist, ttl and getdel.
     */
    public static function keyArgs(string $key): string
    {
        self::assertKey($key);

        return pack('n', strlen($key)).$key;
    }

    /**
     * [keyLen u16][key][valLen u32][val][ttlMs u64] - shared by put, set_nx and getset.
     */
    public static function putArgs(string $key, string $value, int $ttlMilliseconds = 0): string
    {
        self::assertKey($key);
        self::assertTtl($ttlMilliseconds);

        return pack('n', strlen($key)).$key
            .pack('N', strlen($value)).$value
            .pack('J', $ttlMilliseconds);
    }

    /**
     * [keyLen u16][key][ttlMs u64]
     */
    public static function expireArgs(string $key, int $ttlMilliseconds): string
    {
        self::assertKey($key);
        self::assertTtl($ttlMilliseconds);

        return pack('n', strlen($key)).$key.pack('J', $ttlMilliseconds);
    }

    /**
     * [keyLen u16][key][delta i64][ttlMs u64]
     *
     * The TTL applies only when the op creates the key; an existing counter
     * keeps the deadline it already had.
     */
    public static function incrExArgs(string $key, int $delta, int $ttlMilliseconds = 0): string
    {
        self::assertKey($key);
        self::assertTtl($ttlMilliseconds);

        return pack('n', strlen($key)).$key.pack('J', $delta).pack('J', $ttlMilliseconds);
    }

    /**
     * [keyLen u16][key][valLen u32][val][hasExpected u8][expLen u32][expected][ttlMs u64]
     *
     * A null $expected means "store only if the key is absent".
     */
    public static function casArgs(string $key, string $value, ?string $expected, int $ttlMilliseconds = 0): string
    {
        self::assertKey($key);
        self::assertTtl($ttlMilliseconds);

        return pack('n', strlen($key)).$key
            .pack('N', strlen($value)).$value
            .chr($expected === null ? 0 : 1)
            .pack('N', strlen($expected ?? '')).($expected ?? '')
            .pack('J', $ttlMilliseconds);
    }

    /**
     * [keyLen u16][key][expLen u32][expected] - compare-and-delete.
     */
    public static function compareArgs(string $key, string $expected): string
    {
        self::assertKey($key);

        return pack('n', strlen($key)).$key.pack('N', strlen($expected)).$expected;
    }

    /**
     * [keyLen u16][key][expLen u32][expected][ttlMs u64] - compare-and-expire.
     */
    public static function compareExpireArgs(string $key, string $expected, int $ttlMilliseconds): string
    {
        self::assertTtl($ttlMilliseconds);

        return self::compareArgs($key, $expected).pack('J', $ttlMilliseconds);
    }

    /**
     * `{count u32}` followed by each entry in the exact single-put layout.
     *
     * @param  list<array{0: string, 1: string, 2: int}>  $entries  key, value, TTL in milliseconds
     */
    public static function putBatchArgs(array $entries): string
    {
        if (count($entries) > self::MAX_PUT_BATCH_ENTRIES) {
            throw new ProtocolException(sprintf(
                'a put_batch is split at %d entries here, got %d - split it first',
                self::MAX_PUT_BATCH_ENTRIES,
                count($entries)
            ));
        }

        $args = pack('N', count($entries));

        foreach ($entries as [$key, $value, $ttlMilliseconds]) {
            $args .= self::putArgs($key, $value, $ttlMilliseconds);
        }

        return $args;
    }

    /**
     * The applied-entry count a put_batch answers with.
     */
    public static function decodePutBatchResult(string $payload): int
    {
        if (strlen($payload) !== 4) {
            throw new ProtocolException('expected a 4-byte put_batch count, got '.strlen($payload).' bytes');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $payload);

        return $unpacked[1];
    }

    /**
     * Read the i64 that incr_ex and ttl answer with.
     */
    public static function decodeCounter(string $payload): int
    {
        if (strlen($payload) !== 8) {
            throw new ProtocolException('expected an 8-byte counter, got '.strlen($payload).' bytes');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $payload);

        return $unpacked[1];
    }

    /**
     * Read the single 0/1 byte del, exists, persist and the compare ops answer with.
     */
    public static function decodeFlag(string $payload): bool
    {
        return $payload !== '' && $payload[0] !== "\x00";
    }

    /**
     * Read a `[found u8](+[valLen u32][val])` result - getdel and getset.
     */
    public static function decodeFoundValue(string $payload): ?string
    {
        if ($payload === '' || $payload[0] === "\x00") {
            return null;
        }

        if (strlen($payload) < 5) {
            throw new ProtocolException('truncated found-value payload');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($payload, 1, 4));
        $declared = $unpacked[1];

        // The declared length is checked against what actually arrived, for the
        // same reason readResponse() checks the outer frame: substr() would
        // otherwise return whatever it could and a SHORT value would come back
        // looking complete. On getdel that is a value silently truncated on its
        // way out of the store, with the original already deleted - nothing
        // downstream could ever tell, and there is no second copy to compare
        // against. An answer this client cannot vouch for is not an answer.
        if (strlen($payload) !== 5 + $declared) {
            throw new ProtocolException(sprintf(
                'found-value payload declares %d bytes but carries %d',
                $declared,
                strlen($payload) - 5
            ));
        }

        return substr($payload, 5, $declared);
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new ProtocolException(
                'key length '.strlen($key).' exceeds the protocol maximum of '.self::MAX_KEY_LENGTH
            );
        }
    }

    private static function assertTtl(int $ttlMilliseconds): void
    {
        if ($ttlMilliseconds < 0) {
            throw new ProtocolException('ttl must not be negative, got '.$ttlMilliseconds);
        }
    }
}
