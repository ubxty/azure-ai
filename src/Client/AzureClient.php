<?php

namespace Ubxty\AzureAi\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\AzureAi\Events\AzureKeyRotated;
use Ubxty\AzureAi\Events\AzureRateLimited;
use Ubxty\AzureAi\Exceptions\AzureException;
use Ubxty\CoreAi\Exceptions\RateLimitException;
use Ubxty\CoreAi\Models\ModelSpecResolver;
use Ubxty\CoreAi\Standards\OpenAI\OpenAIClient;

/**
 * Azure-specific Chat Completions client.
 *
 * Extends core-ai's OpenAIClient and overrides only the Azure-specific
 * hooks: v1 vs traditional endpoint URL/auth shimming, the
 * `max_tokens` → `max_completion_tokens` swap, Azure deployment listing,
 * Azure-friendly error mapping, and the Azure event hooks. The wire
 * format (request body, SSE parsing, cache-marker injection) is
 * inherited from core-ai/Standards/OpenAI.
 *
 * BC guarantee: every public method that v2.1.x callers used still
 * exists with the same signature — converse(), converseStream(),
 * testConnection(), listModels(), fetchModels(), listDeployments(),
 * getCredentialManager(), setModelsCacheTtl(), setPromptCachePoints(),
 * setRetryAfterSeconds().
 */
class AzureClient extends OpenAIClient
{
    private AzureEndpointResolver $endpointResolver;

    public function __construct(
        AzureCredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
        ?AzureEndpointResolver $endpointResolver = null,
    ) {
        parent::__construct($credentials, $maxRetries, $baseDelay);
        $this->endpointResolver = $endpointResolver ?? new AzureEndpointResolver();
    }

    public function platformName(): string
    {
        return 'Azure OpenAI';
    }

    // ─────────────────────────────────────────────────────────
    //  OpenAIClient hooks overridden for Azure
    // ─────────────────────────────────────────────────────────

    protected function chatUrl(string $endpoint, string $modelId, array $key): string
    {
        $apiVersion = (string) ($key['api_version'] ?? '2024-10-21');

        return $this->endpointResolver->chatUrl($endpoint, $modelId, $apiVersion);
    }

