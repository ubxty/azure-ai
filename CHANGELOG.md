# Changelog

All notable changes to `ubxty/azure-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
