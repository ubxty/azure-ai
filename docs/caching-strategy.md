# Caching Strategy

> Companion to the [README](../README.md). Every cache layer, with cost math.

---

## The cache layers

| Layer | Where | TTL default | Key shape |
|---|---|---|---|
| Deployment listing | `core-ai.azure_ai.cache.models_ttl` | 3600 s | `azure_ai_deployments_{md5(base+key)}` |
| Model catalogue | `core-ai.azure_ai.cache.models_ttl` | 3600 s | `azure_ai_models_{md5(base+key)}` |
| Response cache (v2.1.0+) | `core-ai.azure_ai.cache.response_ttl` (falls back to `core-ai.cache.response_ttl`) | 0 (off) | `azure_openai_ai_response_{sha256(...)}` (built from `cachePrefix()` = `azure_openai_ai`) |
| Embedding cache (v2.1.0+) | `core-ai.azure_ai.cache.embedding_ttl` (falls back to `core-ai.cache.embedding_ttl`) | 604800 s (7 d) | `azure_ai_embeddings_{sha256(...)}` |
| Prompt-cache markers (v2.1.0+) | `core-ai.azure_ai.prompt_caching.points` | upstream-controlled (typically 5-30 min) | injected into chat body |
| Daily / monthly spend ledger | (not a memo — atomic increments under `Cache::lock()`) | persistent | tracks spend |

The package does NOT cache:

- Streaming response chunks.
- Auth state.
- The chat response itself when the request is part of a streaming call.

---

## 1. Prompt-cache markers (`cache_control`)

Azure OpenAI charges full rate for the first call's prefix, then ~10% for any subsequent call's prefix that matches a `cache_control: { type: 'ephemeral' }` anchor. The package injects these markers at the configured anchors; Azure then performs the caching server-side.

### Anchors

| Anchor | Where it lands | When useful |
|---|---|---|
| `system` | Last text or `image_url` part of the system message | Static system prompts reused across calls. The most common case. |
| `last_user` | Last text or `image_url` part of the last user message | Multi-turn replays with a long fixed tail (rare) |

Up to 4 cache breakpoints per conversation (Azure limit). 2 anchors is the typical configuration.

### Configuration

```php
'azure_ai' => [
    'prompt_caching' => [
        'points' => ['system', 'last_user'],
    ],
],
```

Or via env:

```dotenv
AZURE_OPENAI_PROMPT_CACHE_POINTS=system,last_user
```

Empty `points` disables.

> `AZURE_OPENAI_PROMPT_CACHE_POINTS` is **not bridged** by the published `config/core-ai.php`. Set `'points' => ['system', 'last_user']` directly in `config/core-ai.php` under `azure_ai.prompt_caching.*`.

### Caveat: string content

If the system message has plain-string `content` (not a parts array), the client converts it into a single `[{ type: 'text', text: '…' }]` parts array and attaches `cache_control` to that one part. This is invisible to the caller.

### Caveat: tool calls

If a user message contains `tool_use` or `tool_call_id` parts, the `cache_control` is anchored on the last eligible `text` or `image_url` part — `tool_use` blocks are skipped. This is the desired behaviour: tool-call responses are dynamic.

### Cost math (typical)

A `gpt-4o` call with a 600-token static system prompt + 100-token user message + 200-token output:

| Scenario | Input bill |
|---|---|
| No cache | 700 input × $0.005/1k = $0.0035 |
| `cache_control` on `system`, identical system within TTL | 100 fresh × $0.005/1k + 600 cached × 10% × $0.005/1k = $0.0008 |

**~77% off the input-token bill on cached calls.** Output bill is unaffected. The first call pays full rate; savings accumulate on subsequent matching calls.

---

## 2. Response cache (`core-ai.azure_ai.cache.response_ttl`)

`invoke()` / `converse()` memoise results in Laravel's default cache store. The SHA256 hash covers `(model, sys, user, max, temp)`. Bypass the cache by varying any of those.

### When to enable

- Re-ingestion of structured data.
- Templated replies (`"classify this ticket"`, `"summarise this case"`).
- Anything temperature-stable that's idempotent and idempotent on the input.

### When NOT to enable

- Live chat with rolling history (cache miss every turn = wasted compute).
- Calls with timestamps in the user prompt (every call misses).
- Anything where you want freshness > 1 second (use the response cache only when determinism is OK).

### Configuration

```php
// config/core-ai.php
'azure_ai' => [
    'cache' => [
        'response_ttl' => 3600, // 1 hour
    ],
    // ...
],
```

> `cache.response_ttl` is **not defined in the published config by default** — you must publish `core-ai-config` and add the key under `azure_ai.cache.response_ttl` (which reads via `$this->config['cache']['response_ttl']` in the manager). The env var `AZURE_OPENAI_RESPONSE_CACHE_TTL` is **not bridged**; set the config key directly.

### Bypass cache for one call

Use a per-call nonce in the user message (truly any change to the input):

```php
Azure::invoke('gpt-4o', 'You are a careful summariser.', $userMessage . ' #' . uniqid());
```

Or vary `temperature`:

