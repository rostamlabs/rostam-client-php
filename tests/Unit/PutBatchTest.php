<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ProtocolException;
use Rostam\Kv\Protocol\ConnectionConfig;
use Rostam\Kv\Protocol\Topology;
use Rostam\Kv\Protocol\Wire;
use Rostam\Kv\TcpClient;

/**
 * The batch op's encoding, its two caps, and the declaration that gates it.
 *
 * Byte strings here are built with pack() and chr(), never a double-quoted
 * escape: the formatter folds those into raw bytes, which has already turned
 * one file in this package binary and garbled a comment in another.
 */
class PutBatchTest extends TestCase
{
    public function test_it_encodes_a_count_then_single_put_layouts(): void
    {
        $args = Wire::putBatchArgs([
            ['a', 'x', 0],
            ['bb', 'yy', 1500],
        ]);

        $this->assertSame(
            pack('N', 2)
                .pack('n', 1).'a'.pack('N', 1).'x'.pack('J', 0)
                .pack('n', 2).'bb'.pack('N', 2).'yy'.pack('J', 1500),
            $args
        );
    }

    public function test_the_entry_layout_is_exactly_a_single_puts(): void
    {
        $this->assertSame(
            pack('N', 1).Wire::putArgs('key', 'value', 60000),
            Wire::putBatchArgs([['key', 'value', 60000]])
        );
    }

    /**
     * Not a server limit - a 4097-entry batch applied in full on v0.7.0-beta6 -
     * but the size the server's own clients split at, and this one refuses to
     * build a batch it would itself have split.
     */
    public function test_it_refuses_to_build_a_batch_past_the_split_size(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/split at 4096 entries here, got 4097/');

        Wire::putBatchArgs(array_fill(0, Wire::MAX_PUT_BATCH_ENTRIES + 1, ['k', 'v', 0]));
    }

    /**
     * `server.MaxFrameSize` bounds the body, and the server does not answer a
     * body over it - it drops the connection. Refusing here gives the caller a
     * reason instead of a dead socket.
     */
    public function test_a_body_over_sixteen_mebibytes_is_refused_before_it_is_sent(): void
    {
        $this->assertSame(16 * 1024 * 1024, Wire::MAX_FRAME);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/request body of \d+ bytes exceeds the server limit of 16777216/');

        Wire::frame(Wire::OP_PUT, Wire::putArgs('k', str_repeat('v', Wire::MAX_FRAME)));
    }

    /** The bound is on the body alone, exactly as the server reads it: a body of exactly 16 MiB is allowed. */
    public function test_a_body_of_exactly_sixteen_mebibytes_is_allowed(): void
    {
        $op = Wire::OP_PUT;
        $overhead = 1 + strlen($op) + 4;

        $frame = Wire::frame($op, str_repeat('a', Wire::MAX_FRAME - $overhead));

        $this->assertSame(Wire::MAX_FRAME, unpack('N', substr($frame, 0, 4))[1]);
    }

    public function test_the_answer_is_a_four_byte_count(): void
    {
        $this->assertSame(4096, Wire::decodePutBatchResult(pack('N', 4096)));

        $this->expectException(ProtocolException::class);

        Wire::decodePutBatchResult(pack('J', 4096));
    }

    public function test_it_splits_at_the_entry_cap(): void
    {
        $entries = array_fill(0, Wire::MAX_PUT_BATCH_ENTRIES * 2 + 1, ['k', 'v', 0]);

        $chunks = TcpClient::chunkForBatch($entries, PHP_INT_MAX);

        $this->assertSame([4096, 4096, 1], array_map('count', $chunks));
    }

    /**
     * The frame cap, at a size a test can afford: each entry here costs
     * 2 + 1 + 4 + 10 + 8 = 25 bytes, so a 60-byte budget holds two.
     */
    public function test_it_splits_at_the_byte_budget_and_keeps_order(): void
    {
        $entries = [];

        foreach (range(0, 4) as $i) {
            $entries[] = [(string) $i, str_repeat('v', 10), 0];
        }

        $chunks = TcpClient::chunkForBatch($entries, 60);

        $this->assertSame([2, 2, 1], array_map('count', $chunks));
        $this->assertSame(['0', '1', '2', '3', '4'], array_column(array_merge(...$chunks), 0));
    }

    /**
     * Too large for any frame, and sent alone so the frame encoder refuses it
     * exactly as it would refuse the same value in a single put.
     */
    public function test_an_entry_larger_than_the_budget_goes_alone(): void
    {
        $chunks = TcpClient::chunkForBatch([['a', 'x', 0], ['huge', str_repeat('v', 100), 0], ['b', 'y', 0]], 40);

        $this->assertSame([1, 1, 1], array_map('count', $chunks));
    }

    public function test_nothing_to_write_is_no_batches(): void
    {
        $this->assertSame([], TcpClient::chunkForBatch([], 1024));
    }

    public function test_the_default_topology_declares_nothing(): void
    {
        $this->assertSame(Topology::Unknown, ConnectionConfig::fromArray([])->topology);
    }

    public function test_a_single_node_can_be_declared(): void
    {
        $this->assertSame(Topology::SingleNode, ConnectionConfig::fromArray(['topology' => 'single-node'])->topology);
    }

    /** Casting an enum to a string is an Error, so the enum is taken as it is. */
    public function test_the_enum_itself_is_accepted(): void
    {
        $this->assertSame(Topology::SingleNode, ConnectionConfig::fromArray(['topology' => Topology::SingleNode])->topology);
    }

    /** Neither a warning nor an Error on the way to the refusal - just the refusal. */
    public function test_a_topology_that_is_not_even_a_string_is_refused_cleanly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown topology \[array\]/');

        ConnectionConfig::fromArray(['topology' => ['single-node']]);
    }

    /**
     * Refused, not defaulted: `single_node` would otherwise fall back to the
     * slow path without a word, and whoever wrote it would never learn why.
     */
    public function test_an_unrecognised_topology_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown topology \[single_node\]/');

        ConnectionConfig::fromArray(['topology' => 'single_node']);
    }
}
