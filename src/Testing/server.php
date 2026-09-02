<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

/**
 * A throwaway stand-in for `rostam-server -tcp`, used by the test suite.
 *
 * It implements the key-value ops this package speaks, on the real wire format,
 * reproducing the server behaviours the driver depends on:
 *
 *   - `incr_ex` refuses any stored value that is not exactly eight bytes;
 *   - `incr_ex` stamps its TTL only when it creates the key, and otherwise
 *     leaves the stored deadline alone;
 *   - an expired key reads as absent everywhere, so `set_nx` re-acquires;
 *   - anything it cannot carry out - an unknown op, args it could not
 *     decode, incr_ex on a non-counter key - comes back as the same bare
 *     "internal error", because that is all rostam distinguishes;
 *   - with one exception, a layer lower: a frame whose header points past the
 *     end of what arrived is named, `server: frame truncated`. Both were
 *     measured against a real v0.6.0, and neither closes the connection.
 *
 * Run as: php server.php [--token=...] [--drop-after=N] [--lifetime=SECONDS]
 *                        [--legacy]
 *
 * `--legacy` refuses every op this package uses that a pre-v0.5.0 server would
 * not have had, `flush` (v0.6.0) included - it stands in for "a server too old
 * for this client", not for one exact release. It exists to prove that such a
 * server is indistinguishable from any other error, which is why there is no
 * version guard to test any more. It prints the port it bound to on stdout,
 * then serves until killed.
 */
$options = getopt('', ['token::', 'drop-after::', 'lifetime::', 'legacy']);
$token = (string) ($options['token'] ?? '');
$dropAfter = (int) ($options['drop-after'] ?? 0);
$lifetime = (float) ($options['lifetime'] ?? 60);
$legacy = array_key_exists('legacy', $options);

const MODERN_OPS = ['set_nx', 'cas', 'cad', 'caex', 'exists', 'getdel', 'getset', 'persist', 'ttl', 'incr_ex', 'flush'];

// What rostam answers for anything it cannot carry out. Measured on v0.4.2
// and v0.6.0: an unknown op, undecodable args and incr_ex on a non-counter
// all come back byte-identical. A fake that says something more helpful
// lets a test pass on a distinction the real server never makes.
const GENERIC_ERROR = 'internal error';

// The one exception, and it lives a layer lower: a frame whose own header
// points past the end of what arrived is named. Measured on v0.6.0 -
// body "put" answers `server: frame truncated`, not `internal error`.
const TRUNCATED_FRAME = 'server: frame truncated';

$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

if ($server === false) {
    fwrite(STDERR, "unable to listen: {$errorMessage} ({$errorNumber})\n");
    exit(1);
}

stream_set_blocking($server, false);

$address = stream_socket_get_name($server, false);
echo substr($address, (int) strrpos($address, ':') + 1), PHP_EOL;
flush();

/** @var array<int, resource> $clients */
$clients = [];
/** @var array<int, string> $buffers */
$buffers = [];
/** @var array<int, int> $served */
$served = [];
/** @var array<string, array{value: string, expires: float|null}> $store */
$store = [];

$deadline = microtime(true) + $lifetime;

while (microtime(true) < $deadline) {
    $read = array_merge([$server], array_values($clients));
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 0, 200_000) === false) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);

            if ($client !== false) {
                stream_set_blocking($client, false);
                $id = (int) $client;
                $clients[$id] = $client;
                $buffers[$id] = '';
                $served[$id] = 0;
            }

            continue;
        }

        $id = (int) $stream;
        $chunk = @fread($stream, 65536);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            fclose($stream);
            unset($clients[$id], $buffers[$id], $served[$id]);

            continue;
        }

        $buffers[$id] .= $chunk;
        $closing = false;

        while (strlen($buffers[$id]) >= 4) {
            $length = unpack('N', substr($buffers[$id], 0, 4))[1];

            if (strlen($buffers[$id]) < 4 + $length) {
                break;
            }

            $body = substr($buffers[$id], 4, $length);
            $buffers[$id] = substr($buffers[$id], 4 + $length);

            fwrite($stream, respond($body, $token, $legacy, $store));

            $served[$id]++;

            if ($dropAfter > 0 && $served[$id] >= $dropAfter) {
                $closing = true;

                break;
            }
        }

        if ($closing) {
            fclose($stream);
            unset($clients[$id], $buffers[$id], $served[$id]);
        }
    }
}

foreach ($clients as $client) {
    fclose($client);
}

fclose($server);

/**
 * @param  array<string, array{value: string, expires: float|null}>  $store
 */
