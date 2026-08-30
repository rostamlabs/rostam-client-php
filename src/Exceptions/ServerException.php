<?php

declare(strict_types=1);

namespace Rostam\Exceptions;

use Rostam\Kv\Protocol\Status;

/**
 * The server answered with a non-OK status other than "not found".
 */
class ServerException extends RostamException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail = '',
        public readonly string $op = '',
    ) {
        $message = Status::describe($status);

        if ($op !== '') {
            $message = $op.': '.$message;
        }

        if ($detail !== '') {
            $message .= ': '.$detail;
        }

        parent::__construct($message);
    }

    public function isUnauthorized(): bool
    {
        return $this->status === Status::UNAUTHORIZED;
    }

    /**
     * True when the write landed on a replica instead of the shard leader.
     */
    public function isNotLeader(): bool
    {
        return $this->status === Status::NOT_LEADER;
    }
}
