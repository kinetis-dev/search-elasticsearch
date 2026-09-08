<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Kinetis\Config\Config;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;

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

/**
 * @param array<string, string> $env
 */
function buildClient(array $env): Client
{
    return ElasticsearchClientFactory::fromConfig(new Config([
        'SEARCH_ELASTICSEARCH_HOST' => getenv('SEARCH_ELASTICSEARCH_SECURE_HOST') ?: 'http://localhost:9200',
        'SEARCH_ELASTICSEARCH_PLAINTEXT' => 'true',
        ...$env,
    ]));
}

$username = getenv('SEARCH_ELASTICSEARCH_USERNAME') ?: 'elastic';
$password = getenv('SEARCH_ELASTICSEARCH_PASSWORD') ?: '';

// No credentials at all -> the security layer rejects the request.
try {
    buildClient([])->info();
    check('an unauthenticated request is rejected', false);
} catch (ClientResponseException $e) {
    check('an unauthenticated request is rejected', $e->getCode() === 401);
}

// A wrong password is rejected the same way, so the first check is about
// the credentials rather than about the request shape.
try {
    buildClient([
        'SEARCH_ELASTICSEARCH_USERNAME' => $username,
        'SEARCH_ELASTICSEARCH_PASSWORD' => $password . '-wrong',
    ])->info();
    check('a wrong password is rejected', false);
} catch (ClientResponseException $e) {
    check('a wrong password is rejected', $e->getCode() === 401);
}

$authenticated = buildClient([
    'SEARCH_ELASTICSEARCH_USERNAME' => $username,
    'SEARCH_ELASTICSEARCH_PASSWORD' => $password,
]);
$info = $authenticated->info();
check('a correctly authenticated request succeeds', isset($info['cluster_name']));

// An API key, the credential Elastic Cloud hands out: created over the
// authenticated client above, then used as the only credential.
$created = $authenticated->security()->createApiKey(['body' => ['name' => 'kinetis-verify']])->asArray();
check('the cluster issued an API key to test with', isset($created['encoded']));

$byApiKey = buildClient(['SEARCH_ELASTICSEARCH_API_KEY' => $created['encoded']])->info();
check('an API key authenticates on its own', isset($byApiKey['cluster_name']));

// The two credential paths together are refused before a request is made,
// rather than leaving header precedence to decide which one is sent.
try {
    buildClient([
        'SEARCH_ELASTICSEARCH_API_KEY' => $created['encoded'],
        'SEARCH_ELASTICSEARCH_USERNAME' => $username,
        'SEARCH_ELASTICSEARCH_PASSWORD' => $password,
    ]);
    check('an API key and a username together are refused', false);
} catch (SearchConfigurationException) {
    check('an API key and a username together are refused', true);
}

$authenticated->security()->invalidateApiKey(['body' => ['ids' => [$created['id']]]]);

if ($failures > 0) {
    echo "\n{$failures} check(s) failed.\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
