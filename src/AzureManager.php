<?php

namespace Ubxty\AzureAi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ubxty\AzureAi\Client\AzureClient;
use Ubxty\AzureAi\Client\AzureCredentialManager;
use Ubxty\AzureAi\Events\AzureInvoked;
use Ubxty\AzureAi\Exceptions\AzureException;
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

    public function syncModels(?string $connection = null): int
    {
        $connection ??= $this->config['default'] ?? 'default';
        $models = $this->fetchModels($connection);
        $now = now();

        if (! Schema::hasTable('azure_models')) {
            throw new AzureException(
                'The azure_models table does not exist. Run: php artisan migrate'
            );
        }

        foreach ($models as $model) {
            DB::table('azure_models')->upsert(
                [
                    'model_id' => $model['model_id'],
                    'name' => $model['name'],
                    'provider' => $model['provider'],
                    'connection' => $connection,
                    'context_window' => $model['context_window'],
                    'max_tokens' => $model['max_tokens'],
                    'capabilities' => json_encode($model['capabilities']),
                    'input_modalities' => json_encode($model['input_modalities'] ?? ['text']),
                    'is_active' => $model['is_active'] ? 1 : 0,
                    'lifecycle_status' => $model['is_active'] ? 'ACTIVE' : 'INACTIVE',
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['model_id'],
                ['name', 'provider', 'connection', 'context_window', 'max_tokens', 'capabilities', 'input_modalities', 'is_active', 'lifecycle_status', 'synced_at', 'updated_at']
            );
        }

        return count($models);
    }

    protected function fetchModelsForGrouping(?string $connection): array
    {
        try {
            return DB::table('azure_models')
                ->when($connection, fn ($q) => $q->where('connection', $connection))
                ->orderBy('provider')
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => [
                    'model_id' => $row->model_id,
                    'name' => $row->name,
                    'provider' => $row->provider,
                    'context_window' => $row->context_window,
                    'max_tokens' => $row->max_tokens,
                    'capabilities' => json_decode($row->capabilities, true) ?? [],
                    'input_modalities' => json_decode($row->input_modalities ?? 'null', true) ?? ['text'],
                    'is_active' => (bool) $row->is_active,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
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
