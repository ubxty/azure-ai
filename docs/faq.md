# FAQ

> Companion to the [README](../README.md). Short, focused answers.

---

## Setup

**Q: I get `Deployment not found`. What now?**
A: Confirm the deployment name matches what you typed in `.env`. List deployments with `php artisan azure:models`. The deployment name is your model ID for `Azure::invoke()`. Spaces and casing matter.

**Q: My endpoint URL ends in `/openai/v1` but I get 404s.**
A: The v1 detection looks for `/v1` at the end or `/v1/` anywhere in the path. `https://x.services.ai.azure.com/api/projects/p/openai/v1` works; `https://x.services.ai.azure.com/api/projects/p/openai/v1/chat/completions` is auto-normalised.

**Q: I see `api-key: …` on a v1 endpoint and get 401. Why?**
A: The endpoint URL is malformed — either the v1 path is partial or the URL has `?api-version=` appended. Confirm by `php artisan tinker` and inspecting `app(AzureManager::class)->isConfigured()`.

**Q: Does this work with a free Azure trial?**
A: Yes, with severe TPM limits (~250k TPM on S0). Test calls run; production load doesn't. Upgrade to PTU for predictable throughput.

**Q: My `php artisan azure:test` reports `Connection successful! Found 0 deployments.` but I have deployments. Why?**
A: Foundry v1 endpoints don't expose the data-plane listing route. The connection itself is fine. Use `azure:models` with a configured default model, or populate the `models` block in config.

**Q: I get `401: Unauthorized` on a key that worked yesterday.**
A: Keys in Azure Portal → OpenAI resource → Keys and Endpoint are rotated individually or together. Check the "Last refreshed" date. Re-paste the new key into `.env` and `php artisan config:clear`.

---

## Configuration

**Q: Why is my published config different from the docs?**
A: Since core-ai 2.0 the config lives in `config/core-ai.php`. Republish via `php artisan vendor:publish --tag=core-ai-config` and look under the `azure_ai` key.

**Q: Can I have multiple connections (e.g. dev + prod)?**
A: Yes — `azure_ai.connections.default` + `azure_ai.connections.prod`. Switch at call time: `connection: 'prod'`.

**Q: Where do I configure multi-key failover?**
A: `config/core-ai.php` → `azure_ai.connections.default.keys[]`. See [`getting-started.md`](getting-started.md) §10.

**Q: How do I disable the response cache without changing the TTL?**
A: `config(['core-ai.azure_ai.cache.response_ttl' => 0])` at call time, or vary any of `temperature`, `maxTokens`, `systemPrompt`, `userMessage`, `modelId` (changes the SHA hash). Note: `cache.response_ttl` is **not defined in the published config by default** — publish `core-ai-config` and add the key under `azure_ai.cache.response_ttl` first.

---

## Cost

**Q: How much does `gpt-4o` cost?**
A: $5 / 1M input tokens, $15 / 1M output tokens (see Azure pricing pages for the latest). With `cache_control` on `system`, cached input is ~10% of normal rate.

**Q: My billing is much higher than the per-invocation `cost` field. Why?**
A: The `cost` field uses on-demand input/output rates. Provisioned Throughput Units (PTU) are billed separately. Check Azure Cost Management + Billing for reconciled totals.

**Q: Does enabling `cache_control` save money on the FIRST call?**
A: No. The first call's prefix is billed at the full rate. Savings start on the second call within the cache TTL window (5-30 minutes).

**Q: Where are the Azure Pricing API numbers cached?**
A: Inherited from bedrock-ai → `core-ai.bedrock.cache.pricing_ttl` (Bedrock only). For Azure, the per-call `cost` is computed from the live `usage` field of the response, not from a price API.

---

## Errors

**Q: I'm getting `429` even though I'm not at any quota.**
A: You may be at the model-level TPM (tokens per minute) cap. Check Azure Portal → OpenAI resource → "Quotas" tab. PTU bypasses these caps.

**Q: Why does streaming fail in production but not locally?**
A: Reverse proxies (Nginx, Cloudflare) buffer responses by default. SSE requires `X-Accel-Buffering: no`. Streaming also requires `Content-Type: text/event-stream` and chunked encoding passthrough.

**Q: What does `404 Resource not found` mean?**
A: The endpoint URL is wrong. Verify via `azure:configure` or in the Azure Portal → OpenAI resource → Keys and Endpoint → "Endpoint".

