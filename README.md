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

Some calls need more:

| Call | Needs | Why |
| --- | --- | --- |
| everything else | v0.5.0 | the conditional writes (`set_nx`, `cas`, `cad`, `caex`) and `incr_ex` |
| `flush()` | v0.6.0 | where the op arrived |
| `kvMetrics()` | **v0.7.0-beta3** | a beta; see [Metrics](#metrics) |

Point this at an older server and you get a `ServerException` carrying the
server's generic error, because that is genuinely all there is. For every op this
client sends, Rostam answers a byte-identical `internal error` to an op it does
not know, to arguments it could not decode, and to an ordinary application-level
miss such as `incr_ex` on a key that is not a counter — measured on v0.4.2,
v0.6.0 and v0.7.0-beta6 alike. It has no version or capability op to ask instead:
`__kv_metrics__` reports what the cache has done, not what the server supports.
This package will not guess which of the three it was. (Some newer ops name
their own argument errors — `kv_query` answers `wire: kv_query args truncated` —
which changes nothing for the ops above.)

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
| `mset` | `putMany` | per-key TTLs; `put_batch` underneath only on a declared single node — see [below](#putmany-and-topology) |
| `flushdb` | `flush` | v0.6.0+, and **global** — read the warning below before using it |
| `INFO` | `kvMetrics()` | the key-value counters only; v0.7.0-beta3+ |
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

## putMany and topology

Rostam has had a `put_batch` op since at least v0.5.0, and it is about an order
of magnitude faster than the same writes as pipelined single puts — measured on
v0.7.0-beta6, 4000 entries took **48 ms pipelined and 3.6 ms batched**. It is not
used by default, because of how it is routed.

A batch goes to the shard that owns **its first key**. On a single node every
key reaches the same store and each lands where a read will look for it — a batch
of a thousand unrelated keys applied and read back in full. On a cluster, every
key another shard owns is stored on the first key's shard instead, where no read
will find it. This client has no shard map to split a batch by: the server's
topology answer is Go's `gob` encoding.

So it waits to be told:

```php
$rostam = TcpClient::fromArray([
    'host'     => '127.0.0.1',
    'port'     => 7000,
    'topology' => 'single-node',   // default 'unknown': per-key puts, safe anywhere
]);
```

That is a **declaration, not a detection**, and the wire can only disprove it.
Before the first batch the client asks for `__repl_metrics__`: a non-empty shard
list is replication, and it throws `TopologyMismatchException` without writing
anything. An empty list proves nothing — a cluster member hosting no shards
answers the same — so it is taken as agreement, not confirmation. **Any error from
that question is thrown and not remembered**: the op exists in every release this
client supports, so an error never means "this server cannot say", and nothing is
written on the strength of a question nobody answered.

Batches are split at 4096 entries — the size the server's own clients split at;
the server does not refuse more — and at the 16 MiB body limit, and all of them
still go out in one round trip.

**Neither path is a transaction, and a failure does not stop at the entry that
failed.** The server skips an entry it cannot store and applies the rest: on
v0.7.0-beta6, a batch of `[a, <a value too large to store>, b]` answered an error
and both `a` and `b` read back. A pipeline of single puts behaves the same. After
an error, any entry may have landed; writing the same call again is safe, since a
put is last-writer-wins.

## How large a value can be

Two limits, and the second is usually the one you meet.

- **The frame: 16 MiB.** `server.MaxFrameSize` bounds every request body, from
  v0.5.0 through v0.7.0-beta6. The server does not answer a body over it — it
  drops the connection — so this client refuses to send one and throws a
  `ProtocolException` with the reason. (Before v0.3.0 it assumed 64 MiB.)
- **The cache page.** A value has to fit in one page of the server's cache, and the
  page size follows from `max_memory` divided across the shards. On a default
  single-node server that is far below the frame limit: the largest value stored
  was **about 1 MiB** (1,048,544 bytes on v0.6.0, 1,048,540 on v0.7.0-beta6, on the
  same machine). A value over it answers the generic `internal error`. Fewer
  shards or a larger `max_memory` raise it.

## Metrics

```php
$metrics = $rostam->kvMetrics();          // rostam v0.7.0-beta3+

$metrics->evictionsLive();   // live records lost to capacity — not to their TTL
$metrics->rejects();         // writes refused because the shard was full
$metrics->get('rostam_kv_entries');
$metrics->all();             // every unlabelled sample, name => value
```

Every number is **node-wide and cumulative since the server started**: it covers
every key on that node, whoever wrote it.

`evictionsLive()` is the one worth watching. A single-node `rostam-server` always
evicts at capacity — only replicated shards refuse writes instead, and there is
no flag or config to change that — and it does so silently: every `put` returns
success. Measured on v0.7.0-beta6 with a 256 MiB budget, 400 one-megabyte writes
all succeeded, 235 read back, and `evictionsLive()` said 165.

The answer is parsed strictly. A line that is not a Prometheus sample, a counter
that is NaN, infinite, negative or fractional, a repeated series — each is a
`ProtocolException`, never a value quietly skipped or cast. A metric the server
did not report is `null`, never `0`: a counter added in a later release is simply
absent from an older server's answer, and "none happened" is the wrong reading.

`__kv_metrics__` arrived in a **beta**. Its names and output may still change
before v0.7.0 is released.

`kvMetrics()` lives on its own interface, `Rostam\Contracts\ReportsKvMetrics`,
not on `KvClient`: an existing implementation of `KvClient` keeps loading.

## Errors

| Exception | Means |
| --- | --- |
| `ConnectionException` | could not dial, timed out, or the peer went away |
| `ProtocolException` | a frame came back malformed — the stream is out of step, do not treat this as an application-level result. Also thrown *before sending* for a request that cannot be encoded — a body over 16 MiB, a key or token too long for its length field, a negative TTL; then nothing was written, though a `single-node` `putMany` may already have asked the server its topology |
| `ServerException` | the server refused the op; carries `status`, `op` and `detail` — the server's text, decoded from its length prefix (empty for a refused token) |
| `TopologyMismatchException` | a connection declared `single-node` met a server reporting replicated shards; nothing was written |
| `StaleConnectionException` | internal: a pooled socket was dead; the client retries idempotent ops once and you never see this |

## Retries, and what is never retried

A pooled socket can be closed by the peer while idle, so a failure that happens
*before the server can have answered* is retried once on a fresh connection — but
only when **every** op in the exchange is idempotent. Reads are (`get`, `getMany`,
`exists`, `ttl`, `ping`, `kvMetrics`); nothing that writes is — `put_batch`
included.

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
N ops, standing in for a server too old for this client, and demanding a token
chosen per test — a real server fixes its auth at launch.

Two more skip for a different reason. `flush` has no unit smaller than the whole
keyspace, so testing it against a server that holds anything else would delete
data no test ever wrote, and nothing on the wire says whether the server is a
scratch one. Declare it:

```bash
ROSTAM_TEST_SERVER=127.0.0.1:7411 ROSTAM_TEST_SERVER_IS_DISPOSABLE=1 vendor/bin/phpunit
```

`Rostam\Testing` ships the fake server and an in-memory `ArrayKvClient`,
so anything built on this package can test without a socket.

## License

Apache-2.0, the same licence as [Rostam](https://github.com/rostamlabs/rostam)
itself — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
