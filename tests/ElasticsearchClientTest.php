<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch\Tests;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Config\Config;
use Kinetis\SearchElasticsearch\ElasticsearchClient;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The engine-neutral client over a real Elastic\Elasticsearch\Client,
 * with the cluster mocked at the HTTP boundary: what goes on the wire
 * and what comes back are both the official client's own doing, so these
 * assert the mapping this package adds and nothing else.
 */
final class ElasticsearchClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $sent = [];

    public function test_index_writes_the_document_under_its_id(): void
    {
        $client = $this->clientAnswering(['_id' => '1', '_version' => 1, 'result' => 'created']);

        $result = $client->index('articles', '1', ['title' => 'Kinetis']);

        self::assertSame('created', $result['result']);
        self::assertSame('https://localhost:9200/articles/_doc/1', $this->sent[0]['url']);
        self::assertSame('{"title":"Kinetis"}', $this->sent[0]['body']);
    }

    public function test_index_without_an_id_lets_the_cluster_assign_one(): void
    {
        $client = $this->clientAnswering(['_id' => 'generated', 'result' => 'created']);

        $result = $client->index('articles', null, ['title' => 'Kinetis']);

        self::assertSame('generated', $result['_id']);
        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://localhost:9200/articles/_doc', $this->sent[0]['url']);
    }

    public function test_refresh_is_only_asked_for_when_it_is_wanted(): void
    {
        $this->clientAnswering(['result' => 'created'])->index('articles', '1', ['n' => 1], refresh: true);
        self::assertStringContainsString('refresh=true', $this->sent[0]['url']);

        $this->sent = [];
        $this->clientAnswering(['result' => 'created'])->index('articles', '1', ['n' => 1]);
        self::assertStringNotContainsString('refresh', $this->sent[0]['url']);
    }

    public function test_get_answers_the_whole_envelope(): void
    {
        $client = $this->clientAnswering([
            '_id' => '1',
            '_version' => 3,
            'found' => true,
            '_source' => ['title' => 'Kinetis'],
        ]);

        $document = $client->get('articles', '1');

        self::assertSame('Kinetis', $document['_source']['title']);
        self::assertSame(3, $document['_version']);
    }

    public function test_get_answers_null_for_a_document_that_is_not_there(): void
    {
        $client = $this->clientAnswering(['found' => false], status: 404);

        self::assertNull($client->get('articles', 'missing'));
    }

    public function test_delete_answers_true_when_it_deleted_and_false_when_there_was_nothing(): void
    {
        self::assertTrue($this->clientAnswering(['result' => 'deleted'])->delete('articles', '1'));
        self::assertFalse($this->clientAnswering(['result' => 'not_found'], status: 404)->delete('articles', '1'));
    }

    public function test_search_passes_the_query_body_through_and_answers_the_envelope(): void
    {
        $client = $this->clientAnswering([
            'took' => 3,
            'hits' => ['total' => ['value' => 1], 'hits' => [['_source' => ['title' => 'Kinetis']]]],
        ]);

        $result = $client->search('articles', ['query' => ['match' => ['title' => 'Kinetis']]]);

        self::assertSame(1, $result['hits']['total']['value']);
        self::assertSame('https://localhost:9200/articles/_search', $this->sent[0]['url']);
        self::assertSame('{"query":{"match":{"title":"Kinetis"}}}', $this->sent[0]['body']);
    }

    public function test_bulk_sends_one_ndjson_request_for_every_operation(): void
    {
        $client = $this->clientAnswering(['took' => 4, 'errors' => false, 'items' => []]);

        $client->bulk([
            BulkOperation::index('articles', '1', ['title' => 'Kinetis']),
            BulkOperation::update('articles', '2', ['title' => 'Renamed']),
            BulkOperation::delete('articles', '3'),
        ]);

        self::assertCount(1, $this->sent);
        self::assertSame('https://localhost:9200/_bulk', $this->sent[0]['url']);
        self::assertSame(
            '{"index":{"_index":"articles","_id":"1"}}' . "\n"
                . '{"title":"Kinetis"}' . "\n"
                . '{"update":{"_index":"articles","_id":"2"}}' . "\n"
                . '{"doc":{"title":"Renamed"}}' . "\n"
                . '{"delete":{"_index":"articles","_id":"3"}}' . "\n",
            $this->sent[0]['body'],
        );
    }

    public function test_a_bulk_response_reporting_its_own_failures_is_answered_not_thrown(): void
    {
        $client = $this->clientAnswering([
            'took' => 4,
            'errors' => true,
            'items' => [['create' => ['status' => 409, 'error' => ['type' => 'version_conflict_engine_exception']]]],
        ]);

        $result = $client->bulk([BulkOperation::create('articles', '1', ['n' => 1])]);

        self::assertTrue($result['errors']);
        self::assertSame(409, $result['items'][0]['create']['status']);
    }

    public function test_an_error_status_becomes_one_engine_neutral_failure(): void
    {
        $client = $this->clientAnswering(
            ['error' => ['type' => 'version_conflict_engine_exception'], 'status' => 409],
            status: 409,
        );

        try {
            $client->index('articles', '1', ['title' => 'Kinetis']);
            self::fail('the conflict should have been reported');
        } catch (SearchRequestException $e) {
            self::assertSame(409, $e->status);
            // The engine's own exception stays underneath, where the
            // cluster's error text is.
            self::assertInstanceOf(ClientResponseException::class, $e->getPrevious());
        }
    }

    /**
     * A 404 is only an answer for the two calls that name one document.
     */
    public function test_a_missing_index_on_search_is_a_failure_not_an_empty_result(): void
    {
        $client = $this->clientAnswering(['error' => ['type' => 'index_not_found_exception']], status: 404);

        $this->expectException(SearchRequestException::class);
        $client->search('missing', []);
    }

    /**
     * The same failure both engines' adapters must report: Elastic's
     * transport reports a network failure as NoNodeAvailableException
     * over the exception that actually happened, and SearchClient
     * promises the one underneath.
     */
    public function test_a_request_that_never_completed_is_the_shared_network_failure(): void
    {
        $client = $this->clientOver(new MockHttpClient(new MockResponse('', ['error' => 'connection reset'])));

        $this->expectException(SearchNetworkException::class);
        $client->search('articles', []);
    }

    /**
     * The client verifies the X-Elastic-Product response header, which
     * only reaches it because the adapter passes response headers
     * through untouched.
     */
    public function test_a_response_from_something_other_than_elasticsearch_is_refused(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            '{"_source":{}}',
            ['response_headers' => ['content-type' => 'application/json']],
        ));

        $this->expectExceptionMessage('not Elasticsearch');
        $this->clientOver($transport)->get('articles', '1');
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function clientAnswering(array $answer, int $status = 200): ElasticsearchClient
    {
        return $this->clientOver(new MockHttpClient(function (string $method, string $url, array $options) use ($answer, $status): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return new MockResponse(
                json_encode($answer, JSON_THROW_ON_ERROR),
                [
                    'http_code' => $status,
                    'response_headers' => [
                        'content-type' => 'application/json',
                        'x-elastic-product' => 'Elasticsearch',
                    ],
                ],
            );
        }));
    }

    /**
     * Through the package's own factory, so these run against the client
     * the package actually ships — origin policy, node pool and pinned
     * retries included — rather than a builder chain assembled here.
     */
    private function clientOver(MockHttpClient $transport): ElasticsearchClient
    {
        return new ElasticsearchClient(ElasticsearchClientFactory::fromConfig(
            new Config(['SEARCH_ELASTICSEARCH_HOST' => 'https://localhost:9200']),
            transportDecorator: static fn (): ClientInterface => new BufferedHttpClient($transport),
        ));
    }
}
