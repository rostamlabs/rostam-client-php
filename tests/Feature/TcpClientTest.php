<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ConnectionException;
use Rostam\Exceptions\ServerException;
use Rostam\Kv\Protocol\Connection;
use Rostam\Kv\Protocol\ConnectionConfig;
use Rostam\Kv\Protocol\Response;
use Rostam\Kv\Protocol\Status;
use Rostam\Kv\Protocol\Wire;
use Rostam\Kv\TcpClient;
use Rostam\Testing\FakeServer;
use Rostam\TimeUnit;

/**
 * Drives the real client over a real socket against {@see FakeServer}, so the
 * framing, the pipelining and the retry logic are exercised end to end.
 */
class TcpClientTest extends TestCase
{
    /**
     * Every key this file touches. Only used to clear a shared server between
     * tests (see client()); a new key used in a new test belongs here too, or
     * that test inherits whatever the previous one left behind.
     */
    private const KEYS = [
        'a', 'absent', 'anything', 'b', 'blob', 'brief', 'c', 'd', 'greeting',
        'hits', 'k', 'lock', 'missing', 'name', 'nothing', 'other', 'window',
    ];

    private ?FakeServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        parent::tearDown();
    }

    private function client(string $token = '', int $dropAfter = 0, bool $legacy = false): TcpClient
    {
        // A real server cannot be asked to drop connections on a schedule, to
        // predate v0.5.0, or to demand a token this test invents - so those
        // scenarios stay with the fake and simply do not run in conformance mode.
        if (FakeServer::isExternal() && ($dropAfter > 0 || $legacy || $token !== '')) {
            $this->markTestSkipped('needs the fake server; not reproducible against a real one');
        }

        $this->server = FakeServer::start($token, $dropAfter, legacy: $legacy);

        $client = TcpClient::fromArray($this->server->connectionConfig(['token' => $token]));

        // The fake is a brand new process per test, so every test starts on an
        // empty store without asking. A real server is shared and remembers
        // everything, which is not a client bug but does mean these tests were
        // isolated by accident rather than by design - so in conformance mode
        // the key space is cleared explicitly first.
        if (FakeServer::isExternal()) {
            $client->delMany(self::KEYS);
        }

        return $client;
    }

    public function test_it_pings(): void
    {
        $this->assertTrue($this->client()->ping());
    }

    public function test_it_round_trips_a_value(): void
    {
        $client = $this->client();

        $client->put('greeting', 'salam');

        $this->assertSame('salam', $client->get('greeting'));
        $this->assertNull($client->get('nothing'));
    }

    public function test_it_stores_binary_values(): void
    {
        $client = $this->client();

        $bytes = random_bytes(1024);
        $client->put('blob', $bytes);

        $this->assertSame($bytes, $client->get('blob'));
    }

    public function test_a_ttl_expires_the_value(): void
    {
        $client = $this->client();

        $client->put('brief', 'v', 50, TimeUnit::Milliseconds);

        $this->assertSame('v', $client->get('brief'));

        usleep(120_000);

        $this->assertNull($client->get('brief'));
    }

    public function test_expire_persist_and_ttl(): void
    {
        $client = $this->client();

        $this->assertSame(-2, $client->ttl('absent'));

        $client->put('k', 'v');
        $this->assertSame(-1, $client->ttl('k'));

        $this->assertTrue($client->expire('k', 5_000, TimeUnit::Milliseconds));
        $this->assertGreaterThan(0, $client->ttl('k'));

        $this->assertTrue($client->persist('k'));
        $this->assertSame(-1, $client->ttl('k'));

        // Nothing to remove the second time round.
        $this->assertFalse($client->persist('k'));
        $this->assertFalse($client->expire('missing', 1_000, TimeUnit::Milliseconds));
    }

    public function test_exists(): void
    {
        $client = $this->client();

        $this->assertFalse($client->exists('k'));
        $client->put('k', 'v');
        $this->assertTrue($client->exists('k'));
    }

    public function test_set_if_absent_is_atomic_and_carries_the_ttl(): void
    {
        $client = $this->client();

        $this->assertTrue($client->setNx('lock', 'first', 5_000, TimeUnit::Milliseconds));
        $this->assertFalse($client->setNx('lock', 'second', 5_000, TimeUnit::Milliseconds));
        $this->assertSame('first', $client->get('lock'));
        $this->assertGreaterThan(0, $client->ttl('lock'));
    }

    public function test_set_if_absent_treats_an_expired_key_as_absent(): void
    {
        $client = $this->client();

        $client->setNx('lock', 'first', 50, TimeUnit::Milliseconds);

        usleep(120_000);

        $this->assertTrue($client->setNx('lock', 'second'));
        $this->assertSame('second', $client->get('lock'));
    }

    public function test_compare_and_swap(): void
    {
        $client = $this->client();

        // A null expectation means "only if absent".
        $this->assertTrue($client->cas('k', 'one', null));
        $this->assertFalse($client->cas('k', 'two', null));

        $this->assertFalse($client->cas('k', 'two', 'wrong'));
        $this->assertTrue($client->cas('k', 'two', 'one'));
        $this->assertSame('two', $client->get('k'));
    }

    public function test_compare_and_delete_and_expire(): void
    {
        $client = $this->client();
        $client->put('lock', 'owner-a');

        $this->assertFalse($client->cad('lock', 'owner-b'));
        $this->assertFalse($client->caex('lock', 'owner-b', 1_000, TimeUnit::Milliseconds));

        $this->assertTrue($client->caex('lock', 'owner-a', 5_000, TimeUnit::Milliseconds));
        $this->assertGreaterThan(0, $client->ttl('lock'));

        $this->assertTrue($client->cad('lock', 'owner-a'));
        $this->assertNull($client->get('lock'));
        $this->assertFalse($client->cad('lock', 'owner-a'));
    }

    public function test_get_and_delete_and_get_and_set(): void
    {
        $client = $this->client();

        $this->assertNull($client->getdel('k'));

        $client->put('k', 'first');
        $this->assertSame('first', $client->getdel('k'));
        $this->assertNull($client->get('k'));

        $this->assertNull($client->getset('k', 'one'));
        $this->assertSame('one', $client->getset('k', 'two'));
        $this->assertSame('two', $client->get('k'));
    }

    public function test_forget_reports_whether_the_key_existed(): void
    {
        $client = $this->client();
        $client->put('a', '1');

        $this->assertTrue($client->del('a'));
        $this->assertFalse($client->del('a'));
    }

    public function test_increment_is_server_side_and_signed(): void
    {
        $client = $this->client();

        $this->assertSame(1, $client->increment('hits'));
        $this->assertSame(11, $client->increment('hits', 10));
        $this->assertSame(-9, $client->increment('hits', -20));
    }

    public function test_increment_stamps_a_ttl_only_when_it_creates_the_key(): void
    {
        $client = $this->client();

        $client->increment('window', 1, 5_000, TimeUnit::Milliseconds);
        // Read in milliseconds: the whole point is that the deadline did not
        // move, and a 60 ms drift is invisible at second resolution.
        $opened = $client->pttl('window');
        $this->assertGreaterThan(0, $opened);

        usleep(60_000);

        // A second hit inside the window must not push the deadline back out.
        $client->increment('window', 1, 5_000, TimeUnit::Milliseconds);

        $this->assertLessThan($opened, $client->pttl('window'));
    }

    public function test_increment_leaves_an_existing_window_alone(): void
    {
        $client = $this->client();

        $client->put('hits', pack('J', 0), 5_000);
        $before = $client->ttl('hits');

        $client->increment('hits');

        $this->assertLessThanOrEqual($before, $client->ttl('hits'));
        $this->assertGreaterThan(0, $client->ttl('hits'));
    }

    public function test_increment_refuses_a_value_that_is_not_eight_bytes(): void
    {
        $client = $this->client();
        $client->put('name', 'keivan');

        try {
            $client->increment('name');
            $this->fail('expected the server to refuse the increment');
        } catch (ServerException $exception) {
            $this->assertSame(Status::ERROR, $exception->status);
            $this->assertSame('incr_ex', $exception->op);

            // The same words in both modes, and deliberately unhelpful ones.
            // The fake used to say "incr_ex value is not 8 bytes" here while a
            // real rostam-server said "internal error", and the assertion was
            // skipped against the real one to keep the difference quiet. That
            // gap is how a guard elsewhere in this client came to read a
            // message only the fake ever sent. Nothing on the wire separates
            // "that value is not a counter" from "the server is in trouble"
            // from "this server has never heard of incr_ex" - so the fake does
            // not separate them either.
            $this->assertStringContainsString('internal error', $exception->getMessage());
        }
    }

    public function test_batch_reads_come_back_in_request_order(): void
    {
        $client = $this->client();

        $client->putMany([
            ['a', 'one', 0],
            ['b', 'two', 0],
            ['d', 'four', 0],
        ]);

        $this->assertSame(
            ['a' => 'one', 'b' => 'two', 'c' => null, 'd' => 'four'],
            $client->getMany(['a', 'b', 'c', 'd'])
        );
    }

    public function test_batch_deletes_report_each_key(): void
    {
        $client = $this->client();
        $client->put('a', '1');

        $this->assertSame(['a' => true, 'b' => false], $client->delMany(['a', 'b']));
    }

    public function test_it_authenticates_with_a_token(): void
    {
        $client = $this->client('s3cret');

        $client->put('k', 'v');

        $this->assertSame('v', $client->get('k'));
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        if (FakeServer::isExternal()) {
            $this->markTestSkipped('a real server fixes its auth at launch; this needs a per-test token');
        }

        $this->server = FakeServer::start('s3cret');

        $client = TcpClient::fromArray($this->server->connectionConfig(['token' => 'wrong']));

        try {
            $client->get('k');
            $this->fail('expected the server to reject the token');
        } catch (ServerException $exception) {
            $this->assertTrue($exception->isUnauthorized());
        }
    }

    /**
     * An older server cannot be recognised, and this pins that it is not
     * claimed to be.
     *
     * There was a guard here that turned the server's error into "your Rostam
     * predates v0.5.0". It read a message no Rostam sends - only this
     * package's own fake did - so it never fired in production, and the test
     * that covered it passed against the fiction. Measured on a real v0.4.2
     * and a real v0.6.0, an unknown op, undecodable args and `incr_ex` on a
     * non-counter key all answer a byte-identical `internal error`, and there
     * is no version op to ask instead.
     *
     * Guessing would not have been free: reading an ordinary application-level
     * miss as a version mismatch turns Laravel's `increment()` on a
     * non-numeric key from `false` into a thrown exception.
     */
    public function test_an_older_server_cannot_be_told_apart_and_is_not_guessed_at(): void
    {
        $client = $this->client(legacy: true);

        // The pre-0.5.0 ops still work...
        $client->put('k', 'v');
        $this->assertSame('v', $client->get('k'));

        // ...and a newer one arrives as what it is: an error from the server,
        // with the server's own words and nothing invented on top.
        try {
            $client->setNx('k', 'v');
            $this->fail('expected the server to refuse the op');
        } catch (ServerException $exception) {
            $this->assertSame('set_nx', $exception->op);
            $this->assertStringContainsString('internal error', $exception->getMessage());
        }
    }

    /**
     * @return list<array{string, string}>
     */
    public static function malformedArgs(): array
    {
        // Written with pack() rather than a double-quoted byte escape:
        // these are declared LENGTHS, which pack says out loud, and an escape
        // written the other way gets folded into raw bytes by the formatter,
        // which turns this file binary and its diff unreadable in review.
        return [
            'put: key declares five bytes, carries two' => [Wire::OP_PUT, pack('n', 5).'ab'],
            'get: key declares five bytes, carries two' => [Wire::OP_GET, pack('n', 5).'ab'],
            'cas: value declares nine bytes, carries two' => [Wire::OP_CAS, pack('n', 2).'ab'.pack('N', 9).'xy'],
            // These two decode their args inline rather than through the shared
            // helpers, which is exactly how the same mistake survives a fix.
            'expire: key declares five bytes, carries two' => [Wire::OP_EXPIRE, pack('n', 5).'ab'],
            'incr_ex: key declares five bytes, carries two' => [Wire::OP_INCR_EX, pack('n', 5).'ab'],
        ];
    }

    /**
     * Frames this client would never send, answered the way rostam answers them.
     *
     * Two ways to get this wrong, and the stub had both. A short VALUE reached
     * `unpack` and raised, killing the server process. A short KEY did not even
     * raise - `substr` shortens in silence - so a truncated `get` came back as
     * an ordinary miss on a shorter key, which is a wrong answer rather than a
     * loud one. A real v0.6.0 answers `internal error` to all three, so these
     * run in both modes and the promise is the server's, not the fake's.
     */
    #[DataProvider('malformedArgs')]
    public function test_undecodable_args_are_answered_not_crashed_on(string $op, string $args): void
    {
        $client = $this->client();

        $response = $this->sendRaw(Wire::frame($op, $args));

        $this->assertSame(Status::ERROR, $response->status);
        $this->assertStringContainsString('internal error', $response->payload);

        // ...and the server is still there to serve the next test.
        $this->assertTrue($client->ping());
    }

    /**
     * The one malformed request rostam names, and it is named a layer lower.
     *
     * A body whose own header points past the end of what arrived never reaches
     * an op at all, so it is not "something the server could not carry out" and
     * does not get the generic answer. Measured on v0.6.0: `server: frame
     * truncated`. The stub decoded the body outside its guard and died here.
     */
    public function test_a_frame_that_points_past_its_own_end_is_named(): void
    {
        $client = $this->client();

        // op length 3, "put", and one byte where a four-byte args length goes.
        $body = chr(3).'put'.chr(1);
        $response = $this->sendRaw(pack('N', strlen($body)).$body);

        $this->assertSame(Status::ERROR, $response->status);
        $this->assertStringContainsString('frame truncated', $response->payload);

        $this->assertTrue($client->ping());
    }

    /** Write bytes this client's own encoders would never produce. */
    private function sendRaw(string $frame): Response
    {
        $connection = new Connection(ConnectionConfig::fromArray($this->server->connectionConfig()));
        $connection->open();
        $connection->write($frame);

        try {
            return $connection->readResponse();
        } finally {
            $connection->close();
        }
    }

    /**
     * Every other test here cleans up after itself by deleting the keys it
     * owns. A flush cannot: it has no unit smaller than the keyspace, so
     * against a shared server it would delete data no test ever wrote.
     */
    private function requireADisposableServer(): void
    {
        if (! FakeServer::isDisposable()) {
            $this->markTestSkipped(
                'flush wipes the whole server, and ROSTAM_TEST_SERVER may be one that holds '
                .'something. Set ROSTAM_TEST_SERVER_IS_DISPOSABLE=1 if it does not.'
            );
        }
    }

    /**
     * The op exists from v0.6.0. It is global: nothing narrows it, and the
     * argument it is sent does not.
     */
    public function test_flush_empties_the_whole_keyspace(): void
    {
        $this->requireADisposableServer();

        $client = $this->client();

        $client->put('k', 'v');
        $client->put('other', 'w');

        $client->flush();

        $this->assertNull($client->get('k'));
        $this->assertNull($client->get('other'));
    }

    /**
     * Nothing is broken afterwards - a flush is a wipe, not a shutdown, and the
     * same pooled connection has to keep working.
     */
    public function test_the_connection_survives_a_flush(): void
    {
        $this->requireADisposableServer();

        $client = $this->client();

        $client->put('k', 'before');
        $client->flush();
        $client->put('k', 'after');

        $this->assertSame('after', $client->get('k'));
        $this->assertTrue($client->ping());
    }

    public function test_an_idempotent_op_survives_a_socket_closed_while_idle(): void
    {
        // The server hangs up after every single op, so the second call always
        // lands on a pooled socket the peer has already closed.
        $client = $this->client(dropAfter: 1);

        $this->assertTrue($client->ping());
        $this->assertNull($client->get('anything'));
    }

    public function test_a_write_never_lands_on_a_socket_that_died_while_idle(): void
    {
        // A write is never *retried* - re-sending one the server may already
        // have applied is not safe. It does not need to be: a pooled socket is
        // checked before it carries anything, so a peer that hung up is dropped
        // rather than written to.
        $client = $this->client(dropAfter: 1);

        $client->ping();

        $client->put('k', 'v');

        $this->assertSame('v', $client->get('k'));
    }

    public function test_it_fails_clearly_when_nothing_is_listening(): void
    {
        $client = TcpClient::fromArray(['host' => '127.0.0.1', 'port' => 1, 'connect_timeout' => 0.5]);

        $this->expectException(ConnectionException::class);

        $client->ping();
    }
}
