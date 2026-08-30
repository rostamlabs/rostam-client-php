<?php

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
 * and Python clients. The key-value ops this package speaks (Rostam v0.5.0):
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
 */
final class Wire
{
    /** The server rejects any frame whose length prefix exceeds this. */
    public const MAX_FRAME = 64 * 1024 * 1024;

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

    /** What the server answers when it does not know an op name. */
    public const UNKNOWN_OP_MARKER = 'op not registered';

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

        if (4 + strlen($body) > self::MAX_FRAME) {
            throw new ProtocolException(
                'request frame of '.(4 + strlen($body)).' bytes exceeds the server limit of '.self::MAX_FRAME
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
