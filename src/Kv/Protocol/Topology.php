<?php

// SPDX-License-Identifier: Apache-2.0
declare(strict_types=1);

namespace Rostam\Kv\Protocol;

/**
 * What the operator says about the server's shape.
 *
 * A declaration, not a detection, and deliberately so. Nothing on the wire
 * proves a server is a single node: `__repl_metrics__` answers an empty shard
 * list on a single node, but a cluster member that hosts no shards answers it
 * too, and the cluster admin ops fail on a single node with the same generic
 * `internal error` rostam gives for any op it cannot carry out. The wire can
 * prove the opposite - a non-empty shard list means replication - and that is
 * the only check this package makes.
 *
 * It matters for one thing today. `put_batch` routes the whole batch by its
 * first key. On a single node every key reaches the same store and each lands
 * where a read will look for it; on a cluster, keys owned by other shards are
 * stored on the first key's shard and become unreadable. So the batch op is
 * used only where the operator has said it is safe.
 */
enum Topology: string
{
    /**
     * Nothing has been declared. Every multi-key write is sent as one pipeline
     * of single-key ops, routed per key - correct on any topology.
     */
    case Unknown = 'unknown';

    /**
     * One node, no replication. Multi-key writes may use `put_batch`.
     */
    case SingleNode = 'single-node';
}
