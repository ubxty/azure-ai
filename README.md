# ubxty/azure-ai

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ubxty/azure-ai.svg?style=flat-square)](https://packagist.org/packages/ubxty/azure-ai)
[![License](https://img.shields.io/packagist/l/ubxty/azure-ai.svg?style=flat-square)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://php.net)
[![Laravel 11|12](https://img.shields.io/badge/Laravel-11%20%7C%2012-ff2d20.svg)](https://laravel.com)

**Azure OpenAI provider for the `ubxty/core-ai` stack.** Microsoft Foundry v1 + traditional data-plane endpoints, multi-key failover, prompt caching, batch embeddings, structured output, and a complete set of CLI tools. One singleton (`AzureManager`), one facade (`Azure`), one configuration block (`core-ai.azure_ai.*`).

---

## Table of contents

1. [Why this package?](#why-this-package)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Endpoint flavours](#endpoint-flavours)
5. [API versions](#api-versions)
6. [Multi-key failover](#multi-key-failover)
7. [Quickstart](#quickstart)
8. [The AzureManager API](#the-azuremanager-api)
9. [`invoke()` / `converse()` / `converseStream()`](#invoke--converse--conversestream)
10. [The `conversation()` builder](#the-conversation-builder)
11. [Cost Optimisations (v2.1.x)](#cost-optimisations-v21x)
12. [`embed()` — batch embeddings](#embed--batch-embeddings)
13. [Model catalogue](#model-catalogue)
14. [Provider filtering](#provider-filtering)
15. [Artisan commands](#artisan-commands)
16. [Events](#events)
17. [Exceptions](#exceptions)
18. [Health check](#health-check)
19. [Documentation](#documentation)
20. [Testing](#testing)
21. [Contributing](#contributing)
22. [Security](#security)
23. [Changelog](#changelog)
24. [License](#license)

---

## Why this package?

`ubxty/azure-ai` is one of two providers built on the `ubxty/core-ai` abstraction (the other is `ubxty/bedrock-ai`). It concentrates everything Azure OpenAI-specific into a single Laravel-friendly package while inheriting:

- the retry trait (`HasRetryLogic`) with exponential backoff + `Retry-After` honouring,
- the response cache and embedding cache under `core-ai.cache.*`,
- the `Idempotency-Key` derivation,
- the conversation builder / token estimator / invocation logger.

So app code stays provider-neutral: `Azure::invoke(...)` and `Bedrock::invoke(...)` return arrays with the same shape; events are aliased to `AiInvoked` / `AiKeyRotated` / `AiRateLimited`; switching providers is a one-facade swap.

What this package adds on top:

- **Two endpoint shapes** in one client — traditional `*.openai.azure.com` data-plane and Microsoft Foundry v1 `*.services.ai.azure.com/.../openai/v1` — both detected automatically from the endpoint URL.
- **`cache_control: { type: 'ephemeral' }`** markers injected at configured anchors (`system`, `last_user`) for Azure prompt caching.
- **Per-deployment multi-key failover** with `Retry-After` honouring and Bearer/api-key auth shimming.
- **Batch embedding ingestion** via the Azure OpenAI `/embeddings` route, with per-text SHA256 memoisation.
- **Five artisan commands** for chat, configuration, model listing, smoke test, and default-model management.

---

## Installation

```bash
composer require ubxty/azure-ai
```

This pulls `ubxty/core-ai ^2.1.3` transitively. The service provider is auto-discovered.

The package needs PHP 8.2+, Laravel 11 or 12, and an Azure subscription with at least one OpenAI model deployment. See [`docs/getting-started.md`](docs/getting-started.md) for the full setup including portal walkthrough and `azure:configure` wizard.

---

## Configuration

The Azure block lives under `core-ai.azure_ai.*` in `config/core-ai.php` (consolidated in core-ai 2.0). Publish only if you want to customise:

```bash
php artisan vendor:publish --tag=core-ai-config
```

The minimal env-var setup:

```dotenv
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=…
AZURE_OPENAI_API_VERSION=2024-10-21
AZURE_OPENAI_DEFAULT_MODEL=gpt-4o
```

For multi-key rotation, see [Multi-key failover](#multi-key-failover).

The full top-level layout:

```php
'azure_ai' => [
    'default'  => 'default',                      // Connection name to use when none is specified
    'connections' => [
        'default' => [
            'keys' => [
                [
                    'label'      => 'Primary',
                    'endpoint'   => env('AZURE_OPENAI_ENDPOINT'),
                    'api_key'    => env('AZURE_OPENAI_API_KEY'),
                    'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
                ],
            ],
        ],
    ],
    'defaults' => [
        'model'       => env('AZURE_OPENAI_DEFAULT_MODEL', ''),
        'image_model' => env('AZURE_OPENAI_DEFAULT_IMAGE_MODEL', ''),
    ],
    'retry' => [
        'max_retries' => 3,
        'base_delay'  => 2,                       // seconds, doubles each retry (capped by Retry-After)
    ],
    'cache' => [
        'models_ttl' => 3600,                     // Deployment catalogue cache (seconds)
        'response_ttl' => 0,                      // Memoised invoke/converse; reads core-ai.azure_ai.cache.response_ttl first, then core-ai.cache.response_ttl
        'embedding_ttl' => 604800,                // Embedding TTL (7 days); reads core-ai.azure_ai.cache.embedding_ttl first, then core-ai.cache.embedding_ttl
    ],
    'limits' => [
        'daily'   => null,                        // Hard cap in USD/day.  null disables.
        'monthly' => null,                        // Hard cap in USD/month.
    ],
    'prompt_caching' => [
        'points' => ['system', 'last_user'],      // Anchors for cache_control injection
    ],
    'providers' => [
        'disabled_providers'    => [],             // Globally disabled (e.g. ['Cohere'])
        'chat'  => ['disabled_providers' => []],
        'image' => ['disabled_providers' => []],
    ],
    'health_check' => [
        'enabled'   => false,
        'path'      => '/health/azure-openai',
        'middleware' => [],
    ],
    'logging' => [
        'channel' => env('LOG_CHANNEL_AI', null),  // null = use Laravel default
    ],
    'models' => [                                // Config-driven deployment catalogue
        'my-gpt-4o-deployment' => [
            'name'             => 'GPT-4o',
            'provider'         => 'OpenAI',
            'context_window'   => 128000,
            'max_tokens'       => 16384,
            'capabilities'     => ['text', 'vision'],
            'input_modalities' => ['text', 'image'],
            'is_active'        => true,
        ],
        // ...
    ],
],
```

---

## Endpoint flavours

The client detects the endpoint flavour from the URL and routes accordingly:

| Flavour | Example URL | Auth header | Body shape | Cache-control marker |
|---|---|---|---|---|
| Traditional data-plane | `https://your-resource.openai.azure.com` | `api-key: …` | `{ "messages": […] }` only | `cache_control` on content parts |
| Microsoft Foundry v1 | `https://resource.services.ai.azure.com/api/projects/p/openai/v1` | `Authorization: Bearer …` | `{ "model": "gpt-4o", "messages": […] }` + `max_completion_tokens` instead of `max_tokens` | `cache_control` on content parts |

The detection strips any trailing resource path:

```
https://resource.services.ai.azure.com/api/projects/p/openai/v1/chat/completions
                       ↓
base  = https://resource.services.ai.azure.com/api/projects/p/openai/v1
        normalised for the data plane to
        https://resource.services.ai.azure.com/api/projects/p
```

`endpoints that look like the v1 flavour` return an empty array from `listDeployments()` / `listModels()` if the AI Foundry project endpoint does not expose those routes — the package gracefully falls back to the configured default model.

See [`docs/endpoint-flavours.md`](docs/endpoint-flavours.md) for the regex detector, header rules, and a worked side-by-side comparison.

---

## API versions

Each key can specify its own `api_version`. The default in `AzureCredentialManager::normalizeKey()` is `2024-06-01`; the lazy default in `AzureManager::embed()` is `2024-10-21`. Set per key:

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'Prod',  'endpoint' => '…', 'api_key' => '…', 'api_version' => '2024-10-21'],
            ['label' => 'Stage', 'endpoint' => '…', 'api_key' => '…', 'api_version' => '2024-06-01'],
        ],
    ],
],
```

Or via env (single-key mode):

```dotenv
AZURE_OPENAI_API_VERSION=2024-10-21
```

v1 (Foundry) endpoints ignore `api_version` — the URL has `/v1/chat/completions` with no `?api-version=` query string.

---

## Multi-key failover

Configure multiple `endpoint + api_key + api_version` tuples under `connections.default.keys`. The package rotates to the next key when the current one hits rate-limit or auth-failure (see [`docs/real-world-patterns.md`](docs/real-world-patterns.md) §4 for failover patterns):

```php
'connections' => [
    'default' => [
        'keys' => [
            [
                'label'       => 'Production',
                'endpoint'    => env('AZURE_OPENAI_ENDPOINT'),
                'api_key'     => env('AZURE_OPENAI_API_KEY'),
                'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            ],
            [
                'label'       => 'DR (different region)',
                'endpoint'    => env('AZURE_OPENAI_ENDPOINT_DR'),
                'api_key'     => env('AZURE_OPENAI_API_KEY_DR'),
                'api_version' => '2024-10-21',
            ],
        ],
    ],
],
```

Rotation triggers:

- 429 with retry budget exhausted on the current key.
- 401 (auth failure).

Total recovery before rotating:

- With `Retry-After` hint captured from the 429 header: usually 5-30 s.
- Without hint: exponential `2 s → 4 s → 8 s` from `retry.base_delay`.

If all keys fail, `AzureRateLimited` fires and `RateLimitException` is thrown after every (`keys[]` × `max_retries`) combination is exhausted.

---

## Quickstart

```bash
composer require ubxty/azure-ai
php artisan vendor:publish --tag=core-ai-config   # only if you want to customise
php artisan azure:configure                       # interactive wizard
php artisan azure:test                            # smoke test
```

In code:

```php
use Ubxty\AzureAi\Facades\Azure;

// Single-turn
$result = Azure::invoke(
    modelId: 'gpt-4o',
    systemPrompt: 'You are a careful summariser.',
    userMessage: 'Q3 revenue was $4.2M, up 18% YoY.',
    maxTokens: 256,
    temperature: 0.2,
);

echo $result['response'];    // string
echo $result['cost'];        // USD, float
echo $result['input_tokens']; // int
```

Multi-turn:

```php
$result = Azure::converse(
    modelId: 'gpt-4o',
    messages: [
        ['role' => 'user',      'content' => 'What is the capital of France?'],
        ['role' => 'assistant', 'content' => 'Paris.'],
        ['role' => 'user',      'content' => 'And Germany?'],
    ],
    systemPrompt: 'You are a geography expert.',
);
```

Streaming:

```php
return Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: function (string $chunk) {
        echo $chunk;
        ob_flush();
    },
);
```

For SSE-backed streaming responses:

```php
return Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: function (string $chunk) {
        echo $chunk;
    },
);
```

By DI:

```php
class FooService
{
    public function __construct(private AzureManager $azure) {}

    public function handle(): array
    {
        return $this->azure->invoke('gpt-4o', '…', '…');
    }
}
```

---

## The AzureManager API

`AzureManager` extends `Ubxty\CoreAi\Manager\AbstractAiManager`. Inherited methods (from the base contract):

| Method | Returns | Purpose |
|---|---|---|
| `invoke($modelId, $systemPrompt, $userMessage, $maxTokens = 4096, $temperature = 0.7, $pricing = null, $connection = null): array` | `['response', 'input_tokens', 'output_tokens', 'total_tokens', 'cost', 'latency_ms', 'status', 'key_used', 'model_id']` | Single-turn call. v2.1.0+ attaches deterministic Idempotency-Key. |
| `converse($modelId, $messages, $systemPrompt = '', $maxTokens = 4096, $temperature = 0.7, $connection = null): array` | Same shape | Multi-turn call. |
| `converseStream($modelId, $messages, callable $onChunk, $systemPrompt = '', $maxTokens = 4096, $temperature = 0.7, $connection = null): array` | Same shape | SSE streaming; chunks yielded via callback. |
| `conversation(string $modelId)` | `ConversationBuilder` | Fluent multi-turn builder (mirror of `prism-php`). |
| `embed($deploymentId, array $texts, ?int $dimensions = null, ?string $user = null, ?string $connection = null): array` | `array<int, float[]>` (NEW v2.1.0) | Batch embedding. Cached per-text for `core-ai.azure_ai.cache.embedding_ttl` (7 days; falls back to `core-ai.cache.embedding_ttl`). |
| `client(?string $connection = null): AzureClient` | The underlying client | Useful for advanced introspection. |
| `isConfigured(?string $connection = null): bool` | bool | True when the connection has at least one key with both `api_key` and `endpoint`. |
| `supportsStreaming(?string $connection = null): bool` | bool | Always true (Azure OpenAI supports streaming across both flavours). |
| `getCredentialInfo(?string $connection = null): array` | array | Label + endpoint + configured per key (no secrets). |
| `listModels(?string $connection = null): array` | Raw `[…]` | Live `data-plane listDeployments()`. |
| `fetchModels(?string $connection = null): array` | Normalised `[…]` | Live data + inferred specs. Falls back to default model if listing returns empty. |
| `getModelsGrouped(?string $connection = null)` | by-provider | Catalogue from config + live fetch fallback. |
| `syncModels(?string $connection = null): int` | Count | No-op since 1.1.0 — returns configured-model count. |
| `testConnection(?string $connection = null): array` | `['success', 'message', 'response_time', 'deployment_count']` (traditional) / `['success', 'message', 'response_time', 'model_count']` (Foundry v1) | Health check; performs a minimal chat call on Foundry. |
| `platformName(): string` | `'Azure OpenAI'` | For event payloads. |

Useful inherited helpers from core-ai (see `ubxty/core-ai` docs for full list):

- `idempotencyKey($modelId, $content)` — returns `azure_openai_ai-<sha256(modelId|content)>`. The Azure cache/key prefix is derived from `cachePrefix()` (which is `strtolower(str_replace(' ', '_', platformName())) . '_ai'`).
- `TokenEstimator::estimate($text)` / `TokenEstimator::estimateMultimodal($messages, $systemPrompt)` — static pre-call token counters in `Ubxty\CoreAi\Support\TokenEstimator`.

> **Note:** the following helpers exist on `AbstractAiManager` but are `protected` — not callable from app code:
>
> - `getConfiguredModels(?string $connection = null)` — read the config `models` block, normalised.
> - `checkCostLimits($modelId, $pricing)` — invoke guard; throws `CostLimitExceededException` if `limits.daily` or `limits.monthly` would be breached.
> - `trackCost($cost)` — increment the spend ledger (uses an atomic lock to avoid races).

---

## `invoke()` / `converse()` / `converseStream()`

### `invoke()`

```php
$response = Azure::invoke(
    modelId: 'gpt-4o',
    systemPrompt: 'You are a helpful assistant.',
    userMessage: 'Explain recursion in simple terms.',
    maxTokens: 512,
    temperature: 0.2,
);

// [
//     'response'      => 'Recursion is when a function calls itself…',
//     'input_tokens'  => 23,
//     'output_tokens' => 187,
//     'total_tokens'  => 210,
//     'cost'          => 0.0014,
//     'latency_ms'    => 1842,
//     'status'        => 'success',
//     'key_used'      => 'Primary',
//     'model_id'      => 'gpt-4o',
// ]
```

### `converse()`

```php
$response = Azure::converse(
    modelId: 'gpt-4o',
    messages: [
        ['role' => 'system',    'content' => 'You are a careful Q&A bot.'],
        ['role' => 'user',      'content' => 'What is 1 + 1?'],
        ['role' => 'assistant', 'content' => '2.'],
        ['role' => 'user',      'content' => 'Are you sure?'],
    ],
    systemPrompt: 'Override the system message…', // optional, overwrites any in $messages
    maxTokens: 256,
    temperature: 0.0,
);
```

Note: `formatMessages()` always prepends the explicit `systemPrompt` first, then iterates the messages array verbatim — system entries from the array are NOT dropped; both are sent to the provider.

### `converseStream()`

```php
$result = Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: function (string $chunk) {
        echo $chunk;
        ob_flush();
    },
);
```

After the stream completes, `$result` contains the full assembled response plus usage/latency.

### Return shape (all three)

| Key | Type | Notes |
|---|---|---|
| `response` | `string` | Concatenated model output. |
| `input_tokens` | `int` | From `usage.prompt_tokens`. |
| `output_tokens` | `int` | From `usage.completion_tokens`. |
| `total_tokens` | `int` | Sum. |
| `cost` | `float` | USD; from `AbstractAiManager::calculateCost()`. |
| `latency_ms` | `int` | Wall-clock from request start to first parsed event for streaming; full duration for non-streaming. |
| `key_used` | `string` | Label of the credential that succeeded. |
| `model_id` | `string` | Resolved model ID (alias expanded). |
| `status` | `'success'` | Constant for `invoke()` only. |

---

## The `conversation()` builder

```php
return Azure::conversation('gpt-4o')
    ->system('Translate the user message to Mandarin.')
    ->user('How do I open the trunk of a 2020 Camry?')
    ->maxTokens(2048)
    ->send();
```

Built-in fluent methods (inherited from `Ubxty\CoreAi\Conversation\ConversationBuilder` — `model()`, `history()`, `schema()`, `userWithDocuments()`, `userWithAttachments()`, `image()`, `stream()`, and `getSchema()` require `ubxty/core-ai ^2.1.3`):

| Method | Purpose |
|---|---|
| `model(string $id)` | Set the deployment / model ID mid-build. (core-ai ^2.1.3) |
| `system(string $prompt)` | Add the system prompt. |
| `user(string $content)` | Append a user turn. |
| `assistant(string $content)` | Append an assistant turn (for replay/seed). |
| `userWithImage(string $content, $image)` | Multimodal image turn. Accepts file path or pre-encoded base64. |
| `userWithDocuments(string $content, array $documents)` | Append a user message with multiple documents (text only on Azure — extracts text content). (core-ai ^2.1.3) |
| `userWithAttachments(string $content, array $attachments)` | Mixed image + document attachments in a single message. (core-ai ^2.1.3) |
| `image(string $source, string $prompt = '')` | Single-image shorthand for `userWithImage()`. (core-ai ^2.1.3) |
| `history(array $messages)` | Re-seed messages from a saved conversation (appends rather than replaces). (core-ai ^2.1.3) |
| `temperature(float $t)` | Set temperature. |
| `maxTokens(int $n)` | Set max output tokens. |
| `schema(array $jsonSchema)` | Append the JSON Schema as a system-prompt instruction (advisory, model-dependent — newer Claude / GPT-4o+ / Nova follow it reliably). (core-ai ^2.1.3) |
| `send(): array` | Run synchronously; returns the standard result shape. |
| `sendStream(callable $onChunk): array` | Run streaming; chunks delivered via callback. |
| `stream(callable $onChunk): array` | Alias for `sendStream()`. (core-ai ^2.1.3) |
| `getSchema(): ?array` | Return the schema set via `schema()`, or `null`. (core-ai ^2.1.3) |
| `estimate(): array` | Pre-call token + cost estimate. |
| `reset()` | Clear all turns. |

### Multimodal example

```php
$result = Azure::conversation('gpt-4o')
    ->system('You extract line items from invoices.')
    ->user('Extract all items.')
    ->userWithImage('Anything I missed?', '/tmp/invoice.jpg')
    ->maxTokens(4096)
    ->send();
```

The image block is sent as `image_url` per the OpenAI vision wire format:

```json
{ "type": "image_url", "image_url": { "url": "data:image/jpeg;base64,…" } }
```

Documents don't have native OpenAI/Azure support — the package extracts text content and embeds it as a `text` part with `[Document: name]` prefix. For binary documents, the bytes are base64-encoded inline.

---

## Cost Optimisations (v2.1.x)

Every lever in `ubxty/core-ai` is available to `ubxty/azure-ai`. Some Azure-specific behaviour:

| Lever | How it works on Azure |
|---|---|
| **Prompt caching** | `cache_control: { type: 'ephemeral' }` injected into chat bodies at `system` and `last_user` anchors. |
| **Response cache** | SHA256-keyed `(model, sys, user, max, temp)` memo. Per-platform — Azure cache key is `azure_openai_ai_response_<sha256>`; `bedrock-ai` uses its own `aws_bedrock_ai_response_<sha256>`. |
| **Embedding cache** | SHA256-keyed `(deployment, dimensions, text)` memo (`azure_ai_embeddings_<sha256>`). 7-day default. |
| **Idempotency-Key** | `Idempotency-Key: <sha256>` header. Auto-attached only on `invoke()`; `converse()` / `converseStream()` don't derive one unless the caller passes `?string $idempotencyKey` to `AzureClient::converse()` directly. Network-blip retries deduplicated server-side. |
| **Retry-After** | Header honoured before exponential backoff. |
| **Token clamp + fits gate** | Inherited from `core-ai.ModelSpecResolver` + `TokenEstimator::estimate()` (for `invoke()`) / `TokenEstimator::estimateMultimodal()` (for `converse()`). |
| **Multi-key failover** | Round-robin keys[] with rotation on 429 / 401. |

See [`docs/caching-strategy.md`](docs/caching-strategy.md) for full reference with cost math.

### Config

The TTL keys live under `azure_ai.*`, not at the top-level `core-ai.cache.*`. Set them in `config/core-ai.php`:

```php
// config/core-ai.php
'azure_ai' => [
    'prompt_caching' => [
        'points' => ['system', 'last_user'],
    ],
    'cache' => [
        'response_ttl'  => 0,        // 0 = disabled; set to e.g. 3600 for memo
        'embedding_ttl' => 604800,   // 7 days
    ],
    // ...
],
```

The package does NOT bridge `AZURE_OPENAI_PROMPT_CACHE_POINTS`, `AZURE_OPENAI_RESPONSE_CACHE_TTL`, or `AZURE_OPENAI_EMBEDDING_CACHE_TTL` (no `env(...)` in the published config) — set the keys above directly. Publish with `php artisan vendor:publish --tag=core-ai-config` only if you want to customise.

### Cost math (typical)

A `gpt-4o` call with a 600-token static system prompt + 100-token user message + 200-token output:

- Without caching: 700 input × $0.005/1k = $0.0035.
- With `cache_control` on system (subsequent calls within 5 min): 100 fresh × $0.005/1k + 600 cached × 10% × $0.005/1k = $0.0008.
- Network-blip retry: deduplicated server-side (same Idempotency-Key).

For embedding: a 1M-row corpus is single-shot cost if `embedding_ttl` ≥ 7 days. Re-runs are free.

---

## `embed()` — batch embeddings

```php
use Ubxty\AzureAi\Facades\Azure;

$corpus = [
    'The quick brown fox jumps over the lazy dog.',
    'To be or not to be, that is the question.',
];

$vectors = Azure::embed('text-embedding-3-small', $corpus, dimensions: 512);
// [
//     [0.0123, -0.0456, …],  // 512-dim
//     [0.0234, -0.0567, …],
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

### Endpoint routing

| Endpoint flavour | URL |
|---|---|
| Traditional | `POST {base}/openai/deployments/{deploymentId}/embeddings?api-version={api_version}` |
| Foundry v1 | `POST {base}/embeddings` |

The detection lives in `AzureManager::isV1EndpointForEmbed()` — same heuristic as the chat path.

### Supported deployments

| Deployment | Native dim | Allowed dims | Notes |
|---|---|---|---|
| `text-embedding-3-small` | 1536 | 512 / 256 / 1536 | Newest, multilingual. |
| `text-embedding-3-large` | 3072 | 256 / 1024 / 3072 | Highest accuracy; most expensive. |
| `text-embedding-ada-002` | 1536 | 1536 | Legacy. |

### Cache

Per-row SHA256: `azure_ai_embeddings_{sha256(deployment|dimensions|text)}`. TTL: `core-ai.azure_ai.cache.embedding_ttl` first (falls back to `core-ai.cache.embedding_ttl`, default 7 days). See [`docs/embeddings.md`](docs/embeddings.md) for batch sizing, invalidation, and integration with vector stores (pgvector, Pinecone, etc.).

---

## Model catalogue

Config-driven since 1.1.0 — no database. See [`docs/getting-started.md`](docs/getting-started.md) §4 for the full block. The package falls back to a live `/openai/models` call when the config block is empty; Foundry v1 endpoints return `[]` for that route and the call returns 0 models gracefully.

`syncModels()` since 1.1.0 is a no-op (returns the configured-model count); the live fetch fallback is `fetchModels()`.

---

## Provider filtering

```php
'azure_ai' => [
    'providers' => [
        'disabled_providers' => [],                  // Globally disabled
        'chat'  => ['disabled_providers' => []],     // Picker/command only
        'image' => ['disabled_providers' => []],     // Picker/command only
    ],
],
```

Filter by Azure's group name (use `az resource list --resource-type Microsoft.CognitiveServices/accounts/deployments -g …` to discover). Example: hide legacy embeddings:

```php
'providers' => [
    'disabled_providers' => ['Ada', 'GPT-3.5'],
],
```

---

## Artisan commands

| Command | Description |
|---|---|
| `azure:configure` | Interactive wizard that writes `AZURE_OPENAI_*` env vars. |
| `azure:chat` | Multi-turn streaming chat in the terminal. |
| `azure:test` | Smoke-test invocation with a chosen deployment. |
| `azure:models` | Browse deployments (config-driven + live fallback). |
| `azure:default-model {model?} {--connection=…}` | Set or inspect the default chat / image deployment in `.env`. |

---

## Events

`AzureInvoked`, `AzureKeyRotated`, `AzureRateLimited` extend the `AiInvoked` / `AiKeyRotated` / `AiRateLimited` events from `ubxty/core-ai` — they share the same payload shape so listeners work on both providers. See [`docs/real-world-patterns.md`](docs/real-world-patterns.md) §10 for a full audit-log listener.

| Event | Fires when |
|---|---|
| `AzureInvoked` | After every successful `invoke()` / `converse()` / stream complete. |
| `AzureKeyRotated` | When a key is exhausted and the next key is selected. |
| `AzureRateLimited` | When all keys fail with 429. |

Payload of `AzureInvoked`:

```php
new AzureInvoked(
    modelId: 'gpt-4o',
    inputTokens: 700,
    outputTokens: 200,
    cost: 0.0021,
    latencyMs: 1842,
    keyUsed: 'Primary',
);
```

---

## Exceptions

| Class | When |
|---|---|
| `Ubxty\AzureAi\Exceptions\AzureException` | Generic Azure error (HTTP, parsing). |
| `Ubxty\CoreAi\Exceptions\RateLimitException` | All keys 429'd. |
| `Ubxty\CoreAi\Exceptions\ConfigurationException` | `connections.default.keys` empty, or `api_key`/`endpoint` missing. |
| `Ubxty\CoreAi\Exceptions\CostLimitExceededException` | `limits.daily` / `limits.monthly` exceeded. |

All four extend `Ubxty\CoreAi\Exceptions\AiException`, which extends `\RuntimeException`.

---

## Health check

```php
'azure_ai' => [
    'health_check' => [
        'enabled'   => true,
        'path'      => '/health/azure-openai',
        'middleware' => ['auth:sanctum'],
    ],
],
```

Returns `200 { "status": "ok", "platform": "Azure OpenAI", "message": "…", "response_time_ms": 1234 }` on success, `503 { "status": "error", "platform": "Azure OpenAI", "message": "…", "response_time_ms": 5678 }` on failure. For Foundry v1 endpoints the check performs a minimal `chat/completions` POST instead of `listDeployments()` (which isn't exposed).

---

## Documentation

The full documentation lives under [`docs/`](docs/):

- [`docs/getting-started.md`](docs/getting-started.md) — Azure subscription + first call.
- [`docs/endpoint-flavours.md`](docs/endpoint-flavours.md) — Traditional vs Foundry v1, with URL detection rules and wire format differences.
- [`docs/caching-strategy.md`](docs/caching-strategy.md) — All 7 cost levers with worked cost math.
- [`docs/embeddings.md`](docs/embeddings.md) — `embed()` reference, batch sizing, vector-store integration.
- [`docs/real-world-patterns.md`](docs/real-world-patterns.md) — 12 patterns distilled from real production usage.
- [`docs/faq.md`](docs/faq.md) — 25+ Q&A entries.

---

## Testing

The package auto-discovers `ubxty/core-ai` (which provides the manager contract) and Laravel's facade helpers. To test without hitting the Azure API:

```php
use Ubxty\AzureAi\Facades\Azure;

it('summarises a case', function () {
    $manager = Mockery::mock(AzureManager::class);
    $manager->shouldReceive('invoke')->andReturn([
        'response' => 'Sample response',
        'input_tokens' => 10,
        'output_tokens' => 5,
        'total_tokens' => 15,
        'cost' => 0.0001,
        'latency_ms' => 100,
        'status' => 'success',
        'key_used' => 'Mock',
        'model_id' => 'gpt-4o',
    ]);

    $this->app->instance(AzureManager::class, $manager);

    $response = $this->postJson('/api/summarise', ['text' => '…']);

    $response->assertOk();
});
```

For queue jobs that use `Azure`, replace the facade root with a fake returning a deterministic result. The package does not ship its own test utilities — rely on Laravel's `Event::fake()` and facade mocking.

---

## Contributing

PRs are welcome. Conventions:

- Match the surrounding PSR-12 style.
- No new public methods without an entry in this README and (if non-trivial) the `docs/` directory.
- Use `Ravdeep Singh <info.ubxty@gmail.com>` as the author for any commit. Do not add automated-tool trailers.
- Run `composer validate` before submitting.

---

## Security

Vulnerabilities: email `info.ubxty@gmail.com`. Do not file public issues for security-relevant bugs.

The package never logs API keys, but endpoint URLs are logged at warning level on rotation. If your endpoint URL embeds sensitive routing data, scrub it before sharing output.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## License

MIT — see [LICENSE](LICENSE).
