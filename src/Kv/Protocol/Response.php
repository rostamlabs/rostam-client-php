<?php

declare(strict_types=1);

namespace Rostam\Kv\Protocol;

/**
 * One decoded response body: a status and its (possibly empty) payload.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $payload,
    ) {}

    public function isOk(): bool
    {
        return $this->status === Status::OK;
    }

    public function isMiss(): bool
    {
        return $this->status === Status::NOT_FOUND;
    }
}
