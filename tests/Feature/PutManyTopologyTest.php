<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\RostamException;
use Rostam\Exceptions\ServerException;
use Rostam\Exceptions\TopologyMismatchException;
use Rostam\Kv\Command;
use Rostam\Kv\Protocol\ConnectionConfig;
use Rostam\Kv\Protocol\Response;
use Rostam\Kv\Protocol\Status;
use Rostam\Kv\Protocol\Wire;
use Rostam\Kv\TcpClient;
use Rostam\Testing\FakeServer;
use Rostam\TimeUnit;

/**
 * Which op putMany actually sends, and when it refuses to send the fast one.
 *
 * `put_batch` routes a whole batch by its first key. Measured on a single
 * v0.7.0-beta6 node, a batch of a thousand unrelated keys applied all of them
 * and every one read back; on a cluster the same batch strands every key
 * another shard owns. So the op is only reached through a declaration, and
 * these tests assert both that it is reached and that it is not.
 *
 * Keys carry a per-test prefix and are deleted afterwards, so a real server
 * shared across runs never sees one test's batch as another's leftovers.
 */
class PutManyTopologyTest extends TestCase
{
    private ?FakeServer $server = null;

    private string $prefix;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'pmt:'.bin2hex(random_bytes(4)).':';
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->written !== []) {
            try {
                $this->plainClient()->delMany($this->written);
            } catch (\Throwable) {
                // A test that proved the server unreachable has nothing to clean.
            }
        }

        $this->server?->stop();
        $this->server = null;

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config = [], bool $replicated = false, string $token = ''): RecordingClient
    {
        if (FakeServer::isExternal() && ($replicated || $token !== '')) {
            $this->markTestSkipped('needs the fake server; a real one cannot be asked for this');
        }

        $this->server ??= FakeServer::start($token, replicated: $replicated);

        return new RecordingClient(ConnectionConfig::fromArray($this->server->connectionConfig($config + ['token' => $token])));
    }

    private function plainClient(): TcpClient
    {
        return TcpClient::fromArray($this->server->connectionConfig());
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function entries(int $count, int $ttl = 0): array
    {
        $entries = [];

        // Numbered across calls, not per call: two calls in one test must not
        // hand out the same key, or the second silently overwrites the first.
        for ($i = count($this->written), $end = $i + $count; $i < $end; $i++) {
            $key = $this->prefix.$i;
            $this->written[] = $key;
            $entries[] = [$key, 'value:'.$i, $ttl];
        }

        return $entries;
    }

    private function assertAllReadable(array $entries): void
    {
        $values = $this->plainClient()->getMany(array_column($entries, 0));

        foreach ($entries as [$key, $value]) {
            $this->assertSame($value, $values[$key], "{$key} did not read back");
        }
    }

    public function test_by_default_every_entry_is_its_own_put(): void
    {
        $client = $this->client();
        $entries = $this->entries(5);

        $client->putMany($entries);

        $this->assertSame(array_fill(0, 5, Wire::OP_PUT), $client->ops);
        $this->assertAllReadable($entries);
    }

    public function test_a_declared_single_node_is_checked_then_written_as_one_batch(): void
    {
        $client = $this->client(['topology' => 'single-node']);
        $entries = $this->entries(1000);

        $client->putMany($entries);

        $this->assertSame([Wire::OP_REPL_METRICS, Wire::OP_PUT_BATCH], $client->ops);
        $this->assertAllReadable($entries);
    }

    public function test_the_declaration_is_checked_once_per_client_not_per_call(): void
    {
        $client = $this->client(['topology' => 'single-node']);

        $client->putMany($this->entries(2));
        $client->putMany($this->entries(2));

        $this->assertSame(
            [Wire::OP_REPL_METRICS, Wire::OP_PUT_BATCH, Wire::OP_PUT_BATCH],
            $client->ops
        );
    }

    public function test_past_the_entry_split_it_sends_more_than_one_batch_in_one_round_trip(): void
    {
        $client = $this->client(['topology' => 'single-node']);
        $entries = $this->entries(Wire::MAX_PUT_BATCH_ENTRIES + 1);

        $client->putMany($entries);

        $this->assertSame([Wire::OP_REPL_METRICS, Wire::OP_PUT_BATCH, Wire::OP_PUT_BATCH], $client->ops);

        // One exchange for the topology check, one for both batches together.
        // Counting commands alone would not tell that from a call per batch.
        $this->assertSame(2, $client->roundTrips);
        $this->assertAllReadable($entries);
    }

    /**
     * The server drops the connection on a body over 16 MiB, and 1100 entries
     * of 16 KiB are already that. A split at a 64 MiB budget - which this
     * client used to assume - sent one such body and wrote nothing at all.
     * Run in both modes: the fake now closes the connection exactly as the
     * server does, so it cannot pass what the server would refuse.
     *
     * Many small values rather than a few large ones, deliberately. A value
     * must also fit one cache page, and on a default single-node server that
     * is far below the frame limit - about 1 MiB, measured on v0.6.0 and
     * v0.7.0-beta6 - so large values would test the page, not the frame.
     */
    public function test_a_call_larger_than_one_frame_is_split_and_written_in_full(): void
    {
        $client = $this->client(['topology' => 'single-node']);

        $entries = $this->entries(1100);
        foreach ($entries as $index => $entry) {
            $entries[$index][1] = str_pad((string) $index, 16 * 1024, '.');
        }

        $client->putMany($entries);

        $this->assertGreaterThanOrEqual(2, count(array_keys($client->ops, Wire::OP_PUT_BATCH, true)));
        $this->assertAllReadable($entries);
    }

    /**
     * `__repl_metrics__` exists in every release this client supports, so an
     * error from it is never "this server cannot say". It is thrown - nothing
     * is written on the strength of an unanswered question - and it is not
     * remembered, so the next call asks again.
     */
    public function test_a_topology_check_that_fails_is_thrown_and_asked_again(): void
    {
        $this->client();
        $client = new FailsTheFirstTopologyCheck(ConnectionConfig::fromArray(
            $this->server->connectionConfig(['topology' => 'single-node'])
        ));
        [$first, $second] = $this->entries(2);

        try {
            $client->putMany([$first]);
            $this->fail('a batch was written although the topology check failed');
        } catch (ServerException $exception) {
            $this->assertSame(Status::ERROR, $exception->status);
        }

        $this->assertNull($this->plainClient()->get($first[0]));

        $client->putMany([$second]);

        $this->assertSame([Wire::OP_REPL_METRICS, Wire::OP_REPL_METRICS, Wire::OP_PUT_BATCH], $client->ops);
        $this->assertSame($second[1], $this->plainClient()->get($second[0]));
    }

    /**
     * Each entry keeps its own TTL through the batch, in the unit it was given.
     */
    public function test_a_batch_keeps_each_entrys_ttl(): void
    {
        $client = $this->client(['topology' => 'single-node']);
        [$short, $forever] = [$this->entries(1, 30)[0], $this->entries(1, 0)[0]];

        $client->putMany([$short, $forever]);

        $plain = $this->plainClient();
        $this->assertEqualsWithDelta(30, $plain->ttl($short[0]), 2);
        $this->assertSame(-1, $plain->ttl($forever[0]));
    }

    public function test_milliseconds_survive_the_batch(): void
    {
        $client = $this->client(['topology' => 'single-node']);
        $entry = $this->entries(1, 45000)[0];

        $client->putMany([$entry], TimeUnit::Milliseconds);

        $this->assertEqualsWithDelta(45000, $this->plainClient()->ttl($entry[0], TimeUnit::Milliseconds), 2000);
    }

    /**
     * The one contradiction the wire can prove. Refused before anything is
     * written, so it costs a failed call and not a batch stored where no read
     * will look for it.
     */
    public function test_a_single_node_declaration_against_a_replicating_server_writes_nothing(): void
    {
        $client = $this->client(['topology' => 'single-node'], replicated: true);
        $entries = $this->entries(3);

        try {
            $client->putMany($entries);
            $this->fail('a batch was allowed onto a replicating server');
        } catch (TopologyMismatchException $exception) {
            $this->assertStringContainsString('1 replicated shard', $exception->getMessage());
        }

        $this->assertSame([Wire::OP_REPL_METRICS], $client->ops, 'something was written after the check failed');
        $this->assertSame([null, null, null], array_values($this->plainClient()->getMany(array_column($entries, 0))));
    }

    /**
     * A refusal to answer is not the same as being unable to. On a cluster with
     * scoped keys the check itself may be refused, and taking that as agreement
     * is exactly how a batch would reach the place it must not.
     */
    public function test_a_refused_check_is_not_taken_as_agreement(): void
    {
        if (FakeServer::isExternal()) {
            $this->markTestSkipped('a real server fixes its auth at launch; this needs a per-test token');
        }

        $this->server = FakeServer::start('s3cret');
        $client = new RecordingClient(ConnectionConfig::fromArray(
            $this->server->connectionConfig(['token' => 'wrong', 'topology' => 'single-node'])
        ));

        try {
            $client->putMany($this->entries(2));
            $this->fail('the unauthorised check was swallowed');
        } catch (ServerException $exception) {
            $this->assertTrue($exception->isUnauthorized());
        }

        $this->assertSame([Wire::OP_REPL_METRICS], $client->ops);
    }

    /**
     * A check on the answer, not on a behaviour the server is known to have.
     *
     * On success rostam always reports every entry applied - v0.7.0-beta6
     * skips an entry it cannot store and answers an error instead - so no real
     * server produces this. A count that does not add up is still not an answer
     * to return past as though it did, and since no server can be made to send
     * one, it is altered on its way back.
     */
    public function test_a_batch_applied_short_of_what_was_sent_is_reported(): void
    {
        $this->client();
        $client = new ShortchangedClient(ConnectionConfig::fromArray(
            $this->server->connectionConfig(['topology' => 'single-node'])
        ));

        $this->expectException(RostamException::class);
        $this->expectExceptionMessageMatches('/put_batch applied 2 of the 3 entries/');

        $client->putMany($this->entries(3));
    }

    /**
     * The real decoder reads every entry before applying any, so a batch
     * truncated at its last entry stores none of it. Asserted in both modes:
     * this is the server's behaviour, and the fake claims to match it.
     */
    public function test_a_truncated_batch_is_refused_and_applies_nothing(): void
    {
        $this->client();
        [$first, $second] = $this->entries(2);

        $args = Wire::putBatchArgs([$first, $second]);
        $args = substr($args, 0, strlen($args) - 3);

        $client = new RecordingClient(ConnectionConfig::fromArray($this->server->connectionConfig()));

        try {
            $client->pipeline([new Command(Wire::OP_PUT_BATCH, $args)]);
            $this->fail('a truncated batch was accepted');
        } catch (ServerException $exception) {
            $this->assertSame(Status::ERROR, $exception->status);
            $this->assertStringContainsString('internal error', $exception->getMessage());
        }

        $this->assertNull($this->plainClient()->get($first[0]), 'the first entry of a refused batch was applied');
    }
}

