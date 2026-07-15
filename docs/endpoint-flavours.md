# Endpoint Flavours

> Companion to the [README](../README.md). How the package distinguishes between the Azure OpenAI traditional data-plane and Microsoft Foundry v1 endpoints, and the wire-format differences that follow.

---

## Two URLs, two wire formats

| Aspect | Traditional data-plane | Foundry v1 |
|---|---|---|
| Example | `https://your-resource.openai.azure.com` | `https://resource.services.ai.azure.com/api/projects/p/openai/v1` |
| Auth header | `api-key: …` | `Authorization: Bearer …` |
| Chat URL | `{base}/openai/deployments/{deploymentId}/chat/completions?api-version={v}` | `{base}/chat/completions` |
| Embedding URL | `{base}/openai/deployments/{deploymentId}/embeddings?api-version={v}` | `{base}/embeddings` |
| `model` body field | ✗ (deployment IS the route) | ✓ required in body |
| Max-tokens body field | `max_tokens` | `max_completion_tokens` |
| Listing endpoint | `{base}/openai/deployments?api-version={v}` | not exposed (returns `[]` gracefully) |
| `cache_control` markers | ✓ | ✓ |
| Streaming | ✓ | ✓ |

The detection is in `AzureClient::isV1Endpoint()`:

```php
str_ends_with($normalized, '/v1')
    || str_contains($normalized, '/v1/');
```

For URLs that include a trailing resource path (e.g. someone copied `…/v1/chat/completions` as the endpoint), `AzureClient::normalizeEndpoint()` strips a known suffix:

```
/responses
/chat/completions
/embeddings
/completions
/audio/speech
/audio/transcriptions
/images/generations
```

So the client always operates from the correct base regardless of what the user pasted into `.env`.

---

## Why the detection matters

The traditional data-plane authenticates with an `api-key` header. The Foundry v1 endpoints — built on the Microsoft Foundry control plane — use OpenAI-compatible Bearer auth. Mix them up and you get 401s.

Inside `AzureClient::getAuthHeaders()`:

```php
return $this->isV1Endpoint($endpoint)
    ? ['Authorization' => "Bearer {$apiKey}"]
    : ['api-key'        => $apiKey];
```

Inside `AzureClient::buildChatUrl()`:

```php
return $this->isV1Endpoint($endpoint)
    ? "{$base}/chat/completions"
    : "{$base}/openai/deployments/{$resolvedId}/chat/completions?api-version={$apiVersion}";
```

Both v1 endpoints and traditional deployments share the package's retry logic, but `api_version` is **only** attached to traditional URLs.

---

## Newer-model gotcha (`max_tokens` → `max_completion_tokens`)

The OpenAI Responses API (used by Foundry v1) renamed the parameter. Traditional deployments still accept `max_tokens`. The client switches automatically:

```php
if ($v1) {
    $body['model']                = $resolvedId;
    $body['max_completion_tokens'] = $maxTokens;
} else {
    $body['max_tokens'] = $maxTokens;
}
```

In your app code, just pass `$maxTokens = 256` — the package picks the right key.

---

## `api_version` defaults

| Path | Default | Override |
|---|---|---|
| `AzureCredentialManager::normalizeKey()` | `2024-06-01` | `api_version` per key, or `AZURE_OPENAI_API_VERSION` env |
| `AzureManager::embed()` (lazy) | `2024-10-21` | same |

Either default is fine. Newer revisions enable JSON schema output, structured output, parallel tool calls, and other features. Pin to a specific version for compliance:

```php
['label' => 'Prod', 'endpoint' => '…', 'api_key' => '…', 'api_version' => '2024-10-21'],
```

---

## Data-plane listing: the only true gap

Foundry v1 does not expose `/openai/models` or `/openai/deployments` data-plane routes. The package detects this and returns an empty array (instead of throwing):

```php
if (! $response->successful() && $v1) {
    return [];
}
```

`AzureManager::fetchModels()` then falls back to **synthesising a single entry from the configured default model**:

```php
$defaultModel = $this->config['defaults']['model'] ?? '';
$specs        = ModelSpecResolver::resolve($defaultModel);

return [[
    'model_id'        => $defaultModel,
    'name'            => $defaultModel,
    'context_window'  => $specs['context_window'],
    'max_tokens'      => $specs['max_tokens'],
    'capabilities'    => $specs['capabilities']      ?? ['text'],
    'input_modalities'=> $specs['input_modalities']  ?? ['text'],
    'is_active'       => true,
    'provider'        => 'Azure OpenAI',
]];
```

So `Azure::getModelsGrouped()` on a Foundry v1 endpoint still returns your default deployment — no UI breakage in `azure:models` or `azure:default-model` wizards.

To enrich the catalogue on Foundry v1, populate the `models` block in config:

```php
'models' => [
    'my-gpt-4o-deployment' => [
        'name'             => 'GPT-4o',
        'provider'         => 'OpenAI',
        'context_window'   => 128000,
        'max_tokens'       => 16384,
        'capabilities'     => ['text', 'vision'],
        'input_modalities' => ['text', 'image'],
        'is_active'        => true,
    ],
    'my-text-embedding-3-small-deployment' => [
        'name'             => 'text-embedding-3-small',
        'provider'         => 'OpenAI',
        'context_window'   => 8192,
        'max_tokens'       => 0,
        'capabilities'     => ['embeddings'],
        'input_modalities' => ['text'],
        'is_active'        => true,
    ],
],
```

---

## Mixed deployment

One key may be traditional, another Foundry v1:

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'East-Traditional', 'endpoint' => 'https://east.openai.azure.com',     'api_key' => '…', 'api_version' => '2024-10-21'],
            ['label' => 'Foundry',           'endpoint' => 'https://x.services.ai.azure.com/api/projects/p/openai/v1', 'api_key' => '…'],
        ],
    ],
],
```

The `isV1Endpoint()` check is per-call, so the package routes correctly inside a single multi-key setup.

---

## Testing endpoint behaviour

```php
use Ubxty\AzureAi\Client\AzureClient;
use Ubxty\AzureAi\Client\AzureCredentialManager;

$cm = new AzureCredentialManager([
    ['label' => 'East', 'endpoint' => 'https://east.openai.azure.com', 'api_key' => '…'],
    ['label' => 'West', 'endpoint' => 'https://west.services.ai.azure.com/api/projects/p/openai/v1', 'api_key' => '…'],
]);

$client = new AzureClient($cm);

// Reflection helper or test against `buildChatUrl()` indirectly:
$ref = new ReflectionClass($client);
$method = $ref->getMethod('isV1Endpoint');
$method->setAccessible(true);

var_dump($method->invoke($client, 'https://east.openai.azure.com'));          // false
var_dump($method->invoke($client, 'https://west.services.ai.azure.com/api/projects/p/openai/v1')); // true
```

---

## Common pitfalls

| Symptom | Likely cause | Fix |
|---|---|---|
| 401 with v1 endpoint | Wrong scheme / wrong base | Confirm `endpoint` ends in `/openai/v1` exactly |
| 404 Resource not found | Pasted chat URL into `.env` | Strip `/chat/completions`, etc.; the client normalizes |
| `max_tokens` ignored | v1 endpoint + `max_tokens` | Switched automatically — but the response field shape may differ; switch to `max_completion_tokens` |
| `azure:models` empty | Foundry project without config block | Populate `models` under `azure_ai` |
| Token quota hit in dev | Free-tier S0 cap | Upgrade to PTU or wait for daily cap reset |
| `DeploymentNotFound` | Deployment deleted in portal | Re-create; refresh env |
