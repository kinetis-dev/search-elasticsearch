<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch\Tests;

use Elastic\Elasticsearch\Client;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What this package owns: the configuration prefix it reads under, the
 * transport reaching ClientBuilder, and the three builder settings that
 * would otherwise undo this project's own guarantees. The origin,
 * deadline, response bound, credential and TLS policy belong to
 * kinetis/search and are proven there.
 */
final class ElasticsearchClientFactoryTest extends TestCase
{
    public function test_builds_a_client_over_the_packages_own_transport(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
        );

        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(BufferedHttpClient::class, $client->getTransport()->getClient());
    }

    public function test_the_configuration_prefix_is_this_engines_own(): void
    {
        self::assertSame('SEARCH_ELASTICSEARCH', ElasticsearchClientFactory::CONFIG_PREFIX);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_ELASTICSEARCH_HOST');
        ElasticsearchClientFactory::fromConfig(new Config([]));
    }

    public function test_a_named_connection_reads_its_own_scoped_keys(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('SEARCH_LOGS_ELASTICSEARCH_HOST');
        ElasticsearchClientFactory::fromConfig(new Config([]), 'logs');
    }

    public function test_unusable_configuration_is_refused_while_building(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ELASTICSEARCH_PLAINTEXT');
        ElasticsearchClientFactory::fromConfig(new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'http://localhost:9200',
        ]));
    }

    public function test_the_configured_origin_is_the_one_node(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://ES.Example:9200']),
        );

        $nodes = $client->getTransport()->getNodePool()->nextNode();

        self::assertSame('https://es.example:9200', (string) $nodes->getUri());
    }

    /**
     * ClientBuilder arms one retry by default, and
     * Elastic\Transport\Transport re-sends on PSR-18's
     * NetworkExceptionInterface — which would replay an index or bulk
     * request whose dispatch outcome is unknown. ClientBuilder::build()
     * cannot express zero, so the built transport carries it.
     */
    public function test_no_retry_is_armed(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
        );

        self::assertSame(0, $client->getTransport()->getRetries());
    }

    public function test_a_request_that_never_completed_is_attempted_once(): void
    {
        $attempts = 0;
        $failing = new BufferedHttpClient(
            new MockHttpClient(static function () use (&$attempts): MockResponse {
                $attempts++;

                return new MockResponse('', ['error' => 'connection reset']);
            }),
        );

        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
            transportDecorator: static fn (): ClientInterface => $failing,
        );

        try {
            $client->index(['index' => 'articles', 'id' => '1', 'body' => ['title' => 'Kinetis']]);
            self::fail('the request should not have completed');
        } catch (NoNodeAvailableException $e) {
            self::assertInstanceOf(SearchNetworkException::class, $e->getPrevious());
        }

        self::assertSame(1, $attempts);
    }

    /**
     * Elasticsearch's default node pool would mark this client's one node
     * dead on the first failure and never revive it, ending every later
     * search through that client — the rest of the worker's life, for one
     * built outside the container and kept.
     */
    public function test_a_failed_request_does_not_take_the_cluster_out_of_service(): void
    {
        $answers = [
            new MockResponse('', ['error' => 'connection reset']),
            new MockResponse(
                '{"_id":"1","result":"created"}',
                ['response_headers' => ['content-type' => 'application/json', 'x-elastic-product' => 'Elasticsearch']],
            ),
        ];
        $failingThenWorking = new BufferedHttpClient(new MockHttpClient($answers));

        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
            transportDecorator: static fn (): ClientInterface => $failingThenWorking,
        );

        try {
            $client->index(['index' => 'articles', 'id' => '1', 'body' => []]);
            self::fail('the first request should not have completed');
        } catch (NoNodeAvailableException) {
        }

        $response = $client->index(['index' => 'articles', 'id' => '1', 'body' => []]);

        self::assertSame('created', $response->asArray()['result']);
    }

    public function test_an_api_key_travels_as_a_header_and_never_in_the_url(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_ELASTICSEARCH_API_KEY' => 'bXlrZXk=',
        ]));

        self::assertSame('ApiKey bXlrZXk=', $client->getTransport()->getHeaders()['Authorization']);
        self::assertSame('', $client->getTransport()->getNodePool()->nextNode()->getUri()->getUserInfo());
    }

    public function test_an_api_key_id_is_encoded_with_its_secret(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_ELASTICSEARCH_API_KEY' => 'secret',
            'SEARCH_ELASTICSEARCH_API_KEY_ID' => 'id',
        ]));

        self::assertSame(
            'ApiKey ' . base64_encode('id:secret'),
            $client->getTransport()->getHeaders()['Authorization'],
        );
    }

    public function test_no_api_key_sends_no_authorization_header_of_its_own(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
        );

        self::assertArrayNotHasKey('Authorization', $client->getTransport()->getHeaders());
    }

    public function test_an_api_key_and_a_username_together_are_refused(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_ELASTICSEARCH_API_KEY');
        ElasticsearchClientFactory::fromConfig(new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_ELASTICSEARCH_API_KEY' => 'bXlrZXk=',
            'SEARCH_ELASTICSEARCH_USERNAME' => 'elastic',
        ]));
    }

    /**
     * Basic credentials stay in the Symfony client's own option.
     * ClientBuilder::setBasicAuthentication() would instead put them in
     * the request URI's userinfo, where a transport error message can
     * quote them.
     */
    public function test_basic_credentials_never_reach_the_node_uri(): void
    {
        $client = ElasticsearchClientFactory::fromConfig(new Config([
            'SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_ELASTICSEARCH_USERNAME' => 'elastic',
            'SEARCH_ELASTICSEARCH_PASSWORD' => 'secret',
        ]));

        self::assertSame('', $client->getTransport()->getNodePool()->nextNode()->getUri()->getUserInfo());

        $adapter = $client->getTransport()->getClient();
        $symfony = new ReflectionProperty($adapter, 'client')->getValue($adapter);

        /** @var array<string, mixed> $options */
        $options = new ReflectionProperty($symfony, 'defaultOptions')->getValue($symfony);

        self::assertSame('elastic:secret', $options['auth_basic']);
    }

    public function test_a_transport_decorator_is_what_the_official_transport_receives(): void
    {
        $decorated = new class implements ClientInterface {
            public ?ClientInterface $wrapped = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called by this test');
            }
        };

        $client = ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
            transportDecorator: static function (ClientInterface $inner) use ($decorated): ClientInterface {
                $decorated->wrapped = $inner;

                return $decorated;
            },
        );

        self::assertSame($decorated, $client->getTransport()->getClient());
        self::assertInstanceOf(BufferedHttpClient::class, $decorated->wrapped);
    }
}