function respond(string $body, string $token, bool $legacy, array &$store): string
{
    // Outside the try below on purpose no longer: a header pointing past the
    // end of the body used to raise here and take the process with it, which is
    // one thing the real server never does.
    try {
        [$op, $args, $sent] = decodeBody($body);
    } catch (Throwable) {
        return frame(3, TRUNCATED_FRAME);
    }

    if ($token !== '' && $sent !== $token) {
        return frame(4, 'invalid token');
    }

    if ($legacy && in_array($op, MODERN_OPS, true)) {
        return frame(3, GENERIC_ERROR);
    }

    // Args this fake cannot decode reach `unpack` short and raise. rostam does
    // not get to crash on a malformed request and neither does its stand-in:
    // it answers the same thing it answers for everything else it cannot carry
    // out, which is what the docblock above promises.
    try {
        return dispatch($op, $args, $store);
    } catch (Throwable) {
        return frame(3, GENERIC_ERROR);
    }
}

/**
 * Read a declared run of bytes, or refuse.
 *
 * `substr` shortens silently, which is the wrong shape for a wire format: a
 * `get` whose key claims five bytes and carries two would have been served as
 * an ordinary miss on a two-byte key. The real server answers `internal error`,
 * so a length that cannot be honoured has to stop here.
 */
function take(string $args, int $offset, int $length): string
{
    if ($length < 0 || $offset + $length > strlen($args)) {
        throw new UnexpectedValueException('args declare more bytes than they carry');
    }

    return substr($args, $offset, $length);
}

/**
 * @return array{int, int} the value at $offset, and the offset after it
 */
function takeNumber(string $args, int $offset, string $format, int $width): array
{
    return [unpack($format, take($args, $offset, $width))[1], $offset + $width];
}

/**
 * @param  array<string, array{value: string, expires: float|null}>  $store
 */
function dispatch(string $op, string $args, array &$store): string
{
    switch ($op) {
        case '__ping__':
            return frame(0, '');

        case 'flush':
            // Global, exactly as measured: no argument narrows it.
            $store = [];

            return frame(0, '');

        case 'get':
            $entry = live($store, decodeKey($args));

            return $entry === null ? frame(1, '') : frame(0, $entry['value']);

        case 'exists':
            return frame(0, live($store, decodeKey($args)) !== null ? "\x01" : "\x00");

        case 'put':
            [$key, $value, $ttl] = decodeValueArgs($args);
            $store[$key] = ['value' => $value, 'expires' => deadlineFor($ttl)];

            return frame(0, '');

        case 'set_nx':
            [$key, $value, $ttl] = decodeValueArgs($args);

            if (live($store, $key) !== null) {
                return frame(0, "\x00");
            }

            $store[$key] = ['value' => $value, 'expires' => deadlineFor($ttl)];

            return frame(0, "\x01");

        case 'getset':
            [$key, $value, $ttl] = decodeValueArgs($args);
            $previous = live($store, $key);
            $store[$key] = ['value' => $value, 'expires' => deadlineFor($ttl)];

            return frame(0, foundValue($previous === null ? null : $previous['value']));

        case 'cas':
            [$key, $value, $expected, $ttl] = decodeCasArgs($args);
            $entry = live($store, $key);

            $matches = $expected === null
                ? $entry === null
                : ($entry !== null && $entry['value'] === $expected);

            if (! $matches) {
                return frame(0, "\x00");
            }

            $store[$key] = ['value' => $value, 'expires' => deadlineFor($ttl)];

            return frame(0, "\x01");

        case 'cad':
            [$key, $expected] = decodeCompareArgs($args);
            $entry = live($store, $key);

            if ($entry === null || $entry['value'] !== $expected) {
                return frame(0, "\x00");
            }

            unset($store[$key]);

            return frame(0, "\x01");

        case 'caex':
            [$key, $expected, $ttl] = decodeCompareArgs($args, withTtl: true);
            $entry = live($store, $key);

            if ($entry === null || $entry['value'] !== $expected) {
                return frame(0, "\x00");
            }

            $store[$key]['expires'] = deadlineFor($ttl);

            return frame(0, "\x01");

        case 'del':
            $key = decodeKey($args);
            $existed = live($store, $key) !== null;
            unset($store[$key]);

            return frame(0, $existed ? "\x01" : "\x00");

        case 'getdel':
            $key = decodeKey($args);
            $entry = live($store, $key);
            unset($store[$key]);

            return frame(0, foundValue($entry === null ? null : $entry['value']));

        case 'expire':
            [$keyLength, $offset] = takeNumber($args, 0, 'n', 2);
            $key = take($args, $offset, $keyLength);
            [$ttl] = takeNumber($args, $offset + $keyLength, 'J', 8);

            if (live($store, $key) === null) {
                return frame(1, '');
            }

            $store[$key]['expires'] = deadlineFor($ttl);

            return frame(0, '');

        case 'persist':
            $key = decodeKey($args);
            $entry = live($store, $key);

            if ($entry === null || $entry['expires'] === null) {
                return frame(0, "\x00");
            }

            $store[$key]['expires'] = null;

            return frame(0, "\x01");

        case 'ttl':
            $entry = live($store, decodeKey($args));

            if ($entry === null) {
                return frame(0, pack('J', -2));
            }

            if ($entry['expires'] === null) {
                return frame(0, pack('J', -1));
            }

            return frame(0, pack('J', (int) max(0, ($entry['expires'] - microtime(true)) * 1000)));

        case 'incr_ex':
            [$keyLength, $offset] = takeNumber($args, 0, 'n', 2);
            $key = take($args, $offset, $keyLength);
            [$delta, $offset] = takeNumber($args, $offset + $keyLength, 'J', 8);
            [$ttl] = takeNumber($args, $offset, 'J', 8);
            $entry = live($store, $key);

            if ($entry !== null && strlen($entry['value']) !== 8) {
                return frame(3, GENERIC_ERROR);
            }

            $next = ($entry === null ? 0 : unpack('J', $entry['value'])[1]) + $delta;

            // Created: stamp the TTL. Existing: keep the stored deadline, so
            // the window neither slides nor is lost.
            $store[$key] = [
                'value' => pack('J', $next),
                'expires' => $entry === null ? deadlineFor($ttl) : $entry['expires'],
            ];

            return frame(0, pack('J', $next));
    }

    return frame(3, GENERIC_ERROR);
}

