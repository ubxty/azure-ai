# ubxty/azure-ai

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ubxty/azure-ai.svg?style=flat-square)](https://packagist.org/packages/ubxty/azure-ai)
[![License](https://img.shields.io/packagist/l/ubxty/azure-ai.svg?style=flat-square)](LICENSE)

**Azure OpenAI integration for Laravel.** Chat, multi-turn conversations, streaming, multi-key rotation, model syncing, cost tracking, and powerful CLI tools — all built on `ubxty/core-ai`.

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

Run the migrations:

```bash
php artisan migrate
```

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
