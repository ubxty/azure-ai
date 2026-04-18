<?php

namespace Ubxty\AzureAi\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\AzureAi\Events\AzureKeyRotated;
use Ubxty\AzureAi\Events\AzureRateLimited;
use Ubxty\AzureAi\Exceptions\AzureException;
use Ubxty\CoreAi\Client\HasRetryLogic;
use Ubxty\CoreAi\Exceptions\RateLimitException;
use Ubxty\CoreAi\Models\ModelSpecResolver;

class AzureClient
{
    use HasRetryLogic;

    protected int $modelsCacheTtl = 3600;

    public function __construct(
        AzureCredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
    ) {
        $this->credentials = $credentials;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
    }

    // ─────────────────────────────────────────────────────────
    //  Endpoint helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Normalize an endpoint URL by stripping any appended resource path.
     *
     * Users sometimes copy the full resource URL (e.g. ending in /responses or
     * /chat/completions) instead of the base v1 URL. We strip those here so the
     * client always works from the correct base.
     */
    private function normalizeEndpoint(string $endpoint): string
    {
        $base = rtrim($endpoint, '/');

        $knownSuffixes = [
            '/responses',
            '/chat/completions',
            '/embeddings',
            '/completions',
            '/audio/speech',
            '/audio/transcriptions',
            '/images/generations',
        ];

        foreach ($knownSuffixes as $suffix) {
            if (str_ends_with($base, $suffix)) {
                return substr($base, 0, strlen($base) - strlen($suffix));
            }
        }

        return $base;
    }

    /**
     * Detect whether the endpoint is the AI Foundry OpenAI-compatible v1 style.
     *
     * v1 endpoints look like: https://resource.services.ai.azure.com/.../openai/v1
     * Traditional endpoints:  https://resource.openai.azure.com
     */
    private function isV1Endpoint(string $endpoint): bool
    {
        $normalized = $this->normalizeEndpoint($endpoint);

        return str_ends_with($normalized, '/v1')
            || str_contains($normalized, '/v1/');
    }

    /**
     * Derive the traditional data-plane base URL from any endpoint style.
     *
     * For v1 endpoints like:
     *   https://resource.services.ai.azure.com/api/projects/p/openai/v1
     * → https://resource.services.ai.azure.com/api/projects/p
     *
     * For traditional endpoints the normalized value is returned unchanged.
     */
    private function getDataPlaneBase(string $endpoint): string
    {
        $normalized = $this->normalizeEndpoint($endpoint);

        if (preg_match('#^(.+)/openai/v1$#', $normalized, $m)) {
            return $m[1];
        }

        return $normalized;
    }

    /**
     * Build the chat completions URL for the given endpoint style.
     */
    private function buildChatUrl(string $endpoint, string $resolvedId, string $apiVersion): string
    {
        $base = $this->normalizeEndpoint($endpoint);

        if ($this->isV1Endpoint($endpoint)) {
            return "{$base}/chat/completions";
        }

        return "{$base}/openai/deployments/{$resolvedId}/chat/completions?api-version={$apiVersion}";
    }

    /**
     * Get the correct auth headers for the endpoint style.
     *
     * v1 endpoints use OpenAI-style Bearer token for chat completions;
     * data-plane listing endpoints always use api-key header.
     *
     * @return array<string, string>
     */
    private function getAuthHeaders(string $endpoint, string $apiKey): array
    {
        if ($this->isV1Endpoint($endpoint)) {
            return ['Authorization' => "Bearer {$apiKey}"];
        }

        return ['api-key' => $apiKey];
    }

    // ─────────────────────────────────────────────────────────
    //  Chat completion
    // ─────────────────────────────────────────────────────────

