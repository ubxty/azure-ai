# Embeddings — `AzureManager::embed()`

> Companion to the [README](../README.md). Reference for the v2.1.0 batch embeddings method.

---

## Quickstart

```php
use Ubxty\AzureAi\Facades\Azure;

$corpus = [
    'The quick brown fox jumps over the lazy dog.',
    'To be or not to be, that is the question.',
    'All your base are belong to us.',
];

$vectors = Azure::embed('text-embedding-3-small', $corpus, dimensions: 512);
// [
//     [0.0123, -0.0456, 0.0789, …],  // 512 floats
//     [0.0234, -0.0567, 0.0890, …],
//     [0.0345, -0.0678, 0.0901, …],
// ]
```

### Method signature

```php
public function embed(
    string $deploymentId,
    array $texts,
    ?int $dimensions = null,
    ?string $user = null,
    ?string $connection = null,
): array;
```

| Parameter | Description |
|---|---|
| `$deploymentId` | The deployment name — *not* the underlying model name. E.g. `text-embedding-3-small` is the Azure deployment; `text-embedding-3-small` could also be `my-text-emb-3s-deployment` depending on what you named it during deployment creation. |
| `$texts` | Array of strings. Order preserved in the returned array. Empty arrays return empty. |
| `$dimensions` | Optional vector size. `text-embedding-3-small` supports 512 / 256 / 1536 (default). `text-embedding-3-large` supports 256 / 1024 / 3072 (default). `text-embedding-ada-002` returns 1536 only. |
| `$user` | Optional user identifier; sent as the `x-ms-user-agent` header for Azure abuse-detection. |
| `$connection` | Optional named connection. Defaults to `core-ai.azure_ai.default`. |

Return: `array<int, float[]>` — same indices as `$texts`. Each row is the embedding for `$texts[$i]`.

---

## Endpoint routing

The endpoint flavour determines the URL:

| Flavour | Embedding URL |
|---|---|
| Traditional data-plane | `POST {base}/openai/deployments/{deploymentId}/embeddings?api-version={api_version}` |
| Foundry v1 | `POST {base}/embeddings` |

Detection lives in `AzureEndpointResolver::isV1()` (same heuristic as the chat path):

```php
return str_ends_with($endpoint, '/v1') || str_contains($endpoint, '/v1/');
```

The auth header follows the URL flavour:

| Flavour | Header |
|---|---|
| Traditional | `api-key: …` |
| Foundry v1 | `Authorization: Bearer …` |

For the request body:

```json
{
  "input": "The text to embed",
  "dimensions": 512
}
```

`dimensions` is included only if the caller passes a non-null value.

---

## Caching

Per-row memoisations under `core-ai.azure_ai.cache.embedding_ttl` (falls back to `core-ai.cache.embedding_ttl`, default 7 days):

| Field | Description |
|---|---|
| Key prefix | `azure_ai_embeddings_` |
| Key hash | `sha256(deploymentId \| dimensions \| text)` |
| Value | The vector as a `float[]` |

Cache hit = zero Azure spend. Cache miss = one Azure OpenAI embedding HTTP call.

### Extend the TTL

```php
// config/core-ai.php
'azure_ai' => [
    'cache' => [
        'embedding_ttl' => 30 * 86400, // 30 days
    ],
    // ...
],
```

### Invalidate a single row

The hash is content-keyed, so changing the text yields a new key automatically. To force-refresh a specific row:

```php
Cache::forget('azure_ai_embeddings_' . hash('sha256', "text-embedding-3-small|512|Your input text"));
```

### Bulk invalidation

```php
use Illuminate\Support\Facades\Cache;

foreach (Cache::getRedis()->keys('azure_ai_embeddings_*') as $key) {
    Cache::forget($key);
}
```

---

## Supported deployments

| Deployment | Native dim | Allowed dims | Notes |
|---|---|---|---|
| `text-embedding-3-small` | 1536 | 256 / 512 / 1536 | Multilingual. Recommended default. |
| `text-embedding-3-large` | 3072 | 256 / 1024 / 3072 | Highest accuracy; most expensive. |
| `text-embedding-ada-002` | 1536 | 1536 | Legacy, English-only. |

All three support `dimensions` truncation (v3 family does natively; `ada-002` ignores the parameter).

---

## `dimensions` parameter behaviour

`text-embedding-3-small` and `text-embedding-3-large` accept the `dimensions` parameter and return truncated vectors. Specifically:

