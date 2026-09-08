<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch\Tests;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\SearchClient;
use Kinetis\SearchElasticsearch\ElasticsearchClient;
use Kinetis\SearchElasticsearch\PackageBootstrap;
use Kinetis\SearchElasticsearch\SingleNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_host_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(Client::class));
        self::assertFalse($app->has(SearchClient::class));
    }

    /**
     * Constructing the client opens no connection, so this asserts the
     * binding without needing a live cluster.
     */
    public function test_a_configured_host_binds_the_engine_client(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        self::assertInstanceOf(Client::class, $app->get(Client::class));
    }

    public function test_a_configured_host_also_binds_the_engine_neutral_client_over_it(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        self::assertInstanceOf(ElasticsearchClient::class, $app->get(SearchClient::class));
    }

    /**
     * Elastic\Transport\Transport keeps the last request and response it
     * saw, so one client per worker would hold one request's documents
     * and results until the next search displaced them. Every resolution
     * gets its own.
     */
    public function test_each_resolution_gets_its_own_client(): void
    {
        $app = $this->registered();

        self::assertNotSame($app->get(Client::class), $app->get(Client::class));
        self::assertNotSame($app->get(SearchClient::class), $app->get(SearchClient::class));
    }

    /**
     * The transport underneath owns the connection pool and is what must
     * survive: a client per resolution is only affordable because none of
     * them opens a connection of its own.
     */
    public function test_every_client_shares_the_one_transport_that_owns_the_pool(): void
    {
        $app = $this->registered();

        self::assertSame(
            $app->get(Client::class)->getTransport()->getClient(),
            $app->get(Client::class)->getTransport()->getClient(),
        );
    }

    /**
     * A fresh client per resolution must still carry everything the
     * factory pins, not just the first one built.
     */
    public function test_every_resolution_carries_the_pinned_retry_policy(): void
    {
        $app = $this->registered();

        $app->get(Client::class);

        self::assertSame(0, $app->get(Client::class)->getTransport()->getRetries());
    }

    public function test_the_binding_is_registered_while_the_package_registers(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
        ]));

        // A binding, not an instance: the client itself is built per
        // resolution. Registration is what guarantees the id is bound
        // before boot(), rather than wired on the first search.
        self::assertTrue($app->has(Client::class));
    }

    public function test_configuration_a_client_cannot_be_built_from_fails_while_registering(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ELASTICSEARCH_PLAINTEXT');
        new PackageBootstrap()->register(new AppScope(), new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'http://localhost:9200',
        ]));
    }

    public function test_two_credentials_for_one_request_fail_while_registering(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ELASTICSEARCH_API_KEY');
        new PackageBootstrap()->register(new AppScope(), new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_ELASTICSEARCH_API_KEY' => 'bXlrZXk=',
            'SEARCH_ELASTICSEARCH_USERNAME' => 'elastic',
        ]));
    }

    private function registered(): AppScope
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        return $app;
    }

    public function test_an_application_can_replace_the_binding_before_boot(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
        ]));

        $own = ClientBuilder::create()
            ->setHosts(['https://elsewhere:9200'])
            ->setNodePool(new SingleNode('https://elsewhere:9200'))
            ->setHttpClient(new BufferedHttpClient(new MockHttpClient()))
            ->build();
        $app->instance(Client::class, $own);
        $app->boot();

        self::assertSame($own, $app->get(Client::class));
    }
}
