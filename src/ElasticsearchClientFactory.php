<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch;

use Closure;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Kinetis\Config\Config;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\SearchTransport;
use Psr\Http\Client\ClientInterface;

/**
 * Builds an Elastic\Elasticsearch\Client through Elasticsearch's own
 * ClientBuilder. ClientBuilder::setHttpClient() takes a PSR-18
 * ClientInterface, and {@see SearchTransport} supplies one over the
 * Revolt-backed Symfony client, so a search suspends the calling Fiber
 * instead of blocking the worker. Without it the builder discovers a
 * PSR-18 client or falls back to Elastic\Transport\Client\Curl, both of
 * which block the whole event loop for the length of every request.
 *
 * The origin, deadline, response bound, credentials and TLS decision come
 * from SEARCH_ELASTICSEARCH_* through {@see SearchTransport}; see it for
 * what each key means and what a host may be.
 *
 * Four of the builder's own defaults are deliberately not taken:
 *
 * - Retries are pinned to 0 on the built transport rather than through
 *   `ClientBuilder::setRetries()`, which cannot express zero: build()
 *   replaces the value with the host count whenever `empty()` holds for
 *   it. It has to be zero, because Elastic\Transport\Transport catches
 *   PSR-18's NetworkExceptionInterface and re-sends the request. A
 *   deadline or a dropped connection part-way through an index or bulk
 *   request has an unknown dispatch outcome, and re-sending it can write
 *   the document twice. With no retry left, a request that never
 *   completed surfaces as
 *   Elastic\Transport\Exception\NoNodeAvailableException wrapping this
 *   project's own SearchNetworkException.
 * - `setBasicAuthentication()` is unused. It reaches
 *   Transport::setUserInfo(), which puts the credentials into the
 *   request URI's userinfo, where a transport error message would quote
 *   them. Basic credentials stay in the Symfony client's own auth_basic
 *   option, which is also why SEARCH_ELASTICSEARCH_HOST refuses a host
 *   carrying userinfo.
 * - No `setSSL*()` or `setCABundle()` call is made. ClientBuilder passes
 *   TLS settings to an adapter selected by the HTTP client's class name
 *   and throws HttpClientException for one it does not recognize; TLS is
 *   the transport's, under SEARCH_ELASTICSEARCH_VERIFY_PEER.
 * - The node pool is {@see SingleNode} rather than the default
 *   SimpleNodePool, whose liveness bookkeeping would take this client's
 *   one node out of service for the rest of the worker's life after a
 *   single failed request.
 *
 * $connection selects a named connection via Config::scopedKey(), the
 * convention every other *Factory::fromConfig() in this project follows.
 */
final class ElasticsearchClientFactory
{
    /** The configuration prefix {@see SearchTransport} reads every key under. */
    public const string CONFIG_PREFIX = 'SEARCH_ELASTICSEARCH';

    /**
     * $transportDecorator wraps the fully-configured PSR-18 client
     * (origin policy, deadline, response bound, auth and TLS already
     * applied) right before it is handed to ClientBuilder — the seam
     * kinetis/telemetry's TracingSearchTransport uses.
     *
     * Construction reaches no network: an unreachable cluster is not a
     * configuration error and surfaces on the first search.
     *
     * @param ?Closure(ClientInterface): ClientInterface $transportDecorator
     *
     * @throws SearchConfigurationException
     */
    public static function fromConfig(Config $config, string $connection = 'default', ?Closure $transportDecorator = null): Client
    {
        return self::over(
            SearchTransport::fromConfig($config, self::CONFIG_PREFIX, $connection, $transportDecorator),
            $config,
            $connection,
        );
    }

    /**
     * Another client over a transport that already exists, so that many
     * clients can share one connection pool.
     *
     * A client is cheap and holds no connection; the transport under it
     * owns the pool and is the part worth keeping. That asymmetry is why
     * this exists: Elastic\Transport\Transport keeps the last request
     * and the last response it saw in `$lastRequest`/`$lastResponse`, so
     * a client must not outlive the request whose documents those are.
     * {@see PackageBootstrap} builds the transport once for the worker
     * and a client per resolution over it.
     *
     * @throws SearchConfigurationException
     */
    public static function over(SearchTransport $transport, Config $config, string $connection = 'default'): Client
    {
        $builder = ClientBuilder::create()
            ->setHosts([$transport->origin])
            ->setNodePool(new SingleNode($transport->origin))
            ->setHttpClient($transport->client);

        self::applyApiKey($builder, $config, $connection);

        $client = $builder->build();
        $client->getTransport()->setRetries(0);

        return $client;
    }

    /**
     * An API key and a Basic username are two credentials for one
     * request, and which of them would reach the cluster depends on
     * header precedence a deployment cannot see. It is refused instead.
     *
     * {@see PackageBootstrap} calls this at registration, so a
     * deployment configuring both fails at boot without a client having
     * to be built and thrown away to find out.
     *
     * @throws SearchConfigurationException
     */
    public static function assertUsableCredentials(Config $config, string $connection = 'default'): void
    {
        $apiKeyKey = Config::scopedKey(self::CONFIG_PREFIX . '_API_KEY', $connection);
        $usernameKey = Config::scopedKey(self::CONFIG_PREFIX . '_USERNAME', $connection);

        if ($config->string($apiKeyKey, '') !== '' && $config->string($usernameKey, '') !== '') {
            throw SearchConfigurationException::conflictingCredentials($apiKeyKey, $usernameKey);
        }
    }

    /**
     * Elastic Cloud's own common case is an API key rather than a
     * password. SEARCH_ELASTICSEARCH_API_KEY alone is the encoded value
     * Elasticsearch hands out; with SEARCH_ELASTICSEARCH_API_KEY_ID it is
     * the key's secret and the builder encodes the pair. Either way the
     * credential travels as an Authorization header, never in the URL.
     */
    private static function applyApiKey(ClientBuilder $builder, Config $config, string $connection): void
    {
        self::assertUsableCredentials($config, $connection);

        $apiKey = $config->string(Config::scopedKey(self::CONFIG_PREFIX . '_API_KEY', $connection), '');

        if ($apiKey === '') {
            return;
        }

        $id = $config->string(Config::scopedKey(self::CONFIG_PREFIX . '_API_KEY_ID', $connection), '');

        $builder->setApiKey($apiKey, $id === '' ? null : $id);
    }
}
