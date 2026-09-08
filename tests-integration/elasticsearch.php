<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Kinetis\Config\Config;
use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\SearchElasticsearch\ElasticsearchClient;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;

use function Kinetis\Async\concurrently;

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;

    if ($condition) {
        echo "OK   {$label}\n";
    } else {
        echo "FAIL {$label}\n";
        $failures++;
    }
}

$config = new Config([
    'SEARCH_ELASTICSEARCH_HOST' => getenv('SEARCH_ELASTICSEARCH_HOST') ?: 'http://localhost:9200',
    'SEARCH_ELASTICSEARCH_PLAINTEXT' => 'true',
]);
$client = ElasticsearchClientFactory::fromConfig($config);
$search = new ElasticsearchClient($client);

$index = 'kinetis-verify-' . bin2hex(random_bytes(4));

// The official client, through the non-blocking transport.
$client->index([
    'index' => $index,
    'id' => '1',
    'body' => ['title' => 'Kinetis', 'category' => 'framework'],
    'refresh' => 'true',
]);

$got = $client->get(['index' => $index, 'id' => '1']);
check('index() + get() round-trips the document', $got['_source']['title'] === 'Kinetis');

$searchResult = $client->search([
    'index' => $index,
    'body' => ['query' => ['match' => ['category' => 'framework']]],
]);
check('search() finds the indexed document', ($searchResult['hits']['total']['value'] ?? 0) === 1);

$client->index([
    'index' => $index,
    'id' => '2',
    'body' => ['title' => 'Second', 'category' => 'framework'],
    'refresh' => 'true',
]);
$client->delete(['index' => $index, 'id' => '2', 'refresh' => 'true']);

$afterDelete = $client->search([
    'index' => $index,
    'body' => ['query' => ['match_all' => (object) []]],
]);
check('delete() removes exactly the deleted document', ($afterDelete['hits']['total']['value'] ?? -1) === 1);

// The engine-neutral client, against the same live cluster: the five
// calls and the envelopes kinetis/search promises are the same ones this
// engine actually answers with.
$neutralIndex = 'kinetis-verify-neutral-' . bin2hex(random_bytes(4));

$written = $search->index($neutralIndex, 'a', ['title' => 'Neutral', 'category' => 'contract'], refresh: true);
check('SearchClient::index() answers the write envelope', ($written['result'] ?? '') === 'created');

$document = $search->get($neutralIndex, 'a');
check('SearchClient::get() answers the document envelope', ($document['_source']['title'] ?? '') === 'Neutral');
check('SearchClient::get() answers null for a document that is not there', $search->get($neutralIndex, 'nope') === null);

$hits = $search->search($neutralIndex, ['query' => ['match' => ['category' => 'contract']]]);
check('SearchClient::search() answers the shared hits envelope', ($hits['hits']['total']['value'] ?? 0) === 1);

$bulk = $search->bulk([
    BulkOperation::index($neutralIndex, 'b', ['title' => 'Bulked', 'category' => 'contract']),
    BulkOperation::update($neutralIndex, 'a', ['category' => 'updated']),
    BulkOperation::delete($neutralIndex, 'missing'),
], refresh: true);
check('SearchClient::bulk() answers took/errors/items', isset($bulk['took'], $bulk['errors'], $bulk['items']));
check('SearchClient::bulk() reports one item per operation', count($bulk['items']) === 3);
check('SearchClient::bulk() applied the operations that could apply', ($search->get($neutralIndex, 'b')['_source']['title'] ?? '') === 'Bulked');
check('SearchClient::bulk() merged the partial update', ($search->get($neutralIndex, 'a')['_source']['category'] ?? '') === 'updated');
check('deleting an absent document is not a bulk error', $bulk['errors'] === false);

