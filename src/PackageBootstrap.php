<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch;

use Elastic\Elasticsearch\Client;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Search\SearchClient;
use Kinetis\Search\SearchTransport;
use Psr\Container\ContainerInterface;

/**
 * Declared via `extra.kinetis`: with `SEARCH_ELASTICSEARCH_HOST` set,
 * binds {@see Client} and the engine-neutral {@see SearchClient} over it,
 * so a controller or job can constructor-inject either with nothing else
 * to register. Unset means inert.
 *
 * The concrete client is the binding id because
 * Elastic\Elasticsearch\ClientInterface carries only the transport and
 * mode accessors — none of search(), index() or get(), which the final
 * Client picks up from its endpoint traits.
 *
 * Neither binding is shared. Elastic\Transport\Transport keeps the last
 * request and the last response it saw, and Client::setAsync() is a flag
 * any holder can flip, so one client per worker would hold one request's
 * documents and search results until the next search displaced them, and
 * would carry a mode set by whoever touched it first. The transport
 * underneath is what owns the connection pool and is safe to share, so
 * it is built once here and every resolution builds its own client over
 * it. kinetis/search-opensearch binds one shared client instead, because
 * that engine's transport keeps nothing.
 *
 * What that buys is a client no longer-lived than whatever resolved it:
 * a controller or job resolved per request lets its client go with the
 * request. A service that is itself worker-lifetime and injects the
 * client once keeps that one alive, and with it the last request it
 * made — such a service resolves a client per operation instead, or
 * builds one through {@see ElasticsearchClientFactory::over()}.
 *
 * The transport is built here, and the credentials checked with it, so a
 * host that is not one usable origin, a plain-HTTP host without the
 * opt-in, two credentials for one request, or an unusable timeout or
 * response bound fails at registration instead of inside whichever
 * request or queued job happens to search first. Construction opens no
 * connection. The application's own `bootstrap.php` runs after this and
 * still wins on the binding.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('SEARCH_ELASTICSEARCH_HOST', '') === '') {
            return;
        }

        $transport = SearchTransport::fromConfig($config, ElasticsearchClientFactory::CONFIG_PREFIX);
        ElasticsearchClientFactory::assertUsableCredentials($config);

        $app->bind(
            Client::class,
            static fn (): Client => ElasticsearchClientFactory::over($transport, $config),
            shared: false,
        );
        $app->bind(
            SearchClient::class,
            static fn (ContainerInterface $container): SearchClient
                => new ElasticsearchClient($container->get(Client::class)),
            shared: false,
        );
    }
}
