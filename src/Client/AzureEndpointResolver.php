<?php

namespace Ubxty\AzureAi\Client;

/**
 * Pure endpoint-derivation helpers for Azure OpenAI.
 *
 * Promotes the four private helpers that used to live inside
 * `AzureClient` (normalize, isV1, getDataPlaneBase, buildChatUrl,
 * getAuthHeaders) so the chat-completions path and the embeddings path
 * always agree on endpoint flavour. Both paths previously reimplemented
 * the same `str_ends_with('/v1') || str_contains('/v1/')` heuristic
 * independently — keeping the duplicate would let a Foundry project
 * endpoint that ends in `/v1` route chat through the Bearer path but
 * route embeddings through the deployment-in-URL path (or vice-versa).
 *
 * Pattern reference: bedrock-ai's `InferenceProfileResolver`.
 */
class AzureEndpointResolver
{
    /** @var string[] */
    private const RESOURCE_SUFFIXES = [
        '/responses',
        '/chat/completions',
        '/embeddings',
        '/completions',
        '/audio/speech',
        '/audio/transcriptions',
        '/images/generations',
    ];

    /**
     * Strip any appended resource path so the client always works from
     * the base URL.
     */
    public function normalize(string $endpoint): string
    {
        $base = rtrim($endpoint, '/');

        foreach (self::RESOURCE_SUFFIXES as $suffix) {
            if (str_ends_with($base, $suffix)) {
                return substr($base, 0, strlen($base) - strlen($suffix));
            }
        }

        return $base;
    }

    /**
     * Detect whether the endpoint is the AI Foundry OpenAI-compatible v1 style.
     *
     * v1 endpoints look like:
     *   https://resource.services.ai.azure.com/.../openai/v1
     * Traditional endpoints:
     *   https://resource.openai.azure.com
     */
    public function isV1(string $endpoint): bool
    {
        $normalized = $this->normalize($endpoint);

        return str_ends_with($normalized, '/v1')
            || str_contains($normalized, '/v1/');
    }

    /**
     * Derive the traditional data-plane base URL from any endpoint style.
     *
     * For v1 endpoints like
     *   https://resource.services.ai.azure.com/api/projects/p/openai/v1
     * → https://resource.services.ai.azure.com/api/projects/p
     * For traditional endpoints the normalized value is returned unchanged.
     */
    public function dataPlaneBase(string $endpoint): string
    {
        $normalized = $this->normalize($endpoint);

        if (preg_match('#^(.+)/openai/v1$#', $normalized, $m)) {
            return $m[1];
        }

        return $normalized;
    }

    /**
     * Build the chat completions URL for the given endpoint style.
     */
    public function chatUrl(string $endpoint, string $deploymentId, string $apiVersion): string
    {
        $base = $this->normalize($endpoint);

        if ($this->isV1($endpoint)) {
            return "{$base}/chat/completions";
        }

        return "{$base}/openai/deployments/{$deploymentId}/chat/completions?api-version={$apiVersion}";
    }

    /**
     * Build the embeddings URL for the given endpoint style.
     */
    public function embeddingsUrl(string $endpoint, string $deploymentId, string $apiVersion): string
    {
        $base = $this->normalize($endpoint);

        if ($this->isV1($endpoint)) {
            return "{$base}/embeddings";
        }

        return "{$base}/openai/deployments/{$deploymentId}/embeddings?api-version={$apiVersion}";
    }

    /**
     * Get the correct auth headers for the endpoint style.
     *
     * v1 endpoints use OpenAI-style Bearer token; traditional endpoints
     * use the `api-key` header.
     *
     * @return array<string, string>
     */
    public function authHeaders(string $endpoint, string $apiKey): array
    {
        if ($this->isV1($endpoint)) {
            return ['Authorization' => "Bearer {$apiKey}"];
        }

        return ['api-key' => $apiKey];
    }
}