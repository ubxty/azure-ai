# ubxty/azure-ai

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ubxty/azure-ai.svg?style=flat-square)](https://packagist.org/packages/ubxty/azure-ai)
[![License](https://img.shields.io/packagist/l/ubxty/azure-ai.svg?style=flat-square)](LICENSE)

**Azure OpenAI integration for Laravel.** Chat, multi-turn conversations, streaming, multi-key rotation, config-driven model catalogue, cost tracking, and powerful CLI tools — all built on `ubxty/core-ai`.

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- An [Azure OpenAI](https://azure.microsoft.com/en-us/products/ai-services/openai-service) resource with at least one deployment

---

## Installation

```bash
composer require ubxty/azure-ai
```

Publish the config file:

```bash
php artisan vendor:publish --tag=azure-ai-config
```

No database migration is required. The model catalogue is config-driven (see the `models` block in `config/azure-ai.php`); if left empty, the package falls back to a live call against the Azure OpenAI `/openai/models` data-plane endpoint (cached via Laravel cache).

---

## Configuration

Add the following to your `.env`:

```env
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-api-key
AZURE_OPENAI_API_VERSION=2024-10-21
AZURE_OPENAI_DEFAULT_MODEL=gpt-4o
```

For multi-key rotation, publish the config and define multiple keys under `connections.default.keys`.

### Model Catalogue

The list of deployments surfaced by `Azure::getModelsGrouped()`, `azure:models`, and the interactive pickers (`azure:test`, `azure:default-model`) is **config-driven** since 1.1.0 — no database table, no migration step.

Configure deployments in `config/azure-ai.php` under the `models` key. Two shapes are supported (flat is recommended; deployment names are unique per Azure resource):

**Flat-indexed by deployment name (recommended):**

```php
// config/azure-ai.php
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
    'my-gpt-4o-mini-deployment' => [
        'name'             => 'GPT-4o mini',
        'provider'         => 'OpenAI',
        'context_window'   => 128000,
        'max_tokens'       => 16384,
        'capabilities'     => ['text'],
        'input_modalities' => ['text'],
        'is_active'        => true,
    ],
],
```

**Per-connection** — useful when multiple Azure resources share the host app:

```php
'models' => [
    'default' => [
        'my-gpt-4o-deployment' => [ /* …spec… */ ],
    ],
    'secondary' => [
        'my-gpt-4o-mini-deployment' => [ /* …spec… */ ],
    ],
],
```

**Override via environment** — useful for staging/production without editing the file:

```bash
# .env
AZURE_OPENAI_MODELS='{"my-gpt-4o-deployment":{"provider":"OpenAI","context_window":128000,"max_tokens":16384,"capabilities":["text","vision"],"input_modalities":["text","image"],"is_active":true}}'
```

**Fallback to live API** — if the `models` block is empty (the default), `Azure::getModelsGrouped()` falls back to a live call against the Azure OpenAI `/openai/models` data-plane endpoint, cached via Laravel cache for `cache.models_ttl` seconds (default 3600). AI Foundry endpoints that don't expose `/models` return `[]` gracefully — define your deployments in config in that case.

**`syncModels()` behaviour change** — since 1.1.0, `Azure::syncModels(?string $connection)` no longer writes to a database. It returns the count of deployments configured for the given connection (read from `config('azure-ai.models')`). The signature and return type are unchanged for BC.

**Available spec keys** (all optional except `model_id`, which is the array key in flat shape):

| Key | Type | Default | Notes |
|---|---|---|---|
| `name` | string | `model_id` | Display name in pickers |
| `provider` | string | `'Other'` | Used for grouping + `disabled_providers` filtering |
| `context_window` | int | `0` | Tokens; shown in `azure:models` |
| `max_tokens` | int | `0` | Max output tokens |
| `capabilities` | string[] | `[]` | e.g. `['text', 'image']` |
| `input_modalities` | string[] | `['text']` | e.g. `['text', 'image']` |
| `is_active` | bool | `true` | Set false to hide from pickers |
| `connection` | string | (none) | Flat shape only — pin this entry to a specific connection |

---

## Usage

### Facade

```php
use Ubxty\AzureAi\Facades\Azure;

// Single-turn invocation
$result = Azure::invoke(
    modelId: 'gpt-4o',
    systemPrompt: 'You are a helpful assistant.',
    userMessage: 'Explain recursion in simple terms.',
);

echo $result['response'];
echo $result['cost']; // in USD
```

### Multi-turn conversation

```php
use Ubxty\AzureAi\Facades\Azure;

$result = Azure::converse(
    modelId: 'gpt-4o',
    messages: [
        ['role' => 'user', 'content' => 'What is the capital of France?'],
        ['role' => 'assistant', 'content' => 'Paris.'],
        ['role' => 'user', 'content' => 'And Germany?'],
    ],
    systemPrompt: 'You are a geography expert.',
);
```

### Streaming

```php
Azure::stream(
    modelId: 'gpt-4o',
    messages: [['role' => 'user', 'content' => 'Tell me a story.']],
    onChunk: function (string $chunk) {
        echo $chunk;
        ob_flush();
    },
);
```

### ConversationBuilder

```php
use Ubxty\AzureAi\Facades\Azure;

Azure::conversation()
    ->model('gpt-4o')
    ->system('You are a helpful assistant.')
    ->user('What is PHP?')
    ->send();
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `azure:chat` | Interactive multi-turn chat session in the terminal |
| `azure:configure` | Interactive wizard to write Azure credentials to `.env` |
| `azure:models` | List available deployments grouped by model family |
| `azure:test` | Run a test invocation and display response, tokens, and cost |
| `azure:default-model` | Set the default chat/image model in `.env` |

---

## Events

| Event | Fired when |
|---|---|
| `AzureInvoked` | After every successful invocation |
| `AzureKeyRotated` | When a rate-limited key is rotated out |
| `AzureRateLimited` | When all keys are rate-limited |

---

## Health Check

Enable the built-in health check endpoint in your config:

```php
'health_check' => [
    'enabled' => true,
    'path' => '/health/azure-openai',
    'middleware' => ['auth:sanctum'],
],
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