- `text-embedding-3-small` at 512 → shorter than 1536, slightly less accurate, ~3× cheaper to store and compare.
- `text-embedding-3-large` at 256 → significantly shorter than 3072, useful when storage cost dominates.
- `text-embedding-3-large` at 1024 → sweet spot for most workloads.

When using `dimensions`, **always pass the same value at index time and query time** — comparing a 1536-dim vector against a 512-dim vector in cosine similarity is undefined.

The cache key includes dimensions, so changing the dim at query time produces a different vector (different hash → different key). Don't accidentally mix dimensions in your corpus.

---

## Per-call user header

`$user` becomes the `x-ms-user-agent` header. Use it to attribute spend:

```php
$vectors = $manager->embed('text-embedding-3-small', $corpus, dimensions: 512, user: 'ingest-job-42');
```

This surfaces in Azure's per-user spend dashboards (when configured).

---

## Batch sizing

The package processes each text individually (no internal batching). Per-text caching means re-ingest is free within TTL.

For a 1M-row corpus with average 200-token texts, parallel HTTP at concurrency 50 with p50 latency 200 ms / text achieves full ingestion in ~1 hour.

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

Bus::batch(
    collect($corpus)->chunk(1000)->map(
        fn ($chunk, $i) => new IngestEmbeddingsJob($chunk->all(), $tenantId, $i),
    )->toArray()
)->name('embeddings-v3')->dispatch();

class IngestEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private array $texts, private string $tenantId, private int $batchIdx)
    {
        $this->onQueue('embeddings');
    }

    public function handle(): void
    {
        $vectors = Azure::embed('text-embedding-3-small', $this->texts, dimensions: 512);

        DB::table("embeddings_{$this->tenantId}")->insert(
            array_map(fn ($v, $t) => ['text' => $t, 'vec' => pack('g*', ...$v)], $vectors, $this->texts)
        );
    }
}
```

> Concurrency drivers vary by Laravel version — Laravel 11+ requires `spatie/fork` or `reactivex/rxphp` for the `concurrency` driver. Queue-based parallelism is universally available.

---

## Vector store integration

### Postgres `pgvector`

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE TABLE embeddings (
    id BIGSERIAL PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    text TEXT NOT NULL,
    vec vector(512) NOT NULL,
    UNIQUE (tenant_id, text)
);
CREATE INDEX ON embeddings USING hnsw (vec vector_cosine_ops);
```

```php
$vectors = Azure::embed('text-embedding-3-small', $corpus, dimensions: 512);

DB::table('embeddings')->insert(
    array_map(fn ($v, $t) => [
        'tenant_id' => $tenantId,
        'text'      => $t,
        'vec'       => '[' . implode(',', $v) . ']',
    ], $vectors, $corpus)
);
```

Query:

```php
$query = 'How does foo bar work?';
$vec = Azure::embed('text-embedding-3-small', [$query], dimensions: 512)[0];

$hits = DB::select(
    "SELECT text, vec <=> ? AS distance FROM embeddings
     WHERE tenant_id = ?
     ORDER BY vec <=> ? LIMIT 5",
    ['[' . implode(',', $vec) . ']', $tenantId, '[' . implode(',', $vec) . ']']
);
```

### Pinecone / Qdrant

```php
$vectors = Azure::embed('text-embedding-3-small', $corpus, dimensions: 512);

foreach ($vectors as $i => $v) {
    $client->upsert('my-index', [[
        'id'    => "{$tenantId}:{$i}",
        'values' => $v,
        'metadata' => ['text' => $corpus[$i], 'tenant' => $tenantId],
    ]]);
}
```

---

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| `AzureException("Azure embed HTTP 401: invalid_api_key")` | Wrong key or v1 endpoint without Bearer header | Confirm `auth_mode` + endpoint shape |
| `AzureException("Azure embed HTTP 404: Deployment not found")` | Deployment name typo | Confirm via `azure:models` |
| `AzureException("Azure embed returned no vector for text index N")` | Upstream returned empty `data` | Retry; report to Azure if persists |
| All rows return the same vector | Cache hits on the same text across calls | Verify texts are distinct |
| Timeouts on huge texts | Single text > 8k tokens (TPM limit) | Chunk into smaller pieces |

---

## Multi-region embedding

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'East', 'endpoint' => '…openai.azure.com',     'api_key' => '…'],
            ['label' => 'EU',   'endpoint' => '…eu.openai.azure.com',  'api_key' => '…'],
        ],
    ],
],

$vec = $manager->embed('text-embedding-3-small', $corpus, connection: 'EU');
```

Multi-key rotation handles 429 / 401 the same way as chat calls.
