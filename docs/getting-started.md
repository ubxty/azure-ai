# Getting Started with `ubxty/azure-ai`

> Companion to the [README](../README.md). Walks through Azure subscription setup, AOAI resource creation, deployment, and the first `invoke()`.

---

## 1. Prerequisites

- Azure subscription.
- An Azure OpenAI resource (or Microsoft Foundry project).
- PHP 8.2+, Laravel 11 or 12.
- One model **deployed** in the resource (a deployment is a named, billable instantiation of a model).

The minimum RBAC role is `Cognitive Services OpenAI User` (or higher). It grants `read` on the resource and `invoke` on the data-plane deployment.

---

## 2. Install

```bash
composer require ubxty/azure-ai
```

Auto-pulled: `ubxty/core-ai ^2.1.3`. Service provider is auto-discovered.

---

## 3. Create the resource + deployment

### Portal walkthrough

1. Azure Portal → **Create a resource** → search `Azure OpenAI` → **Create**.
2. Pick a Subscription + Resource group + Region (`East US` and `Sweden Central` are the most permissive for newer models).
3. Pick a Pricing tier (Standard S0 is fine for low-throughput; PTUs for production workloads).
4. Click **Create**. After deployment (about 2 minutes), open the resource.
5. Resource → **Keys and Endpoint** → copy **KEY 1** (or KEY 2) and **Endpoint** (looks like `https://your-resource.openai.azure.com`).
6. Resource → **Model deployments** → **Create deployment** → pick a model (`gpt-4o`, `gpt-4o-mini`, `text-embedding-3-small`) → give it a **Deployment name** (this is what you pass as `modelId` to `Azure::invoke`).
7. Resource → **Access control (IAM)** → grant the principal running your app `Cognitive Services OpenAI User`.

### Foundry project walkthrough

1. **Microsoft Foundry** → **New project** → pick subscription + resource group + region.
2. Project → **Deployments** → **Deploy model** → pick a model → name the deployment.
3. Project → **Endpoints** → copy the OpenAI v1 base URL (looks like `https://resource.services.ai.azure.com/api/projects/p/openai/v1`) and the API key.
4. The package detects the `/openai/v1` suffix and uses Bearer auth + `model` in body. **No `api-version` query string** is used on v1 endpoints.

---

## 4. Plug credentials into `.env`

```dotenv
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=…
AZURE_OPENAI_API_VERSION=2024-10-21
AZURE_OPENAI_DEFAULT_MODEL=gpt-4o
```

For Foundry v1:

```dotenv
AZURE_OPENAI_ENDPOINT=https://resource.services.ai.azure.com/api/projects/p/openai/v1
AZURE_OPENAI_API_KEY=…
AZURE_OPENAI_DEFAULT_MODEL=gpt-4o
```

`AZURE_OPENAI_API_VERSION` is optional on Foundry v1 (the URL already pins v1).

---

## 5. Run the interactive wizard

```bash
php artisan azure:configure
```

Walks through:

1. Endpoint URL (paste from portal).
2. API key (one-time input; not echoed).
3. API version (defaults to `2024-10-21`).
4. Default deployment name.

Writes `.env` directly, then auto-runs `php artisan config:clear`.

---

## 6. Smoke-test

```bash
php artisan azure:test
```

The test command:

- Lists deployments (or probes the endpoint with a minimal chat call on Foundry v1).
- Runs a small request against the configured default deployment.
- Reports input/output tokens, latency, and cost.

If it returns `Connection failed`, the most common causes are:

- The endpoint URL is missing the scheme (`https://...`).
- The deployment name doesn't match a live deployment.
- The resource is in a region that doesn't have the model deployed.

---

## 7. First call

```php
use Ubxty\AzureAi\Facades\Azure;

$result = Azure::invoke(
    modelId: 'gpt-4o',                 // the *deployment* name from portal
    systemPrompt: 'You are a careful summariser.',
    userMessage: 'Q3 revenue was $4.2M, up 18% YoY.',
    maxTokens: 256,
    temperature: 0.2,
);

echo $result['response'];
echo $result['cost']; // in USD
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

## 8. Multi-turn

```php
$result = Azure::converse(
    modelId: 'gpt-4o',
    messages: [
        ['role' => 'user',      'content' => 'What is the capital of France?'],
        ['role' => 'assistant', 'content' => 'Paris.'],
        ['role' => 'user',      'content' => 'And Germany?'],
    ],
);
```

---

## 9. Streaming

```php
return Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: fn (string $chunk) => echo $chunk,
);
```

For SSE-backed `StreamedResponse`:

```php
return Azure::converseStream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: fn (string $chunk) => echo $chunk,
);
```

---

## 10. Add a second key for multi-region failover

```dotenv
# Primary (US East)
AZURE_OPENAI_ENDPOINT=https://your-east-resource.openai.azure.com
AZURE_OPENAI_API_KEY=…
AZURE_OPENAI_API_VERSION=2024-10-21

# Secondary (Sweden Central)
AZURE_OPENAI_ENDPOINT_DR=https://your-eu-resource.openai.azure.com
AZURE_OPENAI_API_KEY_DR=…
AZURE_OPENAI_API_VERSION_DR=2024-10-21
```

In `config/core-ai.php` under `azure_ai.connections.default.keys`:

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'East', 'endpoint' => env('AZURE_OPENAI_ENDPOINT'), 'api_key' => env('AZURE_OPENAI_API_KEY'), 'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21')],
            ['label' => 'EU',   'endpoint' => env('AZURE_OPENAI_ENDPOINT_DR'), 'api_key' => env('AZURE_OPENAI_API_KEY_DR'), 'api_version' => env('AZURE_OPENAI_API_VERSION_DR', '2024-10-21')],
        ],
    ],
],
```

The package rotates to the second key on rate-limit or auth-failure.

---

## 11. Multimodal setup

For image analysis (vision-capable deployments only — `gpt-4o`, `gpt-4-turbo`, `gpt-4o-mini`):

```php
$result = Azure::conversation('gpt-4o')
    ->system('You extract line items from invoices.')
    ->user('Extract all items.')
    ->userWithImage('Anything I missed?', '/tmp/invoice.jpg')
    ->maxTokens(4096)
    ->send();
```

The image block is sent as `image_url` per the OpenAI vision wire format. For documents, the package extracts text content and embeds it as a `text` part (Azure doesn't have native document parts).

---

## 12. Where to go next

- [`endpoint-flavours.md`](endpoint-flavours.md) — Traditional vs Foundry v1.
- [`caching-strategy.md`](caching-strategy.md) — All 7 cost levers with worked math.
- [`embeddings.md`](embeddings.md) — Batch embeddings with `embed()`.
- [`real-world-patterns.md`](real-world-patterns.md) — 12 production patterns.
- [`faq.md`](faq.md) — 25+ Q&A.