**Q: Why do I see `content_policy_violation`?**
A: Azure content safety filters tripped. The package returns `BadRequest` with the filter category in the error message. Adjust your prompt or apply for a content-filter exemption via Microsoft's enterprise support.

**Q: Why are my tool-calls returning `BadRequest`?**
A: Newer models (gpt-4o, gpt-4o-mini via v1) require the `tools` block to be in the request body, not in a per-message `tools` field. Adjust your prompt construction. (The current builder doesn't expose tool-call flow; in v2.1.x tool support lives at the underlying client.)

---

## Performance

**Q: My time-to-first-token for streaming is 3 seconds. Why?**
A: Latency depends on prompt size and region. On Foundry v1 in `Sweden Central`, expect 800-1500 ms TTFC for `gpt-4o`. On traditional data-plane in `East US`, expect 400-900 ms. Check your key region matches your model region.

**Q: Can I embed images directly into the chat?**
A: Yes for vision models (`gpt-4o`, `gpt-4-turbo`, `gpt-4o-mini`). Use `userWithImage($content, $path)` on the conversation builder.

**Q: How many concurrent streams can I run?**
A: Default TPM cap on S0 is 250k. A 4k-token-output `gpt-4o` stream is ~60k output tokens if maxTokens is 4096. Realistic concurrency is 4-8 streams per connection on S0. PTU removes the cap.

---

## Embeddings

**Q: `text-embedding-3-small` with `dimensions=512` returns the same vectors as `dimensions=1536`?**
A: They differ. v3-family models truncate the vector when `dimensions < native`. Semantic similarity scores compare within a dimension cohort. Always match dimensions index-side and query-side.

**Q: Why does `embed()` return [] for an empty array?**
A: That's the intended behaviour. Empty arrays produce empty arrays — skip the upstream call entirely.

**Q: Can I cache embeddings indefinitely?**
A: Yes. Set `core-ai.azure_ai.cache.embedding_ttl` (or the fallback `core-ai.cache.embedding_ttl`) to a long value. The cache key is content-hashed; you don't need to invalidate when text changes (a different hash = a different key).

**Q: `text-embedding-ada-002` ignores `dimensions`?**
A: Yes — `ada-002` always returns 1536 dimensions. The parameter is silently dropped on that model.

---

## Operations

**Q: How do I monitor spend in real time?**
A: Listen on `AzureInvoked`, aggregate `cost` field, push to your metrics backend. Match against Azure Cost Management + Billing for monthly reconciliation.

**Q: Can I rotate API keys without re-deploying?**
A: Yes. Store keys in Azure Key Vault and pull via `secret()` at boot. Config is loaded once at boot, so re-deploy is still needed for config changes — for hot rotation, rotate the active key in Azure Portal (the package picks up new keys on the next request after config:cache:clear).

**Q: Where's the `invoke()` event payload?**
A: See [`real-world-patterns.md`](real-world-patterns.md) §10. The event is `AzureInvoked` (or the alias `AiInvoked` from core-ai).

---

## Compatibility

**Q: Can I use this alongside `openai-php/laravel`?**
A: Yes — different namespaces (`Ubxty\AzureAi\…` vs `OpenAI\Laravel\…`). No conflict, but each package wraps its own HTTP client. Pick one as the canonical entry point and stick with it.

**Q: Does this work with `gpt-5` or other unannounced models?**
A: Yes, as soon as they're available in your subscription. Add the deployment and use the deployment name as the `modelId`. If `ModelSpecResolver` doesn't know the model, it returns sensible defaults (context_window 8192, max_tokens 4096).

**Q: My friend uses `prism-php/prism`. Is this similar?**
A: Functionally yes — fluent builder, multi-provider, structured output. `ubxty/azure-ai` extends `ubxty/core-ai` (so the same patterns apply to `ubxty/bedrock-ai`). The hosting pattern (multi-key failover with retry-after) is `core-ai` specific.

---

## Azure OpenAI vs OpenAI.com

**Q: Why use this instead of `openai-php/laravel`?**
A: When your Azure deployment has data-residency requirements (HIPAA / GDPR data stays in a specific region), when you want PTU commitments, or when you're already committed to the Azure ecosystem.

**Q: Can I call OpenAI.com directly through this package?**
A: No — `ubxty/azure-ai` is Azure-specific (the auth header is `api-key`, the URL has `/openai/deployments/…`). For direct OpenAI calls, use a different package.
