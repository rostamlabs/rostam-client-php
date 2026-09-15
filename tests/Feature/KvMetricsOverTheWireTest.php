<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ServerException;
use Rostam\Kv\Metrics\KvMetrics;
use Rostam\Kv\Protocol\Status;
use Rostam\Kv\TcpClient;
use Rostam\Testing\ArrayKvClient;
use Rostam\Testing\FakeServer;

/**
 * `__kv_metrics__` end to end, against the fake and - in conformance mode - a
 * real rostam v0.7.0-beta3 or newer.
 *
 * Exact values are asserted only against the fake. A real server is shared
 * and cumulative since it started, so the numbers belong to every test that
 * ever touched it; what is asserted there is the shape a caller relies on.
 */
class KvMetricsOverTheWireTest extends TestCase
{
    private ?FakeServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        parent::tearDown();
    }

    private function client(bool $legacy = false, int $liveEvictions = 0): TcpClient
    {
        if (FakeServer::isExternal() && ($legacy || $liveEvictions > 0)) {
            $this->markTestSkipped('needs the fake server; a real one cannot be asked for this');
        }

        $this->server = FakeServer::start(legacy: $legacy, liveEvictions: $liveEvictions);

        return TcpClient::fromArray($this->server->connectionConfig());
    }

    public function test_the_counters_a_safety_check_reads_are_whole_numbers(): void
    {
        if (! FakeServer::supports('0.7.0-beta3')) {
            $this->markTestSkipped('__kv_metrics__ arrived in rostam v0.7.0-beta3');
        }

        $metrics = $this->client()->kvMetrics();

        $this->assertIsInt($metrics->evictionsLive());
        $this->assertGreaterThanOrEqual(0, $metrics->evictionsLive());
        $this->assertIsInt($metrics->rejects());
        $this->assertIsInt($metrics->get(KvMetrics::ENTRIES));
    }

    /**
     * Fake only. On a shared real server keys written with a TTL by earlier
     * tests can expire between the two reads, so no delta is guaranteed there
     * and asserting a looser one would prove nothing.
     */
    public function test_the_key_count_moves_with_the_keys(): void
    {
        if (FakeServer::isExternal()) {
            $this->markTestSkipped('a shared server cannot promise an exact key count between two reads');
        }

        $client = $this->client();

        $before = $client->kvMetrics()->get(KvMetrics::ENTRIES);
        $client->put('kvm:one', 'v');

        $this->assertSame(1, $client->kvMetrics()->get(KvMetrics::ENTRIES) - $before);
    }

    public function test_a_server_that_has_lost_live_records_says_how_many(): void
    {
        $this->assertSame(165, $this->client(liveEvictions: 165)->kvMetrics()->evictionsLive());
    }

    /**
     * Older than v0.7.0-beta3, the op does not exist. The server answers the
     * same generic error it gives for anything it cannot carry out, and this
     * client does not pretend to know which.
     */
    public function test_an_older_server_answers_the_generic_error(): void
    {
        try {
            $this->client(legacy: true)->kvMetrics();
            $this->fail('an old server was read as having metrics');
        } catch (ServerException $exception) {
            $this->assertSame(Status::ERROR, $exception->status);
            $this->assertSame('__kv_metrics__', $exception->op);
        }
    }

    public function test_the_in_memory_fake_reports_only_what_it_knows(): void
    {
        $client = new ArrayKvClient;
        $client->put('a', '1');
        $client->put('b', '2');

        $metrics = $client->kvMetrics();

        $this->assertSame(2, $metrics->get(KvMetrics::ENTRIES));
        $this->assertSame(0, $metrics->evictionsLive());
        $this->assertNull($metrics->get('rostam_kv_hits_total'), 'the fake invented a counter it does not keep');

        $client->simulateLiveEvictions(3);
        $this->assertSame(3, $client->kvMetrics()->evictionsLive());
    }
}
