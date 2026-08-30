<?php

declare(strict_types=1);

namespace Rostam\Exceptions;

/**
 * The bytes on the wire did not match the protocol, or a request would violate
 * one of its limits (frame size, key length, op-name length).
 */
class ProtocolException extends RostamException {}
