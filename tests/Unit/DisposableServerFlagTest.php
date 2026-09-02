<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rostam\Testing\FakeServer;

/**
 * The one flag standing between a destructive test and somebody's keyspace.
 *
 * `flush` has no unit smaller than the whole server, so the tests that exercise
 * it are gated on the operator saying the server is a scratch one. Reading that
 * flag loosely defeats it: whoever writes `=0` means no, and would have been
 * told yes.
 */
class DisposableServerFlagTest extends TestCase
{
    private string|false $server;

    private string|false $flag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = getenv('ROSTAM_TEST_SERVER');
        $this->flag = getenv('ROSTAM_TEST_SERVER_IS_DISPOSABLE');

        putenv('ROSTAM_TEST_SERVER=127.0.0.1:7000');
    }

    protected function tearDown(): void
    {
        $this->restore('ROSTAM_TEST_SERVER', $this->server);
        $this->restore('ROSTAM_TEST_SERVER_IS_DISPOSABLE', $this->flag);

        parent::tearDown();
    }

    private function restore(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name.'='.$value);
    }

    /**
     * @return list<array{string}>
     */
    public static function refusals(): array
    {
        return [['0'], ['false'], ['no'], ['off'], [''], ['maybe'], ['1 '.'; rm -rf']];
    }

    #[DataProvider('refusals')]
    public function test_only_an_affirmative_value_unlocks_a_real_server(string $value): void
    {
        putenv('ROSTAM_TEST_SERVER_IS_DISPOSABLE='.$value);

        $this->assertFalse(FakeServer::isDisposable(), "[{$value}] was read as permission to wipe a server");
    }

    /**
     * @return list<array{string}>
     */
    public static function permissions(): array
    {
        return [['1'], ['true'], ['TRUE'], ['yes'], ['on']];
    }

    #[DataProvider('permissions')]
    public function test_the_documented_value_and_its_obvious_synonyms_work(string $value): void
    {
        putenv('ROSTAM_TEST_SERVER_IS_DISPOSABLE='.$value);

        $this->assertTrue(FakeServer::isDisposable());
    }

    /** With no real server named, the fake is always this test's own to destroy. */
    public function test_the_fake_needs_no_permission(): void
    {
        putenv('ROSTAM_TEST_SERVER');
        putenv('ROSTAM_TEST_SERVER_IS_DISPOSABLE=0');

        $this->assertTrue(FakeServer::isDisposable());
    }
}
