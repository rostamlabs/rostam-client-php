<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv;

/**
 * One op to send: its name, its encoded args, and whether re-sending it on a
 * fresh socket would be harmless.
 */
final class Command
{
    public function __construct(
        public readonly string $op,
        public readonly string $args,
        public readonly bool $idempotent = false,
    ) {}
}
