<?php

namespace Ubxty\AzureAi\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;

class AzureCredentialManager extends AbstractCredentialManager
{
    protected function normalizeKey(array $key): array
    {
        $key['label'] = $key['label'] ?? 'Primary';
        $key['api_key'] = $key['api_key'] ?? '';
        $key['endpoint'] = rtrim($key['endpoint'] ?? '', '/');
        $key['api_version'] = $key['api_version'] ?? '2024-06-01';

        return $key;
    }

    /**
     * Get all keys (labels and endpoints only, no secrets).
     *
     * @return array<int, array{index: int, label: string, endpoint: string, api_version: string, configured: bool}>
     */
    public function list(): array
    {
        return array_map(function (array $key, int $index) {
            return [
                'index' => $index,
                'label' => $key['label'] ?? 'Key '.($index + 1),
                'endpoint' => $key['endpoint'] ?? '',
                'api_version' => $key['api_version'] ?? '2024-06-01',
                'configured' => ! empty($key['api_key']) && ! empty($key['endpoint']),
            ];
        }, $this->keys, array_keys($this->keys));
    }

    /**
     * Get the API key for the current credential.
     */
    public function getApiKey(): string
    {
        return $this->current()['api_key'] ?? '';
    }

    /**
     * Get the endpoint URL for the current credential.
     */
    public function getEndpoint(): string
    {
        return $this->current()['endpoint'] ?? '';
    }

    /**
     * Get the API version for the current credential.
     */
    public function getApiVersion(): string
    {
        return $this->current()['api_version'] ?? '2024-06-01';
    }
}
