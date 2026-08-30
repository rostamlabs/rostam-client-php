<?php

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
 *   - an unknown op comes back as a plain error carrying "op not registered".
 *
 * Run as: php server.php [--token=...] [--drop-after=N] [--lifetime=SECONDS]
 *                        [--legacy]
 *
 * `--legacy` refuses every op added in Rostam v0.5.0, which is how the version
 * guard is tested. It prints the port it bound to on stdout, then serves until
 * killed.
 */
$options = getopt('', ['token::', 'drop-after::', 'lifetime::', 'legacy']);
$token = (string) ($options['token'] ?? '');
$dropAfter = (int) ($options['drop-after'] ?? 0);
$lifetime = (float) ($options['lifetime'] ?? 60);
$legacy = array_key_exists('legacy', $options);

const MODERN_OPS = ['set_nx', 'cas', 'cad', 'caex', 'exists', 'getdel', 'getset', 'persist', 'ttl', 'incr_ex'];

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
    [$op, $args, $sent] = decodeBody($body);

    if ($token !== '' && $sent !== $token) {
        return frame(4, 'invalid token');
    }

    if ($legacy && in_array($op, MODERN_OPS, true)) {
        return frame(3, 'shard: op not registered: '.$op);
    }

    switch ($op) {
        case '__ping__':
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
            $keyLength = unpack('n', substr($args, 0, 2))[1];
            $key = substr($args, 2, $keyLength);
            $ttl = unpack('J', substr($args, 2 + $keyLength, 8))[1];

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
            $keyLength = unpack('n', substr($args, 0, 2))[1];
            $key = substr($args, 2, $keyLength);
            $delta = unpack('J', substr($args, 2 + $keyLength, 8))[1];
            $ttl = unpack('J', substr($args, 10 + $keyLength, 8))[1];
            $entry = live($store, $key);

            if ($entry !== null && strlen($entry['value']) !== 8) {
                return frame(3, 'ops: incr_ex value is not 8 bytes');
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

    return frame(3, 'shard: op not registered: '.$op);
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
    $keyLength = unpack('n', substr($args, 0, 2))[1];
    $key = substr($args, 2, $keyLength);
    $offset = 2 + $keyLength;
    $valueLength = unpack('N', substr($args, $offset, 4))[1];
    $value = substr($args, $offset + 4, $valueLength);
    $ttl = unpack('J', substr($args, $offset + 4 + $valueLength, 8))[1];

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
    $keyLength = unpack('n', substr($args, 0, 2))[1];
    $key = substr($args, 2, $keyLength);
    $offset = 2 + $keyLength;
    $expectedLength = unpack('N', substr($args, $offset, 4))[1];
    $expected = substr($args, $offset + 4, $expectedLength);
    $ttl = $withTtl ? unpack('J', substr($args, $offset + 4 + $expectedLength, 8))[1] : 0;

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
        $tokenLength = ord($body[1]);
        $sentToken = substr($body, 2, $tokenLength);
        $offset = 2 + $tokenLength;
    }

    $opLength = ord($body[$offset]);
    $op = substr($body, $offset + 1, $opLength);
    $argsOffset = $offset + 1 + $opLength;
    $argsLength = unpack('N', substr($body, $argsOffset, 4))[1];

    return [$op, substr($body, $argsOffset + 4, $argsLength), $sentToken];
}

function decodeKey(string $args): string
{
    $length = unpack('n', substr($args, 0, 2))[1];

    return substr($args, 2, $length);
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
