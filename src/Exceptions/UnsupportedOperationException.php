<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Exceptions;

/**
 * The server does not know an op this package needs.
 *
 * In practice that means a Rostam older than v0.5.0, which is where the
 * conditional writes (`set_nx`, `cas`, `cad`, `caex`) and `incr_ex` landed.
 * It is deliberately not a {@see ServerException}: the store swallows those to
 * report a failed increment, and a version mismatch must not be swallowed.
 */
class UnsupportedOperationException extends RostamException
{
    public function __construct(public readonly string $op, string $detail = '')
    {
        parent::__construct(
            "the Rostam server does not know the op [{$op}]"
            .($detail !== '' ? " ({$detail})" : '')
            .'. This package needs Rostam v0.5.0 or newer.'
        );
    }
}
