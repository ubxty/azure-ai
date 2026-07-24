# Changelog

All notable changes to `ubxty/azure-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.2.6] - 2026-07-24

### Fixed
- AzureManager::performConverseStream now forwards `$onChunk` (and the cache-key context slot) to `performPlatformCall`. Earlier the 8-arg call silently dropped the streaming closure, so the `azure:chat` reply rendered with 0 input / 0 output tokens and no assistant text.

---

## [2.1.2] - 2026-07-14

### Documentation
- README.md L635-636: removed artisan-command rows for `azure:pricing` and `azure:usage` (no such commands shipped).
- README.md L294, L341, getting-started.md L158: `Azure::stream(...)` (fictional) → `Azure::converseStream(...)`.
- README.md L634: `azure:default-model [--show] [--reset] [--connection=...]` → `azure:default-model {model?} {--connection=}`.
- README.md L690: health-check response shape `{success, model_count}` → `{status, platform, message, response_time_ms}`.
- README.md L457-470: kept `conversation()` rows for `model()` / `history()` / `schema()` (these now exist on `ConversationBuilder` per core-ai v2.1.3) and noted the `^2.1.3` requirement.
- README.md L527-530: env-var references `AZURE_OPENAI_PROMPT_CACHE_POINTS` etc. → direct config keys under `core-ai.azure_ai.*`. Published config does NOT bridge those env names; set them in `config/core-ai.php` under the `azure_ai` block.
- README.md L404-410: `systemPrompt` "overrides" claim was inverted — actual behaviour is to prepend explicit system prompt AND keep any system messages in the array.
- README.md L502: response-cache "Shared with bedrock-ai" claim dropped — cache keys are platform-prefixed and don't collide.
- README.md L120-121: misleading "Inherited from core-ai.cache.*" comment replaced with explicit `core-ai.azure_ai.cache.*` lookup.
- README.md L345-346: removed fictional `pricing()` and `usage()` methods on the manager (Azure has no AWS-style PricingService / UsageTracker).
- README.md L361-363: helper methods marked `protected` — not callable from app code.
- README.md L360: `estimateTokens($text)` → `TokenEstimator::estimate($text)` (static).
- README.md L119: typo `Deploment` → `Deployment`.
- README.md L354: `testConnection` returns `deployment_count` for traditional endpoints, `model_count` only for Foundry v1.
- docs/getting-started.md L158: `Azure::stream(...)` → `Azure::converseStream(...)`.
- docs/real-world-patterns.md L260-272: removed `$event->idempotencyKey` (doesn't exist).
- docs/real-world-patterns.md L291-295: `$event->retryAfterSeconds` → `$event->waitSeconds`.
- docs/real-world-patterns.md: kept `->schema(...)` chained usage (now valid via core-ai v2.1.3 — appends JSON-Schema instruction to system prompt).
- docs/caching-strategy.md L13, L165-168: cache prefix `azure_ai` → `azure_openai_ai`.
- docs/caching-strategy.md L52-53: env-var reference dropped (config key only).
- docs/caching-strategy.md L98-100: noted `cache.response_ttl` is not in the published config by default.
- docs/faq.md L41: `config(['core-ai.cache.response_ttl' => 0])` → `config(['core-ai.azure_ai.cache.response_ttl' => 0])`.
- docs/faq.md: cache-prefix mentions in examples updated to `azure_openai_ai-`.

### Notes
- composer dependency bumped: `ubxty/core-ai ^2.1` → `^2.1.3`.
- No PHP source changes in this release — pure doc correction roll.

---

## [2.1.1] - 2026-07-13

### Documentation
- README rewritten from scratch (245 → 767 lines). Adds an `Endpoint flavours` section explaining traditional data-plane vs Microsoft Foundry v1 with the wire-format differences (`max_tokens` vs `max_completion_tokens`, `api-key` vs `Authorization: Bearer`).
- Adds a `Cost Optimisations (v2.1.x)` section summarising all 7 cost levers with `cache_control` injection, response cache, embedding cache, Idempotency-Key, and Retry-After.
- Adds the `AzureManager API` reference table covering inherited core-ai methods + Azure-specific helpers (`embed()`, `testConnection()`, `isConfigured()`, `fetchModels()`, `listModels()`, `client()`, `getCredentialInfo()`).
- Adds the `embed()` reference with supported deployments (`text-embedding-3-small` / `-large` / `ada-002`), endpoint routing table for v1 vs traditional, and `dimensions` semantics for the v3 family.
- Adds a `Configure` section laying out the full `azure_ai.*` config tree.
- Adds a `Multi-key failover` section showing per-key `endpoint + api_key + api_version` configuration.
- Adds `Health check` and `API versions` sections.
- Adds `Multimodal` example (`userWithImage`) for the conversation builder.
- New `/docs/` cookbook (6 files): `getting-started.md`, `endpoint-flavours.md`, `caching-strategy.md`, `embeddings.md`, `real-world-patterns.md`, `faq.md`.

---

## [2.1.0] - 2026-07-13

### Added
- **`cache_control` injection on chat completion bodies.** `AzureClient::converse()` now applies `applyCacheControl()` after `formatMessages()` to attach `cache_control: { type: 'ephemeral' }` markers to the system message and/or last user message's last eligible content part (text or image_url). Azure OpenAI then charges ~10% of the normal input rate for the cached prefix on subsequent calls within the cache TTL. Configured via `core-ai.azure_ai.prompt_caching.points` (env: `AZURE_OPENAI_PROMPT_CACHE_POINTS`).

- **`Idempotency-Key` header on chat completion requests.** `AzureClient::converse()` accepts an optional `?string $idempotencyKey` last parameter and, when present, attaches it as the `Idempotency-Key` header. `AzureManager::performInvoke()` generates a deterministic `sha256(modelId|system|user)` key and passes it through, so a network blip retries as the same request rather than a fresh billing event.

- **`Retry-After` honouring in the canonical `HasRetryLogic`** (added in `ubxty/core-ai` 2.1.1). When `handleErrorResponse()` parses a 429 response, the `Retry-After` header value is captured and consumed by `withRetry()` in preference to the exponential backoff.

- **`AzureManager::embed($deploymentId, array $texts, ?int $dimensions, ?string $user, ?string $connection)`** — batch embedding endpoint using the Azure OpenAI `/embeddings` data-plane (or `/v1/embeddings` for AI Foundry endpoints). Cached per `(deploymentId, dimensions, sha256(text))` for `core-ai.cache.embedding_ttl` seconds (default 7 days).

### Changed
- `AzureClient::converse()` accepts an optional `?string $idempotencyKey = null` last parameter. Existing 5-arg calls keep working — fully backward-compatible.
- `AzureManager::performInvoke()` now generates an Idempotency-Key and propagates it to the underlying converse call. No signature change.

### Notes
- Requires `ubxty/core-ai ^2.1` (specifically 2.1.1, which added the canonical `HasRetryLogic::setPromptCachePoints()` / `setRetryAfterSeconds()` hooks that this release depends on).
- All additions are backward-compatible. No `cache_control` is injected unless `azure_ai.prompt_caching.points` is non-empty, no `Idempotency-Key` is attached unless the caller provides one, and `embed()` is a new method callers opt into.

---

## [2.0.0] - 2026-07-13

### BREAKING CHANGES

- **Consolidated config in `core-ai`.** Azure-specific config previously in `config/azure-ai.php` is now in `config/core-ai.php` under the `azure_ai` key. Host apps that published the old `azure-ai-config` tag must republish via `core-ai-config` and merge their overrides under the new namespace.
- **Config namespace change.** `config('azure-ai.*')` is now `config('core-ai.azure_ai.*')`. Update any direct references in your application code.
- **Publish tag `azure-ai-config` removed.** Only `core-ai-config` is published.
- **Requires `ubxty/core-ai ^2.0`** (companion 2.0.0 release).

### Removed
- `config/azure-ai.php` — moved to core-ai's `core-ai.php` under the `azure_ai` key.

### Changed
- `AzureAiServiceProvider::register()` — `AzureManager` singleton now binds from `config('core-ai.azure_ai', [])` instead of `config('azure-ai', [])`.
- `AzureAiServiceProvider::boot()` — no longer publishes `azure-ai-config`; that tag lives in `ubxty/core-ai`.
- `AzureAiServiceProvider::registerHealthCheckRoute()` — reads from `config('core-ai.azure_ai.health_check', [])`.
- `Client/AzureClient` — `defaults.model` lookup updated to `config('core-ai.azure_ai.defaults.model', '')`.

### Migration from 1.x
1. `composer require ubxty/azure-ai:^2.0` (auto-pulls `ubxty/core-ai ^2.0`)
2. `php artisan vendor:publish --tag=core-ai-config` to publish the new consolidated config
3. Move any customisations from `config/azure-ai.php` into `config/core-ai.php` under the `azure_ai` key
4. In your application code, replace `config('azure-ai.*')` with `config('core-ai.azure_ai.*')`

---

## [1.1.0] - 2026-07-13

### Removed
- **`azure_models` database table** and its migration (`create_azure_models_table`). Models are now defined in config only. The host app no longer runs a migration to install this package.
- **`AzureAiServiceProvider`** no longer publishes or auto-loads migrations (`azure-ai-migrations` tag removed).

### Changed
- **`AzureManager::syncModels()`** — no longer writes to DB. Returns the count of models configured for the given connection (read from `config('azure-ai.models')`). Signature and return type unchanged for BC.
- **`AzureManager::fetchModelsForGrouping()`** — reads from `config('azure-ai.models')` instead of the DB. Empty array still triggers the parent's live `fetchModels()` fallback (cached Azure OpenAI `/openai/models` data-plane call).
- **`AzureManager`** dropped unused `DB`, `Schema`, and `AzureException` imports.

### Added
- **`config/azure-ai.php` `models` block** — keyed by deployment name. Supports flat-indexed and per-connection shapes. Override via `AZURE_OPENAI_MODELS` env (JSON). See config comments for shape examples.

### Migration from <= 1.0.x
1. Drop the table: `php artisan migrate:rollback --path=vendor/ubxty/azure-ai/database/migrations` (or drop manually).
2. Move any previously-synced models into `config/azure-ai.php` under the new `'models'` key.

---

## [1.0.0] - 2026-04-18

### Added

- **Initial public release** — Azure OpenAI integration for Laravel, built on `ubxty/core-ai`.
- **`AzureManager`** — Extends `AbstractAiManager` with `invoke()`, `converse()`, `stream()`, `syncModels()`, `getModelsGrouped()`, `defaultModel()`, and `defaultImageModel()` for Azure OpenAI deployments.
- **`AzureClient`** — HTTP client for the Azure OpenAI REST API supporting chat completions and streaming. Handles token counting, error mapping, deployment listing, and connectivity health checks.
- **`AzureCredentialManager`** — Multi-key round-robin rotation with per-key rate-limit tracking and automatic failover.
- **`azure_models` database table** — Stores synced deployment metadata (model ID, name, provider, connection, context window, max tokens, capabilities, input modalities, lifecycle status). Auto-migrated on `php artisan migrate`.
- **`Azure` facade** — Convenient static access to all `AzureManager` methods.
- **`AzureAiServiceProvider`** — Auto-discovered Laravel service provider that registers the manager singleton, publishes config and migrations, registers commands, and mounts the optional health check route.
- **Artisan commands** — `azure:chat` (interactive streaming chat), `azure:configure` (credential wizard), `azure:models` (deployment listing), `azure:test` (test invocation), `azure:default-model` (set default model in `.env`).
- **Events** — `AzureInvoked`, `AzureKeyRotated`, `AzureRateLimited`.
- **Exceptions** — `AzureException` (extends `AiException`).
- **`HealthCheckController`** — HTTP endpoint for Azure OpenAI connectivity probes (opt-in via config).
- **Multi-key rotation** — Configure multiple API key/endpoint pairs under `connections.default.keys`; the package rotates on rate limits automatically.
- **Cost tracking** — Every invocation returns `cost` (USD), `input_tokens`, `output_tokens`, and `latency_ms`.
- **Provider filtering** — `providers.disabled_providers`, `providers.chat.disabled_providers`, and `providers.image.disabled_providers` config keys for excluding specific model families from commands and pickers.
- **Cost limits** — Configurable `daily` and `monthly` cost limits; exceeding them throws `CostLimitExceededException`.
- **Health check route** — Optional `/health/azure-openai` endpoint with configurable middleware.
