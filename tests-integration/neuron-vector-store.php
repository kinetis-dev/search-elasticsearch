<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use Revolt\EventLoop;

use function Kinetis\Async\concurrently;

// neuron-ai's ElasticsearchVectorStore over the client
// ElasticsearchClientFactory builds: the store's own calls, the response
// objects it reads, and the loop staying live while it waits.

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

$client = ElasticsearchClientFactory::fromConfig(new Config([
    'SEARCH_ELASTICSEARCH_HOST' => getenv('SEARCH_ELASTICSEARCH_HOST') ?: 'http://localhost:9200',
    'SEARCH_ELASTICSEARCH_PLAINTEXT' => 'true',
]));

$index = 'kinetis-verify-neuron-' . bin2hex(random_bytes(4));
$store = new ElasticsearchVectorStore($client, $index, topK: 10);

/**
 * @param list<float> $embedding
 * @param array<string, mixed> $metadata
 */
function document(string $id, string $content, array $embedding, string $sourceType, string $sourceName, array $metadata): Document
{
    return (new Document($content))
        ->setId($id)
        ->setEmbedding($embedding)
        ->setSourceType($sourceType)
        ->setSourceName($sourceName)
        ->setMetadata($metadata);
}

/** @return list<string> */
function ids(iterable $documents): array
{
    $ids = [];
    foreach ($documents as $document) {
        $ids[] = (string) $document->getId();
    }

    return $ids;
}

// Runs $call in a Fiber while a zero-interval repeat timer counts event
// loop turns, and answers the result with the turns counted between the
// call's start and its return inside that Fiber. A call that suspends on
// I/O lets the loop turn at least once; a call that never suspends — a
// blocking handler under the store or the SDK — returns with zero turns,
// however long it took.
$ticks = 0;
$timer = EventLoop::repeat(0, static function () use (&$ticks): void {
    $ticks++;
});

$whileLoopRuns = static function (callable $call) use (&$ticks): array {
    return concurrently([static function () use ($call, &$ticks): array {
        $before = $ticks;
        $result = $call();

        return [$result, $ticks - $before];
    }])[0];
};

try {
    $documents = [
        document('alpha-1', 'Workers keep the application in memory.', [1.0, 0.0, 0.0, 0.0], 'file', 'alpha.md', ['chapter' => 1, 'tags' => ['runtime']]),
        document('alpha-2', 'Fibers suspend on I/O instead of blocking.', [0.0, 1.0, 0.0, 0.0], 'file', 'alpha.md', ['chapter' => 2, 'tags' => ['async', 'io']]),
        document('beta-1', 'Search indexes hold dense vectors.', [0.0, 0.0, 1.0, 0.0], 'url', 'https://example.test/beta', ['chapter' => 7]),
    ];

    [, $addTicks] = $whileLoopRuns(static fn () => $store->addDocuments($documents));
    check('addDocuments() lets the event loop run while it waits on the cluster', $addTicks > 0);

    [$results, $searchTicks] = $whileLoopRuns(
        static fn () => $store->search(new SearchRequest([0.1, 0.95, 0.05, 0.0])),
    );
    check('search() lets the event loop run while it waits on the cluster', $searchTicks > 0);
    echo "loop turns during addDocuments(): {$addTicks}; during search(): {$searchTicks}\n";

    $results = [...$results];
    check('search() returns every stored document', count($results) === 3);
    $top = $results[0] ?? null;
    check('the document nearest the query vector ranks first', $top?->getId() === 'alpha-2');
    check('its content round-trips', $top?->getContent() === 'Fibers suspend on I/O instead of blocking.');
    check('its source round-trips', $top?->getSourceType() === 'file' && $top->getSourceName() === 'alpha.md');
    check('its metadata round-trips', $top?->getMetadata() === ['chapter' => 2, 'tags' => ['async', 'io']]);
    check('it carries a score', is_float($top?->getScore()));

    $store->delete(FilterGroup::allOf(
        Filter::eq('sourceType', 'file'),
        Filter::eq('sourceName', 'alpha.md'),
    ));

    $remaining = ids($store->search(new SearchRequest([1.0, 0.0, 0.0, 0.0])));
    check('delete() by source removes that source\'s documents', array_intersect($remaining, ['alpha-1', 'alpha-2']) === []);
    check('delete() by source keeps the other source\'s documents', $remaining === ['beta-1']);
} catch (Throwable $e) {
    check('the store ran against the cluster: ' . $e::class . ': ' . $e->getMessage(), false);
} finally {
    EventLoop::cancel($timer);

    try {
        $client->indices()->delete(['index' => $index]);
    } catch (Throwable $e) {
        echo "warning: could not delete {$index}: {$e->getMessage()}\n";
    }
}

if ($failures > 0) {
    echo "\n{$failures} check(s) failed.\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
