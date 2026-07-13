<?php

namespace Ubxty\AzureAi;

use Ubxty\AzureAi\Client\AzureClient;
use Ubxty\AzureAi\Client\AzureCredentialManager;
use Ubxty\AzureAi\Events\AzureInvoked;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Manager\AbstractAiManager;
use Ubxty\CoreAi\Models\ModelSpecResolver;

class AzureManager extends AbstractAiManager
{
    /** @var array<string, AzureClient> */
    protected array $clients = [];

    /**
     * Get the Azure client for the given (or default) connection.
     */
    public function client(?string $connection = null): AzureClient
    {
        $connection ??= $this->config['default'] ?? 'default';

        if (isset($this->clients[$connection])) {
            return $this->clients[$connection];
        }

        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            throw new ConfigurationException("Azure connection [{$connection}] is not configured.");
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            throw new ConfigurationException("No API keys configured for Azure connection [{$connection}].");
        }

        $retryConfig = $this->config['retry'] ?? [];

        $client = new AzureClient(
            new AzureCredentialManager($keys),
            $retryConfig['max_retries'] ?? 3,
            $retryConfig['base_delay'] ?? 2,
        );

        $client->setModelsCacheTtl($this->config['cache']['models_ttl'] ?? 3600);

        $this->clients[$connection] = $client;

        return $client;
    }

    // ─────────────────────────────────────────────────────────
    //  AbstractAiManager implementations
    // ─────────────────────────────────────────────────────────

    protected function performInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?array $pricing,
        ?string $connection
    ): array {
        $startTime = microtime(true);

        $result = $this->client($connection)->converse(
            $modelId,
            [['role' => 'user', 'content' => $userMessage]],
            $systemPrompt,
            $maxTokens,
            $temperature,
        );

        $cost = $this->calculateCost($result['input_tokens'], $result['output_tokens'], $pricing);

        return [
            'response' => $result['response'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'cost' => $cost,
            'latency_ms' => $result['latency_ms'] ?? (int) ((microtime(true) - $startTime) * 1000),
            'status' => 'success',
            'key_used' => $result['key_used'],
            'model_id' => $result['model_id'],
        ];
    }

    protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array {
        return $this->client($connection)->converse(
            $modelId,
            $messages,
            $systemPrompt,
            $maxTokens,
            $temperature,
        );
    }

    protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection
    ): array {
        return $this->client($connection)->converseStream(
            $modelId,
            $messages,
            $onChunk,
            $systemPrompt,
            $maxTokens,
            $temperature,
        );
    }

    public function testConnection(?string $connection = null): array
    {
        return $this->client($connection)->testConnection();
    }

    public function listModels(?string $connection = null): array
    {
        return $this->client($connection)->listDeployments();
    }

    public function fetchModels(?string $connection = null): array
    {
        $models = $this->client($connection)->fetchModels();

        // If listing returned nothing (e.g. AI Foundry endpoint with no /models route),
        // synthesise an entry from the configured default model so the wizard still works.
        if (empty($models)) {
            $defaultModel = $this->config['defaults']['model'] ?? '';

            if ($defaultModel !== '') {
                $specs = ModelSpecResolver::resolve($defaultModel);

                return [[
                    'model_id' => $defaultModel,
                    'name' => $defaultModel,
                    'context_window' => $specs['context_window'],
                    'max_tokens' => $specs['max_tokens'],
                    'capabilities' => $specs['capabilities'] ?? ['text'],
                    'input_modalities' => $specs['input_modalities'] ?? ['text'],
                    'is_active' => true,
                    'provider' => 'Azure OpenAI',
                ]];
            }
        }

        return $models;
    }

    /**
     * Sync models for the given connection.
     *
     * Since 1.1.0, the catalogue is config-driven (see config/azure-ai.php
     * `models` block). This method returns the count of models configured
     * for the connection. {@see AbstractAiManager::getModelsGrouped()}
     * falls back to a live {@see fetchModels()} call when config is empty.
     */
    public function syncModels(?string $connection = null): int
    {
        $connection ??= $this->config['default'] ?? 'default';

        return count($this->getConfiguredModels($connection));
    }

    protected function fetchModelsForGrouping(?string $connection): array
    {
        $models = $this->getConfiguredModels($connection ?? $this->config['default'] ?? 'default');

        return array_values(array_map(
            fn (string $modelId, array $spec): array => [
                'model_id'         => $modelId,
                'name'             => $spec['name'] ?? $modelId,
                'provider'         => $spec['provider'] ?? 'Other',
                'context_window'   => (int) ($spec['context_window'] ?? 0),
                'max_tokens'       => (int) ($spec['max_tokens'] ?? 0),
                'capabilities'     => (array) ($spec['capabilities'] ?? []),
                'input_modalities' => (array) ($spec['input_modalities'] ?? ['text']),
                'is_active'        => (bool) ($spec['is_active'] ?? true),
            ],
            array_keys($models),
            array_values($models),
        ));
    }

    /**
     * Read models for a connection from the config `models` block.
     *
     * Supports two shapes:
     *   1. Per-connection: ['default' => ['my-gpt-4o' => ['provider' => '…']]]
     *   2. Flat-indexed-by-deployment-name (deployment names are unique per
     *      Azure resource): ['my-gpt-4o' => ['provider' => '…']]
     *
     * In the flat shape, an entry with `'connection' => 'other'` is filtered
     * out when querying for `'default'`.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getConfiguredModels(string $connection): array
    {
        $all = $this->config['models'] ?? [];

        if (! is_array($all)) {
            return [];
        }

        // Per-connection shape: top-level key is the connection name.
        if (isset($all[$connection]) && is_array($all[$connection])) {
            return $all[$connection];
        }

        // Flat shape: filter entries whose explicit 'connection' pin does not match.
        return array_filter(
            $all,
            fn ($spec) => is_array($spec)
                && (! isset($spec['connection']) || $spec['connection'] === $connection),
        );
    }

    public function isConfigured(?string $connection = null): bool
    {
        $connection ??= $this->config['default'] ?? 'default';
        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            return false;
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            return false;
        }

        $key = $keys[0];

        return ! empty($key['api_key']) && ! empty($key['endpoint']);
    }

    public function supportsStreaming(?string $connection = null): bool
    {
        return true;
    }

    public function getCredentialInfo(?string $connection = null): array
    {
        try {
            $cm = $this->client($connection)->getCredentialManager();

            return $cm->list();
        } catch (\Exception) {
            return [];
        }
    }

    public function platformName(): string
    {
        return 'Azure OpenAI';
    }

    /**
     * Fire an AzureInvoked event instead of the generic AiInvoked event.
     */
    protected function fireInvokedEvent(array $result): void
    {
        if (function_exists('event')) {
            event(new AzureInvoked(
                modelId: $result['model_id'] ?? 'unknown',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                cost: $result['cost'] ?? 0,
                latencyMs: $result['latency_ms'] ?? 0,
                keyUsed: $result['key_used'] ?? 'unknown',
            ));
        }
    }
}
