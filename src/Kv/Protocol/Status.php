<?php

declare(strict_types=1);

namespace Rostam\Kv\Protocol;

/**
 * Response status codes carried in the first byte of a response body.
 */
final class Status
{
    public const OK = 0;

    public const NOT_FOUND = 1;

    public const NOT_LEADER = 2;

    public const ERROR = 3;

    public const UNAUTHORIZED = 4;

    public static function describe(int $status): string
    {
        return match ($status) {
            self::OK => 'ok',
            self::NOT_FOUND => 'not found',
            self::NOT_LEADER => 'not leader',
            self::ERROR => 'server error',
            self::UNAUTHORIZED => 'unauthorized (auth token missing or invalid)',
            default => 'status '.$status,
        };
    }
}
