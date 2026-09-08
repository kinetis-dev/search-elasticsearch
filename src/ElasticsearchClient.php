<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Kinetis\Search\AbstractSearchClient;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchCall;
use Kinetis\Search\SearchClient;
use LogicException;

/**
 * {@see SearchClient} over the official Elastic\Elasticsearch\Client: the
 * five calls an application can make against either engine, in this
 * engine's terms.
 *
 * Only this engine's half of a call happens here. The parameters are
 * {@see AbstractSearchClient}'s, elasticsearch-php builds the request
 * from them, the response object it answers with is read as the array
 * both engines' envelopes share, and this engine's exceptions become the
 * ones both adapters report. Every response body is the cluster's own,
 * untouched.
 *
 * The wrapped client stays available: kinetis/search-elasticsearch binds
 * Elastic\Elasticsearch\Client too, and an application that needs
 * anything outside these five calls — ES|QL, index management, the bulk
 * helper — injects that instead.
 */
final readonly class ElasticsearchClient extends AbstractSearchClient
{
    public function __construct(private Client $client)
    {
    }

    /**
     * Every error status the cluster answered with is a
     * {@see SearchRequestException} carrying the client's own exception,
     * where the cluster's error text lives. Both of the client's
     * response exceptions carry the status as their code.
     *
     * A request that never completed is a {@see SearchNetworkException}
     * here as it is on the other engine, which takes unwrapping:
     * Elastic\Transport\Transport reports one as a
     * NoNodeAvailableException over the exception that actually
     * happened. Anything else that pool raises is left as itself.
     */
    #[\Override]
    protected function send(SearchCall $call, array $params): array
    {
        // elasticsearch-php types each endpoint's parameters as that
        // endpoint's own array shape, and four of the five require a key:
        // `index` and `body` to index, `id` and `index` to get or delete,
        // `body` to bulk. AbstractSearchClient assembles those, but the
        // shape belongs to the SearchCall arm rather than to send()'s one
        // parameter, so no signature here carries it. A missing key is the
        // client's own MissingParameterException.
        try {
            $response = match ($call) {
                SearchCall::Index => $this->client->index($params), // @phpstan-ignore argument.type
                SearchCall::Get => $this->client->get($params), // @phpstan-ignore argument.type
                SearchCall::Delete => $this->client->delete($params), // @phpstan-ignore argument.type
                SearchCall::Search => $this->client->search($params),
                SearchCall::Bulk => $this->client->bulk($params), // @phpstan-ignore argument.type
            };
        } catch (ClientResponseException | ServerResponseException $e) {
            throw SearchRequestException::status($e->getCode(), $e);
        } catch (NoNodeAvailableException $e) {
            throw $e->getPrevious() instanceof SearchNetworkException ? $e->getPrevious() : $e;
        }

        if (!$response instanceof Elasticsearch) {
            // Only reachable through setAsync(true), which this package
            // never sets: the client answers a promise there. Inventing
            // an envelope would make delete() report a deletion.
            throw new LogicException('The Elasticsearch client answered no response object.');
        }

        return $response->asArray();
    }
}