    /**
     * Send a chat completion request (non-streaming).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    public function converse(
        string $deploymentId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        $startTime = microtime(true);

        return $this->withRetry($deploymentId, function (string $resolvedId, array $key) use ($messages, $systemPrompt, $maxTokens, $temperature, $startTime) {
            $endpoint = $key['endpoint'];
            $apiVersion = $key['api_version'] ?? '2024-10-21';
            $apiKey = $key['api_key'];
            $v1 = $this->isV1Endpoint($endpoint);

            $url = $this->buildChatUrl($endpoint, $resolvedId, $apiVersion);

            $body = [
                'messages' => $this->formatMessages($messages, $systemPrompt),
                'temperature' => $temperature,
            ];

            // Newer models (accessed via v1 endpoints) require max_completion_tokens;
            // traditional deployments use the legacy max_tokens parameter.
            if ($v1) {
                $body['model'] = $resolvedId;
                $body['max_completion_tokens'] = $maxTokens;
            } else {
                $body['max_tokens'] = $maxTokens;
            }

            $response = Http::withHeaders(
                $this->getAuthHeaders($endpoint, $apiKey) + ['Content-Type' => 'application/json']
            )->timeout(120)->post($url, $body);

            if (! $response->successful()) {
                $this->handleErrorResponse($response);
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? [];
            $usage = $data['usage'] ?? [];

            $outputText = $choice['message']['content'] ?? '';
            $inputTokens = $usage['prompt_tokens'] ?? 0;
            $outputTokens = $usage['completion_tokens'] ?? 0;

            return [
                'response' => $outputText,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'stop_reason' => $choice['finish_reason'] ?? 'stop',
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'model_id' => $resolvedId,
                'key_used' => $key['label'] ?? 'Primary',
            ];
        });
    }

    /**
     * Send a streaming chat completion request (SSE).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, latency_ms: int, model_id: string, key_used: string}
     */
    public function converseStream(
        string $deploymentId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        $startTime = microtime(true);

        return $this->withRetry($deploymentId, function (string $resolvedId, array $key) use ($messages, $onChunk, $systemPrompt, $maxTokens, $temperature, $startTime) {
            $endpoint = $key['endpoint'];
            $apiVersion = $key['api_version'] ?? '2024-10-21';
            $apiKey = $key['api_key'];
            $v1 = $this->isV1Endpoint($endpoint);

            $url = $this->buildChatUrl($endpoint, $resolvedId, $apiVersion);

            $body = [
                'messages' => $this->formatMessages($messages, $systemPrompt),
                'temperature' => $temperature,
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ];

            if ($v1) {
                $body['model'] = $resolvedId;
                $body['max_completion_tokens'] = $maxTokens;
            } else {
                $body['max_tokens'] = $maxTokens;
            }

            $authHeaders = $this->getAuthHeaders($endpoint, $apiKey);
            $curlHeaders = array_map(
                fn ($k, $v) => "{$k}: {$v}",
                array_keys($authHeaders),
                $authHeaders
            );
            $curlHeaders[] = 'Content-Type: application/json';
            $curlHeaders[] = 'Accept: text/event-stream';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);

            $fullResponse = '';
            $inputTokens = 0;
            $outputTokens = 0;
            $buffer = '';

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, &$fullResponse, &$inputTokens, &$outputTokens, $onChunk) {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $json = json_decode(substr($line, 6), true);

                        if (! $json) {
                            continue;
                        }

                        // Stream usage tokens (when stream_options.include_usage is true)
                        if (isset($json['usage'])) {
                            $inputTokens = $json['usage']['prompt_tokens'] ?? $inputTokens;
                            $outputTokens = $json['usage']['completion_tokens'] ?? $outputTokens;
                        }

                        $delta = $json['choices'][0]['delta'] ?? [];

                        if (isset($delta['content'])) {
                            $text = $delta['content'];
                            $fullResponse .= $text;
                            $onChunk($text, ['type' => 'delta']);
                        }
                    }
                }