// A rejected operation, on the other hand, is reported inside a 200 —
// the whole reason bulk() answers rather than throws.
$conflicted = $search->bulk([BulkOperation::create($neutralIndex, 'a', ['title' => 'Duplicate'])], refresh: true);
check('SearchClient::bulk() reports a rejected operation inside a 200', $conflicted['errors'] === true);
check('a rejected bulk operation carries its own status', ($conflicted['items'][0]['create']['status'] ?? 0) === 409);

check('SearchClient::delete() answers true for a document it removed', $search->delete($neutralIndex, 'b', refresh: true));
check('SearchClient::delete() answers false for one that was not there', !$search->delete($neutralIndex, 'b'));

$client->indices()->delete(['index' => $index]);
$client->indices()->delete(['index' => $neutralIndex]);

// The response-byte ceiling, against a real cluster and the real
// transport: a mock can prove the guard is installed, only a live
// download proves it aborts one.
$bounded = ElasticsearchClientFactory::fromConfig(new Config([
    'SEARCH_ELASTICSEARCH_HOST' => getenv('SEARCH_ELASTICSEARCH_HOST') ?: 'http://localhost:9200',
    'SEARCH_ELASTICSEARCH_PLAINTEXT' => 'true',
    'SEARCH_ELASTICSEARCH_MAX_RESPONSE_BYTES' => '2048',
]));
$ceilingIndex = 'kinetis-verify-ceiling-' . bin2hex(random_bytes(4));

for ($i = 0; $i < 40; $i++) {
    $client->index(['index' => $ceilingIndex, 'id' => (string) $i, 'body' => ['blob' => str_repeat('x', 4096)]]);
}
$client->indices()->refresh(['index' => $ceilingIndex]);

try {
    $bounded->search(['index' => $ceilingIndex, 'body' => ['size' => 100]]);
    check('a response past SEARCH_ELASTICSEARCH_MAX_RESPONSE_BYTES is abandoned', false);
} catch (NoNodeAvailableException $e) {
    $cause = $e->getPrevious();
    check(
        'a response past SEARCH_ELASTICSEARCH_MAX_RESPONSE_BYTES is abandoned',
        $cause instanceof SearchNetworkException
            && str_contains($cause->getPrevious()?->getMessage() ?? '', 'SEARCH_ELASTICSEARCH_MAX_RESPONSE_BYTES'),
    );
}

$client->indices()->delete(['index' => $ceilingIndex]);

// Non-blocking proof. Each call asks the cluster to wait for a second
// node that will never join, so Elasticsearch holds the response for the
// full 1s timeout and then answers 408 with "timed_out": true, which the
// client raises as a ClientResponseException carrying that status as its
// code. That exception is the only outcome that yields a duration: a
// normal answer and every other client error alike leave the task as an
// exception, which concurrently() rethrows and ends the run.
//
// Every task reports the wait it observed, and the two bounds fail for
// separate reasons: a 408 arriving without the cluster having waited
// returns in milliseconds and breaks the first, and three waits taken in
// turn need three seconds of wall time and break the second.
$wait = static function () use ($client): float {
    $start = microtime(true);

    try {
        $client->cluster()->health(['wait_for_nodes' => '2', 'timeout' => '1s']);
    } catch (ClientResponseException $e) {
        if ($e->getCode() !== 408) {
            throw $e;
        }

        return microtime(true) - $start;
    }

    throw new RuntimeException('cluster health answered without the 408 a timed-out wait carries.');
};

$start = microtime(true);
$waits = concurrently([$wait, $wait, $wait]);
$elapsed = microtime(true) - $start;

check('each cluster call held the response for its own second', min($waits) > 0.9);
check('three one-second cluster waits overlap instead of summing', $elapsed < 2.0);
echo 'waits: ' . implode(', ', array_map(static fn (float $seconds): string => round($seconds, 3) . 's', $waits))
    . '; elapsed: ' . round($elapsed, 3) . "s\n";

if ($failures > 0) {
    echo "\n{$failures} check(s) failed.\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
