# rostam-client-php

A PHP client for [Rostam](https://github.com/rostamlabs/rostam)'s key-value engine,
speaking its native binary TCP protocol: framing, connection pooling, pipelining,
TLS and token auth, with no extensions beyond core streams.

Rostam's KV half is **not on its REST API**. It lives only on the binary TCP
protocol, because it is built for sub-microsecond operations that an HTTP round
trip would defeat. This package is that protocol, and nothing else — no framework,
no container, no cache semantics. If you want a Laravel cache driver, that is
[`rostamlabs/rostam-cache-laravel`](https://github.com/rostamlabs/rostam-cache-laravel),
which is built on this.

## Requirements

- PHP 8.2+ on a 64-bit build
- **Rostam v0.5.0 or newer**, started with a `-tcp` listener
  (`flush()` alone needs **v0.6.0**)

v0.5.0 is where the conditional writes (`set_nx`, `cas`, `cad`, `caex`) and
`incr_ex` landed; `flush` arrived in v0.6.0.

Point this at an older server and you get a `ServerException` carrying the
server's generic error, because that is genuinely all there is. Rostam answers a
byte-identical `internal error` to an op it does not know, to arguments it could
not decode, and to an ordinary application-level miss such as `incr_ex` on a key
that is not a counter — measured on v0.4.2 and v0.6.0 alike — and it has no
version or capability op to ask instead. This package will not guess which of
the three it was.

```bash
ROSTAM_API_KEY=$(openssl rand -hex 32) rostam-server -tcp 127.0.0.1:7000 -data /var/lib/rostam
```

## Install

```bash
composer require rostamlabs/rostam-client-php
```

## Usage

```php
use Rostam\Kv\TcpClient;
use Rostam\TimeUnit;

$rostam = TcpClient::fromArray([
    'host'  => '127.0.0.1',
    'port'  => 7000,
    'token' => getenv('ROSTAM_API_KEY'),
]);

$rostam->put('session:abc', $blob, 300);          // seconds
$rostam->get('session:abc');
$rostam->del('session:abc');

$rostam->setNx('lock:deploy', $owner, 30);        // atomic: one caller wins
$rostam->cas('config', $new, expected: $old);     // compare-and-swap
$rostam->cad('lock:deploy', $owner);              // release only what you hold

$rostam->increment('hits');                       // atomic, server-side
$rostam->putMany([['a', '1', 60], ['b', '2', 60]]);
$rostam->getMany(['a', 'b', 'c']);                // one round trip
```

### Time is in seconds

Every TTL is seconds unless you say otherwise. The wire speaks milliseconds; the
conversion happens at the edge so no caller has to remember which side of it they
are on.

```php
$rostam->expire('session', 60);                        // a minute
$rostam->expire('lease', 250, TimeUnit::Milliseconds); // a quarter second

$rostam->ttl('session');   // seconds: -2 absent, -1 no expiry
$rostam->pttl('session');  // the same answer in milliseconds
```

This matters more than it looks. A wrong unit does not fail — it silently expires
data a thousand times too early, which is the worst way for a mistake to behave.

## Coming from predis

Method names follow **Rostam's own op names**, the way predis follows Redis's. Most
of them are the same word; where Redis's name is the familiar one and the meaning
matches exactly, it is available as an alias.

| Redis / predis | here | note |
| --- | --- | --- |
| `set` | `put` or `set` | Rostam's op is `put`; `set` is an alias |
| `setex` / `psetex` | `setex` / `psetex` | TTL-first argument order, as in Redis |
| `setnx` | `setNx` | PHP method names are case-insensitive, so `setnx()` reaches it |
| `get` `del` `exists` `persist` `getset` `getdel` | same | identical names and meaning |
| `incr` `incrby` `decr` `decrby` | same | `incr_ex` underneath; an existing counter keeps its window, as `INCRBY` does |
| `expire` / `pexpire` | same | seconds / milliseconds |
| `ttl` / `pttl` | same | seconds / milliseconds |
| `mget` | **`getMany`** | deliberately not called `mget` — see below |
| `flushdb` | `flush` | v0.6.0+, and **global** — read the warning below before using it |
| — | `cas` `cad` `caex` | compare-and-swap / -delete / -expire; no Redis equivalent |

**Why `getMany` and not `mget`.** Rostam's `mget` is routed to a single shard by
its first key, so on a cluster it answers "missing" for every key that lives
elsewhere. `getMany` is a client-side fan-out over a pipeline: one round trip, and
correct on any topology. Naming it `mget` would promise Redis's semantics and
deliver something else, which is the one thing an alias must never do.

**`flush()` is not `FLUSHDB`.** Redis's `FLUSHDB` clears one numbered database
and leaves the others. Rostam has no databases: `flush` wipes **the entire
keyspace on that server**, whoever wrote the keys. Measured against v0.6.0:

    put app:a, put session:b
    flush                       (sent carrying the key `app:`)
    app:a      -> not found
    session:b  -> not found     <- the argument did not scope anything

So on a shared server this destroys the other application's cache, the sessions,
and any queued jobs that had already been accepted. Vector collections are a
separate keyspace and survive; that was measured too. Use it when the server
belongs to one thing and you mean all of it, and reach for a generation counter
when you need to clear only your own keys — which is what the Laravel cache
driver does by default.

## Errors

| Exception | Means |
| --- | --- |
| `ConnectionException` | could not dial, timed out, or the peer went away |
| `ProtocolException` | a frame came back malformed — the stream is out of step, do not treat this as an application-level result |
| `ServerException` | the server refused the op; carries `status`, `op` and the payload |
| `StaleConnectionException` | internal: a pooled socket was dead; the client retries idempotent ops once and you never see this |

## Retries, and what is never retried

A pooled socket can be closed by the peer while idle, so a failure that happens
*before the server can have answered* is retried once on a fresh connection — but
only when **every** op in the exchange is idempotent. Reads are (`get`, `getMany`,
`exists`, `ttl`, `ping`); nothing that writes is.

That conservatism is deliberate. If `getdel` were retried after the server had
already executed it, the second attempt would return null and the value would be
gone with no copy anywhere. A missed answer is recoverable; a silently lost value
is not.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `host` / `port` | `127.0.0.1` / `7000` | the server's `-tcp` listener |
| `token` | `''` | matches `-api-key` / `ROSTAM_API_KEY`; sends protocol v2 frames when set |
| `connect_timeout` | `2.0` | seconds for the dial |
| `timeout` | `5.0` | seconds for each read and write |
| `pool_size` | `4` | how many idle sockets are **kept**; the client is synchronous, so this is retention, not a concurrency limit |
| `persistent` | `false` | PHP persistent sockets, kept by the worker across requests |
| `retry_on_stale_connection` | `true` | re-send an idempotent exchange once when a pooled socket turns out to be dead |
| `tls.enabled` | `false` | wrap the connection in TLS |
| `tls.ca` / `tls.cert` / `tls.key` | `null` | CA bundle and client certificate for mTLS |

## Testing

```bash
composer install
composer test
```

The suite runs against a fake server written in PHP, which is fast and can be told
to misbehave on demand. But a fake is a reimplementation of the protocol by the
same hand that wrote the client, so a shared misreading of the wire would pass
every test and still fail in production — the blind spots are correlated. Point it
at a real server to rule that out:

```bash
rostam-server -tcp 127.0.0.1:7411 -insecure
ROSTAM_TEST_SERVER=127.0.0.1:7411 vendor/bin/phpunit
```

Five scenarios need the fake and skip themselves there: dropping a connection after
N ops, pretending to predate v0.5.0, and demanding a token chosen per test — a real
server fixes its auth at launch.

`Rostam\Testing` ships the fake server and an in-memory `ArrayKvClient`,
so anything built on this package can test without a socket.

## License

Apache-2.0, the same licence as [Rostam](https://github.com/rostamlabs/rostam)
itself — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