```php
Azure::invoke('gpt-4o', '…', $userMessage, temperature: 0.2000001);
```

---

## 3. Embedding cache (`core-ai.azure_ai.cache.embedding_ttl`)

`AzureManager::embed()` memoise per-text vectors:

```php
$vectors = $manager->embed('text-embedding-3-small', $corpus, dimensions: 512);
```

Cache key: `azure_ai_embeddings_{sha256(deploymentId|dimensions|text)}`. Changing any of those resets. TTL: 7 days default (`core-ai.azure_ai.cache.embedding_ttl`, falls back to `core-ai.cache.embedding_ttl`).

### When to extend

```php
// config/core-ai.php
'azure_ai' => [
    'cache' => [
        'embedding_ttl' => 30 * 86400, // 30 days
    ],
    // ...
],
```

Cache hit = zero Azure spend; cache miss = one embedding call. For a 100k-row corpus, a 30-day TTL means re-runs within the month are free.

---

## 4. Idempotency-Key (v2.1.0+)

`AzureManager::performInvoke()` derives:

```php
$idempotencyKey = hash('sha256', $modelId.'|'.$systemPrompt.'|'.$userMessage);
```

This is passed through to `AzureClient::converse(?string $idempotencyKey = null)`, which adds it as the `Idempotency-Key` HTTP header:

```
POST /openai/deployments/.../chat/completions
Idempotency-Key: <sha256>
```

Azure OpenAI uses the header to deduplicate retries. A network-blip retry returns the same response instead of double-billing.

### Compute the same key in your code

```php
$key = app(AzureManager::class)->idempotencyKey($modelId, $systemPrompt . $userMessage);
// 'azure_openai_ai-<sha256 hash>'  (prefix = cachePrefix() = azure_openai_ai)
```

Note: this is the **prefixed key** returned by `idempotencyKey()` for app-level tracing. The `Idempotency-Key` HTTP header attached inside `performInvoke()` is a *different* hash: `sha256(model|system|user)` (no prefix).

Use it when storing invocation metadata so retries can be traced by key.

---

## 5. `Retry-After` honouring (v2.1.0+)

When `AzureClient::handleErrorResponse()` parses a 429 response, it captures the `Retry-After` header:

```php
if ($status === 429) {
    $retryAfter = $response->header('Retry-After');
    if ($retryAfter !== null) {
        $this->setRetryAfterSeconds((int) $retryAfter);
    }
    throw new RateLimitException("429 Too many requests: {$message}", 429);
}
```

The canonical `HasRetryLogic::withRetry()` (inherited from core-ai 2.1.1) consumes the hint in preference to the exponential backoff:

| Scenario | Effective wait |
|---|---|
| Exponential path (no hint) | 2 s, 4 s, 8 s (per `azure_ai.retry.base_delay`) |
| With `Retry-After` hint | Often 5-30 s — the actual upstream cooldown |

---

## 6. Putting it all together

For a hot path with a 600-token static system prompt + 100-token variable user message + 200-token output × 1M calls/day + 1% network-blip retry rate:

| Lever | Without v2.1.0 | With v2.1.0 (all on) |
|---|---|---|
| Input cost per call (avg 700 tokens) | $0.0035 | $0.0008 + 0 % retry double-billing |
| Network-blip retry | double-billed | deduplicated upstream |
| Rate-limit backoff | 14 s exponential | 5-30 s upstream cooldown |
| Repeated prompt | full rate | 0 cost (response cache) |

For a 100k-row embedding corpus:

| Lever | Impact |
|---|---|
| `core-ai.cache.embedding_ttl = 7 days` | One-shot cost; reruns are free |

Numbers use `gpt-4o`'s published rates ($0.005/1k input, 10% cached rate). Plug your own rates in.

---

## 7. Cache store

The package uses Laravel's default cache store. For production:

```dotenv
CACHE_STORE=redis
```

- Response cache: a few KB per row.
- Embedding cache: vector bytes (e.g. 2 KB for 512-dim embeddings).

Redis is recommended for prod. The file driver works for local dev. APCu is fast but evicted on php-fpm restart — avoid for the embedding cache.

---

## Worked example

Suppose `getModelsGrouped()` runs every 5 minutes (dashboard refresh). With `cache.models_ttl = 3600` (default) and a deployment list of 12 entries (~5 KB JSON), the same cache key is reused for 1 hour = 12 dashboard refreshes from 1 list call.

For 1M dashboard refreshes/day: 24k live list calls (vs 1M) — ~40× reduction.

---

## Caveats and pitfalls

- **Content-hash determinism**: the SHA256 hash covers `$systemPrompt` concatenated with `$userMessage`. Whitespace, casing, and Unicode all matter — `Azure.\n` and `Azure.` hash differently.
- **Cache-busting for prompt iteration**: set `cache.response_ttl = 0` or vary `temperature` between iterations.
- **Memory pressure**: if the response cache holds 100k entries, your Redis or file cache will swell. Set a sensible TTL.
- **Stampede**: under cache miss pressure (e.g. cache expiration under load), multiple concurrent in-flight calls will all hit Azure with the same input. Use Laravel's atomic `Cache::lock()` if this matters for your workload.
