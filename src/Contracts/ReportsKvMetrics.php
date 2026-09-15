<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Contracts;

use Rostam\Kv\Metrics\KvMetrics;

/**
 * A client that can ask the server what its key-value cache has been doing.
 *
 * Its own interface rather than a method on {@see KvClient}, because that one is
 * published and may already be implemented elsewhere: PHP resolves an
 * implemented interface eagerly, so a method added to it is a fatal error the
 * next time such a class is loaded, not a deprecation. A client that does not
 * carry this simply cannot report metrics.
 *
 * Needs rostam v0.7.0-beta3 or newer, where `__kv_metrics__` arrived. Before it
 * a cache-only server reported nothing about itself over the wire; an older
 * server answers the generic error it gives any op it does not know.
 */
interface ReportsKvMetrics
{
    /**
     * This node's key-value counters and gauges, as the server reports them.
     *
     * Node-wide and cumulative since the server started: they describe every
     * key on the node, whoever wrote it, not the keys this client wrote.
     */
    public function kvMetrics(): KvMetrics;
}