    protected function authHeaders(string $endpoint, array $key, ?string $idempotencyKey): array
    {
        $headers = $this->endpointResolver->authHeaders($endpoint, (string) ($key['api_key'] ?? ''))
            + ['Content-Type' => 'application/json'];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    protected function resolveModelId(string $modelId, array $key): string
    {
        return $modelId;
    }

    /**
     * Azure-specific request-body adjustment:
     *   - v1 endpoints use `max_completion_tokens` + a `model` field
     *   - traditional deployments use `max_tokens` + deployment-in-URL
     *
     * The inherited body shape already has `max_tokens`; we swap it
     * and add `model` only when the endpoint is v1.
     */
    protected function buildRequest(
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        array $tools,
        ?\Ubxty\CoreAi\Contracts\ToolChoice $toolChoice,
        ?\Ubxty\CoreAi\Contracts\StructuredSchema $schema,
        ?array $cacheAnchors,
        string $modelId,
        array $key,
    ): array {
        $body = parent::buildRequest(
            $messages, $systemPrompt, $maxTokens, $temperature,
            $tools, $toolChoice, $schema, $cacheAnchors, $modelId, $key,
        );

        $endpoint = (string) ($key['endpoint'] ?? '');
        if ($this->endpointResolver->isV1($endpoint)) {
            unset($body['max_tokens']);
            $body['max_completion_tokens'] = $maxTokens;
            $body['model'] = $modelId;
        }

        return $body;
    }

    // ─────────────────────────────────────────────────────────
    //  Azure-specific deployment listing
    // ─────────────────────────────────────────────────────────

    /**
     * List deployed models on the Azure OpenAI resource. AI Foundry
     * project endpoints don't expose this route, so they degrade
     * gracefully to an empty list — fetchModels() falls back to the
     * configured default model in that case.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDeployments(): array
    {
        $key = $this->credentials->current();
        $endpoint = (string) ($key['endpoint'] ?? '');
        $apiVersion = (string) ($key['api_version'] ?? '2024-10-21');
        $apiKey = (string) ($key['api_key'] ?? '');
        $base = $this->endpointResolver->dataPlaneBase($endpoint);
        $isV1 = $this->endpointResolver->isV1($endpoint);

        return Cache::remember(
            'azure_ai_deployments_'.md5($base.$apiKey),
            $this->modelsCacheTtl,
            function () use ($base, $apiVersion, $apiKey, $isV1): array {
                $url = "{$base}/openai/deployments?api-version={$apiVersion}";

                $response = Http::withHeaders(['api-key' => $apiKey])->get($url);

                if (! $response->successful() && $isV1) {
                    return [];
                }

                if (! $response->successful()) {
                    throw new AzureException(
                        'Failed to list deployments: HTTP '.$response->status().' — '.$response->body()
                    );
                }

                return $response->json('data') ?? [];
            }
        );
    }

    /**
     * Backwards-compatible alias — kept returning deployments under
     * the `listModels` name in v2.1.x so callers that called
     * `AzureClient::listModels()` directly (not via the manager) keep
     * working.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModels(): array
    {
        return $this->listDeployments();
    }

    /**
     * Fetch deployments with the normalized shape used by the model
     * picker wizard.
     *
     * @return array<int, array{model_id: string, name: string, context_window: int, max_tokens: int, capabilities: array, input_modalities: array, is_active: bool, provider: string}>
     */
    public function fetchModels(): array
    {
        $deployments = $this->listDeployments();

        return array_map(function (array $deployment): array {
            $modelName = (string) ($deployment['model'] ?? $deployment['id'] ?? '');
            $deploymentId = (string) ($deployment['id'] ?? $modelName);
            $specs = ModelSpecResolver::resolve($modelName);
            $status = (string) ($deployment['status'] ?? 'succeeded');

            $capabilities = (array) ($specs['capabilities'] ?? ['text']);
            $inputModalities = (array) ($specs['input_modalities'] ?? ['text']);

            if (str_contains($modelName, 'vision')
                || str_contains($modelName, 'gpt-4o')
                || str_contains($modelName, 'gpt-4-turbo')) {
                if (! in_array('image', $inputModalities, true)) {
                    $inputModalities[] = 'image';
                }
            }

            return [
                'model_id' => $deploymentId,
                'name' => (string) ($deployment['model'] ?? $deploymentId),
                'context_window' => (int) $specs['context_window'],
                'max_tokens' => (int) $specs['max_tokens'],
                'capabilities' => $capabilities,
                'input_modalities' => $inputModalities,
                'is_active' => $status === 'succeeded',
                'provider' => $this->resolveProvider($modelName),
            ];
        }, $deployments);
    }

    /**
     * Probe the connection. For AI Foundry v1 endpoints there's no
     * `/models` listing route, so we probe via a tiny chat completion
     * instead. "Missed model deployment" errors are treated as a
     * reachable-but-unconfigured success.
     *
     * @return array{success: bool, message: string, response_time: int, model_count?: int}
     */
    public function testConnection(): array
    {
        $start = microtime(true);

        try {
            $key = $this->credentials->current();
            $endpoint = (string) ($key['endpoint'] ?? '');
            $isV1 = $this->endpointResolver->isV1($endpoint);

            if ($isV1) {
                $apiKey = (string) ($key['api_key'] ?? '');
                $base = $this->endpointResolver->normalize($endpoint);
                $defaultModel = (string) config('core-ai.azure_ai.defaults.model', '');

                $body = [
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                    'max_tokens' => 1,
                ];
                if ($defaultModel !== '') {
                    $body['model'] = $defaultModel;
                }

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout(15)->post("{$base}/chat/completions", $body);

                $elapsed = (int) ((microtime(true) - $start) * 1000);

                if ($response->successful()) {
                    $model = (string) ($response->json('model') ?? $defaultModel ?: 'unknown');

                    return [
                        'success' => true,
                        'message' => "Connection successful! AI Foundry endpoint responding (model: {$model}).",
                        'response_time' => $elapsed,
                        'model_count' => 1,
                    ];
                }

                $errorMsg = (string) ($response->json('error.message') ?? '');
                if (str_contains(strtolower($errorMsg), 'missed model deployment')
                    || str_contains(strtolower($errorMsg), 'model not found')) {
                    return [
                        'success' => true,
                        'message' => 'Connection successful! AI Foundry endpoint is reachable. Set AZURE_OPENAI_DEFAULT_MODEL in .env to specify a deployment.',
                        'response_time' => $elapsed,
                        'model_count' => 0,
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'AI Foundry endpoint returned HTTP '.$response->status().': '.$errorMsg,
                    'response_time' => $elapsed,
                ];
            }

            $deployments = $this->listDeployments();
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            return [
                'success' => true,
                'message' => 'Connection successful! Found '.count($deployments).' deployment(s).',
                'response_time' => $elapsed,
                'model_count' => count($deployments),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * Public wrapper so {@see \Ubxty\AzureAi\AzureManager::embed()} can
     * route through the same v1-detection logic.
     */
    public function embeddingsUrl(string $deploymentId, string $apiVersion): string
    {
        $key = $this->credentials->current();
        $endpoint = (string) ($key['endpoint'] ?? '');

        return $this->endpointResolver->embeddingsUrl($endpoint, $deploymentId, $apiVersion);
    }

    public function getCredentialManager(): AzureCredentialManager
    {
        /** @var AzureCredentialManager $cm */
        $cm = $this->credentials;

        return $cm;
    }

    public function setModelsCacheTtl(int $ttl): static
    {
        $this->modelsCacheTtl = $ttl;

        return $this;
    }

    // ─────────────────────────────────────────────────────────
    //  Azure-specific error mapping (used by parent sendRequest)
    // ─────────────────────────────────────────────────────────

    /**
     * Map a non-2xx response to the platform's friendly exception. Called
     * by the parent's sendRequest/sendStreamingRequest when the upstream
     * returns a non-success status.
     *
     * @throws AzureException|RateLimitException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $status = $response->status();
        $body = $response->json() ?? [];
        $message = (string) ($body['error']['message'] ?? $response->body());

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');
            if ($retryAfter !== null) {
                $this->setRetryAfterSeconds((int) $retryAfter);
            }
            throw new RateLimitException("429 Too many requests: {$message}", 429);
        }

        throw new AzureException(
            $this->extractFriendlyError("Azure OpenAI HTTP Error: {$status} - {$message}"),
            $status
        );
    }

    protected function extractFriendlyError(string $errorMessage): string
    {
        $friendlyMessages = [
            'invalid_api_key' => 'Invalid API key. Check your AZURE_OPENAI_API_KEY in .env.',
            'Unauthorized' => 'Authentication failed. Check your API key or Azure AD token.',
            'DeploymentNotFound' => 'Deployment not found. Verify the deployment name exists in your Azure OpenAI resource.',
            'model_not_found' => 'Model or deployment not found. Check the deployment ID.',
            'content_filter' => 'Content was blocked by Azure content safety filters.',
            'content_policy' => 'Content was blocked by Azure content safety filters.',
            'context_length_exceeded' => 'The conversation is too long for this model. Try reducing the message history.',
            'tokens_limit_reached' => 'Token limit exceeded. Reduce max_tokens or the input message length.',
            'rate_limit' => 'Rate limit exceeded. Wait a moment and try again.',
            'server_error' => 'Azure OpenAI service error. Please try again.',
            'Resource not found' => 'Azure OpenAI endpoint not found. Check your AZURE_OPENAI_ENDPOINT.',
        ];

        foreach ($friendlyMessages as $needle => $friendly) {
            if (str_contains($errorMessage, $needle)) {
                return $friendly;
            }
        }

        if (strlen($errorMessage) > 200) {
            return substr($errorMessage, 0, 200).'...';
        }

        return $errorMessage;
    }

    // ─────────────────────────────────────────────────────────
    //  HasRetryLogic event hooks
    // ─────────────────────────────────────────────────────────

    protected function resetPlatformClient(): void
    {
        // No persistent SDK client to reset for Azure (HTTP-based).
    }

    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        Log::warning('Azure OpenAI call failed, trying next key', [
            'error' => $reason,
            'key_label' => $fromKey['label'] ?? 'Unknown',
        ]);

        if (function_exists('event')) {
            event(new AzureKeyRotated(
                fromKeyLabel: $fromKey['label'] ?? 'Unknown',
                toKeyLabel: $toKey['label'] ?? 'Unknown',
                reason: $reason,
                modelId: $modelId,
            ));
        }
    }

    protected function onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void
    {
        if (function_exists('event')) {
            event(new AzureRateLimited(
                modelId: $modelId,
                keyLabel: $key['label'] ?? 'Unknown',
                retryAttempt: $retryAttempt,
                waitSeconds: 0,
            ));
        }
    }
}