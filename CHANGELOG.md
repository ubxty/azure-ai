# Changelog

All notable changes to `ubxty/azure-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
