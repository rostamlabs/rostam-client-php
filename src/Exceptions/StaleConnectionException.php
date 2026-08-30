<?php

declare(strict_types=1);

namespace Rostam\Exceptions;

use Rostam\Kv\TcpClient;

/**
 * Internal signal: a pooled socket failed before a single response byte arrived.
 *
 * A server (or a middlebox) may close an idle connection at any time, and from
 * this side that is indistinguishable from a request that was never seen. It is
 * only ever raised for a *reused* socket and only *before* the response starts,
 * so the client may safely re-send an idempotent op on a fresh connection.
 * Callers never see it: {@see TcpClient} either retries
 * or converts it to a {@see ConnectionException}.
 */
class StaleConnectionException extends RostamException {}