function deadlineFor(int $ttlMilliseconds): ?float
{
    return $ttlMilliseconds > 0 ? microtime(true) + $ttlMilliseconds / 1000 : null;
}

function foundValue(?string $value): string
{
    return $value === null ? "\x00" : "\x01".pack('N', strlen($value)).$value;
}

/**
 * @return array{0: string, 1: string, 2: int}
 */
function decodeValueArgs(string $args): array
{
    [$keyLength, $offset] = takeNumber($args, 0, 'n', 2);
    $key = take($args, $offset, $keyLength);
    [$valueLength, $offset] = takeNumber($args, $offset + $keyLength, 'N', 4);
    $value = take($args, $offset, $valueLength);
    [$ttl] = takeNumber($args, $offset + $valueLength, 'J', 8);

    return [$key, $value, $ttl];
}

/**
 * @return array{0: string, 1: string, 2: string|null, 3: int}
 */
function decodeCasArgs(string $args): array
{
    $keyLength = unpack('n', substr($args, 0, 2))[1];
    $key = substr($args, 2, $keyLength);
    $offset = 2 + $keyLength;
    $valueLength = unpack('N', substr($args, $offset, 4))[1];
    $value = substr($args, $offset + 4, $valueLength);
    $offset += 4 + $valueLength;
    $has = ord($args[$offset]);
    $expectedLength = unpack('N', substr($args, $offset + 1, 4))[1];
    $expected = substr($args, $offset + 5, $expectedLength);
    $ttl = unpack('J', substr($args, $offset + 5 + $expectedLength, 8))[1];

    return [$key, $value, $has === 1 ? $expected : null, $ttl];
}

/**
 * @return array{0: string, 1: string, 2: int}
 */
function decodeCompareArgs(string $args, bool $withTtl = false): array
{
    [$keyLength, $offset] = takeNumber($args, 0, 'n', 2);
    $key = take($args, $offset, $keyLength);
    [$expectedLength, $offset] = takeNumber($args, $offset + $keyLength, 'N', 4);
    $expected = take($args, $offset, $expectedLength);
    $ttl = $withTtl ? takeNumber($args, $offset + $expectedLength, 'J', 8)[0] : 0;

    return [$key, $expected, $ttl];
}

/**
 * @return array{0: string, 1: string, 2: string}
 */
function decodeBody(string $body): array
{
    $sentToken = '';
    $offset = 0;

    if ($body !== '' && ord($body[0]) === 0x02) {
        $tokenLength = ord(take($body, 1, 1));
        $sentToken = take($body, 2, $tokenLength);
        $offset = 2 + $tokenLength;
    }

    $opLength = ord(take($body, $offset, 1));
    $op = take($body, $offset + 1, $opLength);
    [$argsLength, $argsOffset] = takeNumber($body, $offset + 1 + $opLength, 'N', 4);

    return [$op, take($body, $argsOffset, $argsLength), $sentToken];
}

function decodeKey(string $args): string
{
    [$length, $offset] = takeNumber($args, 0, 'n', 2);

    return take($args, $offset, $length);
}

/**
 * @param  array<string, array{value: string, expires: float|null}>  $store
 * @return array{value: string, expires: float|null}|null
 */
function live(array &$store, string $key): ?array
{
    if (! isset($store[$key])) {
        return null;
    }

    $entry = $store[$key];

    if ($entry['expires'] !== null && $entry['expires'] <= microtime(true)) {
        unset($store[$key]);

        return null;
    }

    return $entry;
}

function frame(int $status, string $payload): string
{
    return pack('N', 5 + strlen($payload)).chr($status).pack('N', strlen($payload)).$payload;
}
