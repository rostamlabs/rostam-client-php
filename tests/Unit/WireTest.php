<?php

declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rostam\Exceptions\ProtocolException;
use Rostam\Kv\Protocol\Wire;

class WireTest extends TestCase
{
    public function test_it_frames_an_op_without_a_token(): void
    {
        $frame = Wire::frame('get', 'ARGS');

        // [len u32][opLen u8]["get"][argsLen u32]["ARGS"]
        $this->assertSame(
            pack('N', 12)."\x03".'get'.pack('N', 4).'ARGS',
            $frame
        );
    }

    public function test_it_frames_an_op_with_a_token_using_protocol_v2(): void
    {
        $frame = Wire::frame('get', '', 'secret');

        $body = "\x02"."\x06".'secret'."\x03".'get'.pack('N', 0);

        $this->assertSame(pack('N', strlen($body)).$body, $frame);
        $this->assertSame(0x02, ord($frame[4]));
    }

    public function test_key_args_layout(): void
    {
        $this->assertSame("\x00\x05".'hello', Wire::keyArgs('hello'));
    }

    public function test_put_args_layout(): void
    {
        $this->assertSame(
            "\x00\x01".'k'.pack('N', 2).'vv'.pack('J', 1500),
            Wire::putArgs('k', 'vv', 1500)
        );
    }

    public function test_expire_args_layout(): void
    {
        $this->assertSame("\x00\x01".'k'.pack('J', 60000), Wire::expireArgs('k', 60000));
    }

    public function test_incr_ex_args_layout(): void
    {
        // [keyLen u16][key][delta i64][ttlMs u64]
        $this->assertSame(
            "\x00\x01".'k'.pack('J', -3).pack('J', 0),
            Wire::incrExArgs('k', -3)
        );
        $this->assertSame(
            "\x00\x01".'k'.pack('J', 1).pack('J', 60000),
            Wire::incrExArgs('k', 1, 60000)
        );
    }

    public function test_cas_args_layout(): void
    {
        // [keyLen u16][key][valLen u32][val][has u8][expLen u32][expected][ttlMs u64]
        $this->assertSame(
            "\x00\x01".'k'.pack('N', 3).'new'."\x01".pack('N', 3).'old'.pack('J', 0),
            Wire::casArgs('k', 'new', 'old')
        );

        // A null expectation is "store only if absent", and carries no bytes.
        $this->assertSame(
            "\x00\x01".'k'.pack('N', 3).'new'."\x00".pack('N', 0).pack('J', 500),
            Wire::casArgs('k', 'new', null, 500)
        );
    }

    public function test_compare_args_layouts(): void
    {
        $this->assertSame("\x00\x01".'k'.pack('N', 5).'token', Wire::compareArgs('k', 'token'));
        $this->assertSame(
            "\x00\x01".'k'.pack('N', 5).'token'.pack('J', 30000),
            Wire::compareExpireArgs('k', 'token', 30000)
        );
    }

    public function test_it_decodes_a_signed_counter(): void
    {
        $this->assertSame(42, Wire::decodeCounter(pack('J', 42)));
        $this->assertSame(-7, Wire::decodeCounter(pack('J', -7)));
        $this->assertSame(-2, Wire::decodeCounter(pack('J', -2)));
    }

    public function test_it_decodes_a_flag(): void
    {
        $this->assertTrue(Wire::decodeFlag("\x01"));
        $this->assertFalse(Wire::decodeFlag("\x00"));
        $this->assertFalse(Wire::decodeFlag(''));
    }

    public function test_it_decodes_a_found_value(): void
    {
        $this->assertNull(Wire::decodeFoundValue("\x00"));
        $this->assertNull(Wire::decodeFoundValue(''));
        $this->assertSame('hi', Wire::decodeFoundValue("\x01".pack('N', 2).'hi'));
        $this->assertSame('', Wire::decodeFoundValue("\x01".pack('N', 0)));
    }

    public function test_it_refuses_an_oversized_key(): void
    {
        $this->expectException(ProtocolException::class);

        Wire::keyArgs(str_repeat('k', 65536));
    }

    public function test_it_refuses_a_negative_ttl(): void
    {
        $this->expectException(ProtocolException::class);

        Wire::putArgs('k', 'v', -1);
    }

    public function test_it_refuses_an_oversized_op_name(): void
    {
        $this->expectException(ProtocolException::class);

        Wire::frame(str_repeat('o', 256), '');
    }

    /**
     * The frame's own length is verified in Connection::readResponse, but the
     * found-value payload carries a SECOND length inside it, and that one used
     * to be trusted. substr() does not complain about a length it cannot honour
     * - it returns what it has - so a short payload came back looking like a
     * complete value. On getdel that is the worst possible shape for a bug: the
     * value is truncated on the way out and the original is already gone, so
     * nothing downstream can tell and there is no copy left to compare with.
     */
    public function test_it_refuses_a_found_value_shorter_than_it_declares(): void
    {
        $payload = "\x01".pack('N', 32).'only-eight';

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('declares 32 bytes but carries 10');

        Wire::decodeFoundValue($payload);
    }

    public function test_it_refuses_a_found_value_longer_than_it_declares(): void
    {
        // Trailing bytes mean the stream is out of step just as surely as
        // missing ones, and reading past the declared length would hand back a
        // value the server never sent.
        $payload = "\x01".pack('N', 3).'abc-and-then-some';

        $this->expectException(ProtocolException::class);

        Wire::decodeFoundValue($payload);
    }

    public function test_it_still_decodes_an_exact_found_value(): void
    {
        $this->assertSame('hello', Wire::decodeFoundValue("\x01".pack('N', 5).'hello'));
        $this->assertNull(Wire::decodeFoundValue("\x00"));
    }

    /**
     * An empty value is a real value: the server answers found=1 with a zero
     * length, and that must not read as a miss.
     */
    public function test_it_decodes_a_found_but_empty_value(): void
    {
        $this->assertSame('', Wire::decodeFoundValue("\x01".pack('N', 0)));
    }
}
