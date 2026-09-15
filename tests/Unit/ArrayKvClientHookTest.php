<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rostam\Testing\ArrayKvClient;

class ArrayKvClientHookTest extends TestCase
{
    /**
     * The hook exists to put a rival between a read and a write. A write op
     * that skips it gives a test a window it believes it opened and did not -
     * which is how a queue test of a compare-and-swap cursor passed against a
     * cursor with no compare in it.
     */
    public function test_every_op_that_may_change_a_key_calls_the_hook_first(): void
    {
        $client = new ArrayKvClient;
        $seen = [];
        $client->beforeWrite = function (string $key, ArrayKvClient $self) use (&$seen, $client): void {
            $this->assertSame($client, $self);
            $seen[] = end($self->ops).':'.$key;
        };

        $client->put('a', 'v');
        $client->putMany([['b', 'v', 0], ['c', 'v', 0]]);
        $client->setNx('d', 'v');
        $client->cas('a', 'w', 'v');
        $client->cad('a', 'nope');
        $client->caex('b', 'v', 10);
        $client->getdel('c');
        $client->getset('c', 'v');
        $client->del('d');
        $client->delMany(['e', 'f']);
        $client->increment('n');
        $client->expire('n', 10);
        $client->persist('n');

        // Reads and flush name no key they could change.
        $client->get('a');
        $client->getMany(['a']);
        $client->exists('a');
        $client->ttl('a');
        $client->flush();

        $this->assertSame([
            'put:a',
            'putMany:b', 'putMany:c',
            'setNx:d',
            'cas:a',
            'cad:a',
            'caex:b',
            'getdel:c',
            'getset:c',
            'del:d',
            'delMany:e', 'delMany:f',
            'increment:n',
            'expire:n',
            'persist:n',
        ], $seen);
    }

    /**
     * A hook that writes the same key without clearing itself used to call
     * itself back until the stack ran out. Its own writes are not announced.
     */
    public function test_writes_made_by_the_hook_do_not_call_it_again(): void
    {
        $client = new ArrayKvClient;
        $calls = 0;

        $client->beforeWrite = function (string $key, ArrayKvClient $self) use (&$calls): void {
            $calls++;
            $self->del($key);
            $self->increment('rival');
        };

        $client->put('k', 'v1');
        $this->assertSame(1, $calls);
        $this->assertSame('v1', $client->get('k'));

        // ...and the next write outside it is announced as usual.
        $client->put('k', 'v2');
        $this->assertSame(2, $calls);
    }

    /** Called before the write, so what the hook does is what the op then meets. */
    public function test_a_rival_write_in_the_hook_is_what_the_op_then_sees(): void
    {
        $client = new ArrayKvClient;
        $client->put('cursor', 'h0');

        $client->beforeWrite = function (string $key, ArrayKvClient $self): void {
            $self->beforeWrite = null;
            $self->put($key, 'h1');
        };

        $this->assertFalse($client->cas('cursor', 'h1', 'h0'));
        $this->assertSame('h1', $client->get('cursor'));
    }
}