                return strlen($data);
            });

            $httpCode = 0;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int) $matches[1];
                }

                return strlen($header);
            });

            curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new AzureException("Azure OpenAI streaming error: {$curlError}");
            }

            if ($httpCode >= 400) {
                if ($httpCode === 429) {
                    throw new RateLimitException('429 Too many requests - rate limited', 429);
                }

                throw new AzureException("Azure OpenAI HTTP error: {$httpCode}");
            }

            return [
                'response' => $fullResponse,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'model_id' => $resolvedId,
                'key_used' => $key['label'] ?? 'Primary',
            ];
        });
    }

    /**
     * Test the connection by listing deployments.
     *
     * @return array{success: bool, message: string, response_time: int, deployment_count?: int}
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $key = $this->credentials->current();
            $endpoint = $key['endpoint'];
            $v1 = $this->isV1Endpoint($endpoint);

            // For AI Foundry v1 endpoints, test with a lightweight chat call
            // since the deployment listing API isn't available.
            if ($v1) {
                $apiKey = $key['api_key'];
                $base = $this->normalizeEndpoint($endpoint);
                $defaultModel = config('azure-ai.defaults.model', '');

                // If no default model is configured, we can only verify the
                // endpoint is reachable (the expected error tells us it works).
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

                $responseTime = (int) ((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $model = $response->json('model') ?? $defaultModel ?: 'unknown';

                    return [
                        'success' => true,
                        'message' => "Connection successful! AI Foundry endpoint responding (model: {$model}).",
                        'response_time' => $responseTime,
                        'model_count' => 1,
                    ];
                }

                // "Missed model deployment" means the endpoint is reachable but
                // no default model is configured — connection itself is OK.
                $errorBody = $response->json();
                $errorMsg = $errorBody['error']['message'] ?? '';
                if (str_contains(strtolower($errorMsg), 'missed model deployment') || str_contains(strtolower($errorMsg), 'model not found')) {
                    return [
                        'success' => true,
                        'message' => 'Connection successful! AI Foundry endpoint is reachable. Set AZURE_OPENAI_DEFAULT_MODEL in .env to specify a deployment.',
                        'response_time' => $responseTime,
                        'model_count' => 0,
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'AI Foundry endpoint returned HTTP '.$response->status().': '.$errorMsg,
                    'response_time' => $responseTime,
                ];
            }

            // Traditional endpoint: list deployments
            $deployments = $this->listDeployments();
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'message' => 'Connection successful! Found '.count($deployments).' deployment(s).',
                'response_time' => $responseTime,
                'model_count' => count($deployments),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * List deployed models (deployments) on the Azure OpenAI resource.
     *
     * Uses the data-plane endpoint: GET {base}/openai/deployments?api-version=X
     * Works reliably for traditional endpoints (*.openai.azure.com).
     * AI Foundry project endpoints may not support this — returns [] gracefully.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDeployments(): array
    {
        $key = $this->credentials->current();
        $endpoint = $key['endpoint'];
        $apiVersion = $key['api_version'] ?? '2024-10-21';
        $apiKey = $key['api_key'];
        $base = $this->getDataPlaneBase($endpoint);
        $v1 = $this->isV1Endpoint($endpoint);

        return Cache::remember(
            'azure_ai_deployments_'.md5($base.$apiKey),
            $this->modelsCacheTtl,
            function () use ($base, $apiVersion, $apiKey, $v1) {
                $url = "{$base}/openai/deployments?api-version={$apiVersion}";

                $response = Http::withHeaders([
                    'api-key' => $apiKey,
                ])->get($url);

                // AI Foundry project endpoints don't expose a deployment listing route.
                // Return empty so the caller falls back to the configured default model.
                if (! $response->successful() && $v1) {
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
     * List all available models on the Azure OpenAI resource.
     *
     * Uses the data-plane endpoint: GET {base}/openai/models?api-version=X
     * AI Foundry project endpoints may not support this — returns [] gracefully.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModels(): array
    {
        $key = $this->credentials->current();
        $endpoint = $key['endpoint'];
        $apiVersion = $key['api_version'] ?? '2024-10-21';
        $apiKey = $key['api_key'];
        $base = $this->getDataPlaneBase($endpoint);
        $v1 = $this->isV1Endpoint($endpoint);

        return Cache::remember(
            'azure_ai_models_'.md5($base.$apiKey),
            $this->modelsCacheTtl,
            function () use ($base, $apiVersion, $apiKey, $v1) {
                $url = "{$base}/openai/models?api-version={$apiVersion}";

                $response = Http::withHeaders([
                    'api-key' => $apiKey,
                ])->get($url);

                if (! $response->successful() && $v1) {
                    return [];
                }

                if (! $response->successful()) {
                    throw new AzureException(
                        'Failed to list models: HTTP '.$response->status().' — '.$response->body()
                    );
                }

                return $response->json('data') ?? [];
            }
        );
    }

    /**
     * Fetch deployments with normalized structure.
     *
     * @return array<int, array{model_id: string, name: string, context_window: int, max_tokens: int, capabilities: array, input_modalities: array, is_active: bool, provider: string}>
     */
    public function fetchModels(): array
    {
        $deployments = $this->listDeployments();

        return array_map(function (array $deployment) {
            $modelName = $deployment['model'] ?? $deployment['id'] ?? '';
            $deploymentId = $deployment['id'] ?? $modelName;
            $specs = ModelSpecResolver::resolve($modelName);

            $status = $deployment['status'] ?? 'succeeded';

            $capabilities = $specs['capabilities'] ?? ['text'];
            $inputModalities = $specs['input_modalities'] ?? ['text'];

            // Enhance capabilities from model name for common patterns
            if (str_contains($modelName, 'vision') || str_contains($modelName, 'gpt-4o') || str_contains($modelName, 'gpt-4-turbo')) {
                if (! in_array('image', $inputModalities)) {
                    $inputModalities[] = 'image';
                }
            }

            return [
                'model_id' => $deploymentId,
                'name' => $deployment['model'] ?? $deploymentId,
                'context_window' => $specs['context_window'],
                'max_tokens' => $specs['max_tokens'],
                'capabilities' => $capabilities,
                'input_modalities' => $inputModalities,
                'is_active' => $status === 'succeeded',
                'provider' => $this->resolveProvider($modelName),
            ];
        }, $deployments);
    }

    /**
     * Set the models cache TTL in seconds.
     */
    public function setModelsCacheTtl(int $ttl): static
    {
        $this->modelsCacheTtl = $ttl;

        return $this;
    }

    /**
     * Get the credential manager instance.
     */
    public function getCredentialManager(): AzureCredentialManager
    {
        return $this->credentials;
    }

    // ─────────────────────────────────────────────────────────
    //  Message formatting (Azure / OpenAI format)
    // ─────────────────────────────────────────────────────────

    /**
     * Format messages into the OpenAI chat completions format.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array<int, array{role: string, content: string|array}>
     */
    protected function formatMessages(array $messages, string $systemPrompt = ''): array
    {
        $formatted = [];

        if ($systemPrompt !== '') {
            $formatted[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $formatted[] = ['role' => $role, 'content' => $content];

                continue;
            }

            // Multimodal: convert to OpenAI content array format
            if (is_array($content)) {
                $parts = [];

                foreach ($content as $block) {
                    if (isset($block['text'])) {
                        $parts[] = ['type' => 'text', 'text' => $block['text']];
                    } elseif (isset($block['type']) && $block['type'] === 'text') {
                        $parts[] = $block;
                    } elseif (isset($block['image'])) {
                        $parts[] = $this->formatImageBlock($block['image']);
                    } elseif (isset($block['type']) && $block['type'] === 'image_url') {
                        $parts[] = $block;
                    } elseif (isset($block['document'])) {
                        // Azure OpenAI doesn't natively support document uploads.
                        // Extract text content if available and send as text.
                        $parts[] = $this->formatDocumentBlock($block['document']);
                    }
                }

                $formatted[] = ['role' => $role, 'content' => $parts];

                continue;
            }

            $formatted[] = ['role' => $role, 'content' => (string) $content];
        }

        return $formatted;
    }

    /**
     * Format an image block for the OpenAI vision API.
     */
    protected function formatImageBlock(array $imageData): array
    {
        if (isset($imageData['source']['bytes'])) {
            $bytes = $imageData['source']['bytes'];
            $format = $imageData['format'] ?? 'jpeg';
            $mimeType = "image/{$format}";
            $base64 = base64_encode($bytes);

            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mimeType};base64,{$base64}",
                ],
            ];
        }

        if (isset($imageData['url'])) {
            return [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageData['url'],
                ],
            ];
        }

        return ['type' => 'text', 'text' => '[Image could not be processed]'];
    }

    /**
     * Format a document block. Azure OpenAI doesn't natively support documents,
     * so we extract text content and include it inline.
     */
    protected function formatDocumentBlock(array $documentData): array
    {
        $name = $documentData['name'] ?? 'document';

        if (isset($documentData['source']['bytes'])) {
            $bytes = $documentData['source']['bytes'];
            $format = $documentData['format'] ?? 'txt';

            if (in_array($format, ['txt', 'md', 'csv', 'html', 'htm'])) {
                return [
                    'type' => 'text',
                    'text' => "[Document: {$name}]\n\n".$bytes,
                ];
            }

            // For binary documents, encode as base64 and notify
            return [
                'type' => 'text',
                'text' => "[Document: {$name} ({$format}, ".strlen($bytes)." bytes) — binary content sent as base64]\n\n".base64_encode($bytes),
            ];
        }

        return ['type' => 'text', 'text' => "[Document: {$name} — content not available]"];
    }

    // ─────────────────────────────────────────────────────────
    //  Error handling
    // ─────────────────────────────────────────────────────────

    /**
     * Handle a non-successful HTTP response from Azure.
     *
     * @throws AzureException|RateLimitException
     */
    protected function handleErrorResponse(Response $response): void
    {
        $status = $response->status();
        $body = $response->json() ?? [];
        $message = $body['error']['message'] ?? $response->body();

        if ($status === 429) {
            throw new RateLimitException("429 Too many requests: {$message}", 429);
        }

        throw new AzureException(
            $this->extractFriendlyError("Azure OpenAI HTTP Error: {$status} - {$message}"),
            $status,
        );
    }

    /**
     * Extract a user-friendly error message from raw Azure errors.
     */
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

        // Truncate overly long messages
        if (strlen($errorMessage) > 200) {
            return substr($errorMessage, 0, 200).'...';
        }

        return $errorMessage;
    }

    // ─────────────────────────────────────────────────────────
    //  HasRetryLogic hooks
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

    // ─────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Map a model name to a provider for grouping.
     */
    protected function resolveProvider(string $modelName): string
    {
        $modelLower = strtolower($modelName);

        $providerMap = [
            'gpt-' => 'OpenAI',
            'o1' => 'OpenAI',
            'o3' => 'OpenAI',
            'o4' => 'OpenAI',
            'dall-e' => 'OpenAI',
            'whisper' => 'OpenAI',
            'tts' => 'OpenAI',
            'text-embedding' => 'OpenAI',
            'phi-' => 'Microsoft',
            'llama' => 'Meta',
            'mistral' => 'Mistral AI',
            'mixtral' => 'Mistral AI',
            'cohere' => 'Cohere',
            'jais' => 'G42',
        ];

        foreach ($providerMap as $prefix => $provider) {
            if (str_contains($modelLower, $prefix)) {
                return $provider;
            }
        }

        return 'Other';
    }
}
