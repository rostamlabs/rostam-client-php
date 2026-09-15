<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Exceptions;

/**
 * The server contradicts what the connection was told about it.
 *
 * Raised before anything is written, so the contradiction costs a failed call
 * rather than a batch of keys stored where no read will find them.
 */
class TopologyMismatchException extends RostamException {}
