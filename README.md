<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/search-elasticsearch</strong>
  <br>
  <strong>Non-blocking Elasticsearch client construction for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/search-elasticsearch"><img src="https://img.shields.io/packagist/v/kinetis/search-elasticsearch?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/search-elasticsearch"><img src="https://img.shields.io/packagist/dt/kinetis/search-elasticsearch" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/search-elasticsearch"><img src="https://img.shields.io/packagist/php-v/kinetis/search-elasticsearch" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/search-elasticsearch"><img src="https://img.shields.io/packagist/l/kinetis/search-elasticsearch" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Builds a real `Elastic\Elasticsearch\Client` (from
`elasticsearch/elasticsearch`) through Elasticsearch's own `ClientBuilder`,
over [`kinetis/search`](https://github.com/kinetis-dev/search)'s
Revolt-native HTTP transport instead of the blocking cURL client the
builder would otherwise discover. The returned object is the real,
un-wrapped client — nothing Kinetis-specific sits on top of it.

Each call is one wire attempt against one origin, bounded by one deadline
and one response size, following no redirect and **making no retry**:
`Elastic\Transport\Transport` re-sends on a PSR-18 network failure, which
would replay an `index` or `bulk` whose dispatch outcome is unknown, so
retries are pinned to zero. Every status Elasticsearch answers with stays
the official client's to map.

```php
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;

$client = ElasticsearchClientFactory::fromConfig($config);

$client->index(['index' => 'articles', 'id' => '1', 'body' => ['title' => 'Kinetis']]);
$results = $client->search(['index' => 'articles', 'body' => ['query' => ['match' => ['title' => 'Kinetis']]]]);
```

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `Elastic\Elasticsearch\Client` when
  `SEARCH_ELASTICSEARCH_HOST` is set, and one for
  [`kinetis/search`](https://github.com/kinetis-dev/search)'s
  engine-neutral `SearchClient` over it. Unset means the package binds
  nothing. Neither is shared: `Elastic\Transport\Transport` keeps the
  last request and response it saw, so one client per worker would hold
  one request's documents until the next search displaced them. The
  transport that owns the connection pool is built once during
  registration — opening no connection, so unusable configuration fails
  at boot rather than on the first search — and each resolution gets its
  own client over it. An application's own `bootstrap.php` runs
  afterwards and can replace either binding.

Nothing else. Named connections stay explicit application wiring.

## Configuration

```
SEARCH_ELASTICSEARCH_HOST=https://localhost:9200
```

The host, deadline, response bound, Basic credentials and TLS switch are
[`kinetis/search`](https://github.com/kinetis-dev/search)'s, spelled with
this engine's prefix. What this package adds:

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_ELASTICSEARCH_API_KEY` | — | An API key — the `encoded` value the cluster hands out, or the secret alongside the id below. Travels as an `Authorization: ApiKey` header, never in the URL. |
| `SEARCH_ELASTICSEARCH_API_KEY_ID` | — | The key's id, when it is held separately from its secret. |

An API key and `SEARCH_ELASTICSEARCH_USERNAME` together are refused while
the client is built: they are two credentials for one request.

Every key is scoped — `SEARCH_ELASTICSEARCH_HOST` + `logs` →
`SEARCH_LOGS_ELASTICSEARCH_HOST`. Full reference:
[kinetis.dev/docs/search.html](https://kinetis.dev/docs/search.html).

## Matching the client to your cluster

`elasticsearch/elasticsearch` `^8.19 || ^9.0` is accepted, and the major
must match the cluster's: a 9.x client sends `compatible-with=9`, which
an 8.x cluster rejects. Composer resolves to the newest allowed release,
so an Elasticsearch 8 cluster needs the constraint pinned in your own
application:

```sh
composer require elasticsearch/elasticsearch:^8.19
```

## Installation

```sh
composer require kinetis/search-elasticsearch
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
and [`kinetis/search`](https://github.com/kinetis-dev/search).
Full documentation:
[kinetis.dev/docs/search-elasticsearch.html](https://kinetis.dev/docs/search-elasticsearch.html).

## License

MIT — see [LICENSE](LICENSE).
