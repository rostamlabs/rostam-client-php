<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rostam\Testing\FakeServer;
use RuntimeException;

/**
 * Where ROSTAM_TEST_SERVER actually points.
 *
 * Before v0.3.1 only the port was read and the host was assumed to be
 * 127.0.0.1, so a suite aimed at a server on another machine quietly ran
 * against whatever was listening locally on that port - or nothing at all.
 */
class ExternalServerTargetTest extends TestCase
{
    private string|false $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = getenv('ROSTAM_TEST_SERVER');
    }

    protected function tearDown(): void
    {
        putenv($this->server === false ? 'ROSTAM_TEST_SERVER' : 'ROSTAM_TEST_SERVER='.$this->server);

        parent::tearDown();
    }

    /**
     * @return list<array{string, string, int}>
     */
    public static function targets(): array
    {
        return [
            ['127.0.0.1:7411', '127.0.0.1', 7411],
            ['10.1.2.3:7000', '10.1.2.3', 7000],
            ['rostam.internal:7411', 'rostam.internal', 7411],
        ];
    }

    #[DataProvider('targets')]
    public function test_the_host_is_kept_not_assumed(string $target, string $host, int $port): void
    {
        putenv('ROSTAM_TEST_SERVER='.$target);

        // Nothing is dialled here: in external mode start() only records where
        // the server is.
        $config = FakeServer::start()->connectionConfig();

        $this->assertSame($host, $config['host']);
        $this->assertSame($port, $config['port']);
    }

    /**
     * @return list<array{string}>
     */
    public static function unusableTargets(): array
    {
        return [['127.0.0.1'], ['rostam.internal'], [':7411'], ['127.0.0.1:']];
    }

    #[DataProvider('unusableTargets')]
    public function test_a_target_that_is_not_host_and_port_is_refused(string $target): void
    {
        putenv('ROSTAM_TEST_SERVER='.$target);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must look like host:port/');

        FakeServer::start();
    }
}
