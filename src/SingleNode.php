<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch;

use Elastic\Transport\NodePool\Node;
use Elastic\Transport\NodePool\NodePoolInterface;

/**
 * The one origin SEARCH_ELASTICSEARCH_HOST names, answered to every
 * request, with no liveness state.
 *
 * Elasticsearch's default pool exists to rotate over several nodes and
 * to stop sending to one that failed. This client has one node and no
 * failover — a multi-node cluster belongs behind a load balancer — so
 * that bookkeeping has nothing to choose between, and carrying it is
 * what breaks a client: Elastic\Transport\Transport marks the node it
 * failed on dead, SimpleNodePool's default NoResurrect strategy never
 * revives it, and every later request through that client answers
 * NoNodeAvailableException. One dropped connection would end searching
 * for as long as the client lives — the whole worker, for a client built
 * outside the container and kept.
 *
 * Keeping the node alive also keeps the failure legible: the transport
 * reaches the end of its (empty) retry loop and reports the exception
 * that actually happened as the cause, instead of the pool's own "no
 * alive nodes".
 *
 * Reaching an unhealthy cluster is the cluster's and the balancer's
 * problem to report; this client's job is to say truthfully what
 * happened to each request.
 */
final class SingleNode implements NodePoolInterface
{
    private Node $node;

    public function __construct(string $origin)
    {
        $this->node = new Node($origin);
    }

    #[\Override]
    public function nextNode(): Node
    {
        return $this->node;
    }

    /**
     * Ignored: the origin arrived through the constructor, and this pool
     * has nothing to select between. ClientBuilder is still given the
     * same origin, because it reads its own host list for something else
     * — an `.elastic.cloud` host is what turns on the serverless API
     * version header — so the two are one value passed to two readers
     * rather than a copy that goes unused.
     *
     * @param string[] $hosts
     */
    #[\Override]
    public function setHosts(array $hosts): self
    {
        return $this;
    }
}