/**
 * A TcpClient whose put_batch answers claim one entry fewer than was sent.
 */
final class ShortchangedClient extends TcpClient
{
    protected function dispatch(array $commands): array
    {
        $responses = parent::dispatch($commands);

        foreach ($commands as $index => $command) {
            if ($command->op === Wire::OP_PUT_BATCH && $responses[$index]->isOk()) {
                $applied = Wire::decodePutBatchResult($responses[$index]->payload);
                $responses[$index] = new Response(Status::OK, pack('N', $applied - 1));
            }
        }

        return $responses;
    }
}

/**
 * A TcpClient that remembers every op it put on the wire, in order, and how
 * many exchanges carried them.
 */
class RecordingClient extends TcpClient
{
    /** @var list<string> */
    public array $ops = [];

    public int $roundTrips = 0;

    protected function dispatch(array $commands): array
    {
        $this->roundTrips++;

        foreach ($commands as $command) {
            $this->ops[] = $command->op;
        }

        return parent::dispatch($commands);
    }
}

/**
 * A TcpClient whose first topology check comes back as the generic error.
 */
final class FailsTheFirstTopologyCheck extends RecordingClient
{
    private bool $failed = false;

    protected function dispatch(array $commands): array
    {
        $responses = parent::dispatch($commands);

        foreach ($commands as $index => $command) {
            if ($command->op === Wire::OP_REPL_METRICS && ! $this->failed) {
                $this->failed = true;
                $responses[$index] = new Response(Status::ERROR, 'internal error');
            }
        }

        return $responses;
    }
}
