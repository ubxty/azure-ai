<?php

namespace Ubxty\AzureAi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ubxty\AzureAi\Client\AzureClient;
use Ubxty\AzureAi\Client\AzureCredentialManager;
use Ubxty\AzureAi\Client\AzureEndpointResolver;
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
        $client->setPromptCachePoints($this->promptCachePoints());

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
        return $this->performPlatformCall(
            'invoke', $modelId, $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
            $maxTokens, $temperature, $connection,
        );
    }

    protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null,
    ): array {
        return $this->performPlatformCall(
            'converse', $modelId, $systemPrompt, $messages,
            $maxTokens, $temperature, $connection, $cachePointsOverride,
        );
    }

    protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null,
    ): array {
        return $this->performPlatformCall(
            'converseStream', $modelId, $systemPrompt, $messages,
            $maxTokens, $temperature, $connection, $cachePointsOverride,
        );
    }

    // ─────────────────────────────────────────────────────────
    //  v2.2 platform-hook implementation
    //  Drives the v2.2 AbstractLLMClient (AzureClient extends
    //  core-ai/Standards/OpenAI/OpenAIClient) end-to-end through the
    //  LLMResult DTO. Translates the LLMResult shape back into the
    //  AbstractAiManager result envelope that BedrockManager /
    //  legacy callers expect.
    // ─────────────────────────────────────────────────────────

    protected function usePlatformHook(): bool
    {
        return true;
    }

    protected function platformInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?string $idempotencyKey,
    ): array {
        $startTime = microtime(true);

        $result = $this->client($connection)->converse(
            $modelId,
            [['role' => 'user', 'content' => $userMessage]],
            $systemPrompt,
            $maxTokens,
            $temperature,
            $idempotencyKey,
        );

        return [
            'response' => (string) ($result['response'] ?? ''),
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'total_tokens' => (int) ($result['total_tokens'] ?? 0),
            'cost' => $this->calculateCost((int) ($result['input_tokens'] ?? 0), (int) ($result['output_tokens'] ?? 0), null),
            'latency_ms' => (int) ($result['latency_ms'] ?? (int) ((microtime(true) - $startTime) * 1000)),
            'status' => 'success',
            'key_used' => (string) ($result['key_used'] ?? 'unknown'),
            'model_id' => (string) ($result['model_id'] ?? $modelId),
        ];
    }

    protected function platformConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride,
        ?string $idempotencyKey,
    ): array {
        $startTime = microtime(true);

        $result = $this->client($connection)->converse(
            $modelId, $messages, $systemPrompt, $maxTokens, $temperature, $idempotencyKey,
        );

        return [
            'response' => (string) ($result['response'] ?? ''),
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'total_tokens' => (int) ($result['total_tokens'] ?? 0),
            'stop_reason' => (string) ($result['stop_reason'] ?? 'stop'),
            'latency_ms' => (int) ($result['latency_ms'] ?? (int) ((microtime(true) - $startTime) * 1000)),
            'model_id' => (string) ($result['model_id'] ?? $modelId),
            'key_used' => (string) ($result['key_used'] ?? 'unknown'),
        ];
    }

    protected function platformConverseStream(
        string $modelId,
        array $messages,
        ?callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride,
        ?string $idempotencyKey,
    ): array {
        $startTime = microtime(true);

        // Translate the AbstractAiManager `$onChunk($text, $isFinal)`
        // signature to the OpenAIClient / parent `converseStream`
        // `$onDelta($text)` signature.
        $deltaDelegate = function (string $delta) use (&$onChunk): void {
            if (is_callable($onChunk)) {
                $onChunk($delta, false);
            }
        };

        $result = $this->client($connection)->converseStream(
            $modelId, $messages, $deltaDelegate, $systemPrompt, $maxTokens, $temperature, $idempotencyKey,
        );

        if (is_callable($onChunk)) {
            $onChunk('', true);
        }

        return [
            'response' => (string) ($result['response'] ?? ''),
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'total_tokens' => (int) ($result['total_tokens'] ?? 0),
            'latency_ms' => (int) ($result['latency_ms'] ?? (int) ((microtime(true) - $startTime) * 1000)),
            'model_id' => (string) ($result['model_id'] ?? $modelId),
            'key_used' => (string) ($result['key_used'] ?? 'unknown'),
        ];
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
     * Since 1.1.0, the catalogue is config-driven (see core-ai
     * `azure_ai.models` block in config/core-ai.php since 2.0.0). This
     * method returns the count of models configured
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

    protected function providerDefault(): string
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

    // ─────────────────────────────────────────────────────────
    //  v2.1.0 — prompt-cache config + embeddings
    // ─────────────────────────────────────────────────────────

    /**
     * Read the configured prompt-cache checkpoint anchors
     * (`core-ai.azure_ai.prompt_caching.points`), filtered to the supported set.
     *
     * @return string[]
     */
    protected function promptCachePoints(): array
    {
        $configured = $this->config['prompt_caching']['points'] ?? [];

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $configured),
            fn (string $p) => in_array($p, ['system', 'last_user'], true),
        ));
    }

    /**
     * Generate embeddings for a batch of texts using the Azure OpenAI
     * /embeddings endpoint. Cached per `(modelName, dimensions, tenantId, sha256(text))`
     * for `core-ai.cache.embedding_ttl` seconds (default 7 days).
     *
     * Cache key includes the resolved underlying model name (not just the
     * deployment ID) so that two deployments pointing at different models —
     * or the same deployment re-pointed at a different model — never share
     * a stale vector. Tenant scoping via `?int $tenantId` namespaces the
     * cache so multi-tenant deployments cannot collide.
     *
     * @param  string[]  $texts
     * @param  string  $user  Optional user-id header for abuse detection.
     * @param  ?int  $tenantId  Optional tenant id to namespace the cache.
     * @return array<int, float[]>
     */
    public function embed(
        string $deploymentId,
        array $texts,
        ?int $dimensions = null,
        ?string $user = null,
        ?string $connection = null,
        ?int $tenantId = null,
    ): array {
        $deploymentId = $this->resolveAlias($deploymentId);
        $modelName = $this->resolveModelName($deploymentId, $connection);

        // A12 — Default to the ambient tenant. If neither an explicit
        // arg nor an ambient tenant is available (CLI / central domain
        // / unit test), fall back to a `t0` namespace and emit a
        // structured warning so operators can spot un-scoped embedding
        // calls in production logs.
        if ($tenantId === null) {
            $ambient = function_exists('tenant') ? tenant('id') : null;
            $tenantId = $ambient !== null ? (int) $ambient : 0;

            if ($tenantId === 0 && function_exists('\\Illuminate\\Support\\Facades\\Log')) {
                \Illuminate\Support\Facades\Log::warning('AzureManager::embed: no tenant_id resolved; falling back to shared t0 cache namespace', [
                    'deployment_id' => $deploymentId,
                    'model' => $modelName,
                ]);
            }
        }

        $tenantPrefix = $tenantId !== 0 ? "tenant:{$tenantId}:" : '';

        $ttl = (int) ($this->config['cache']['embedding_ttl']
            ?? config('core-ai.cache.embedding_ttl', 604800));

        $cm = new AzureCredentialManager(
            $this->config['connections'][$connection ?? $this->config['default'] ?? 'default']['keys'] ?? []
        );

        $key = $cm->current();
        $endpoint = $key['endpoint'];
        $apiVersion = $key['api_version'] ?? '2024-10-21';
        $apiKey = $key['api_key'];
        $base = rtrim($endpoint, '/');

        $results = [];
        $pending = [];

        foreach ($texts as $i => $text) {
            $hash = hash('sha256', $modelName.'|'.((string) $dimensions).'|'.$text);
            $cacheKey = "azure_ai_embeddings_{$tenantPrefix}{$hash}";
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $results[$i] = $cached;
            } else {
                $pending[$i] = $text;
            }
        }

        foreach ($pending as $i => $text) {
            $body = ['input' => $text];
            if ($dimensions !== null) {
                $body['dimensions'] = $dimensions;
            }

            $headers = [
                'api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ];
            if ($user !== null && $user !== '') {
                $headers['x-ms-user-agent'] = $user;
            }

            $url = (new AzureEndpointResolver())->embeddingsUrl($base, $deploymentId, $apiVersion);

            $response = Http::withHeaders($headers)->timeout(60)->post($url, $body);

            if (! $response->successful()) {
                throw new AzureException("Azure embed HTTP {$response->status()}: ".$response->body(), $response->status());
            }

            $vec = $response->json('data.0.embedding') ?? [];

            if (! is_array($vec) || empty($vec)) {
                throw new AzureException("Azure embed returned no vector for text index {$i}");
            }

            $hash = hash('sha256', $modelName.'|'.((string) $dimensions).'|'.$text);
            Cache::put("azure_ai_embeddings_{$tenantPrefix}{$hash}", $vec, $ttl);
            $results[$i] = $vec;
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Resolve the underlying model name for a deployment ID.
     *
     * Used by embed() so the cache key is keyed by the actual model that
     * produced the vectors (e.g. "text-embedding-3-small") rather than the
     * arbitrary deployment ID the operator picked. Falls back to the
     * deployment ID itself when the deployment listing is unavailable
     * (AI Foundry project endpoints, transient API errors, etc.) so cache
     * behaviour degrades gracefully to the v2.1.x shape.
     */
    private function resolveModelName(string $deploymentId, ?string $connection): string
    {
        try {
            $models = $this->client($connection)->fetchModels();
        } catch (\Throwable $e) {
            return $deploymentId;
        }

        foreach ($models as $model) {
            if (($model['model_id'] ?? null) === $deploymentId) {
                $name = $model['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        }

        return $deploymentId;
    }
}
