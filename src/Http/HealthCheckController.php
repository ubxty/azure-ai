<?php

namespace Ubxty\AzureAi\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ubxty\AzureAi\AzureManager;

class HealthCheckController extends Controller
{
    public function __invoke(AzureManager $manager): JsonResponse
    {
        try {
            $result = $manager->testConnection();

            return new JsonResponse([
                'status' => $result['success'] ? 'ok' : 'error',
                'platform' => $manager->platformName(),
                'message' => $result['message'] ?? '',
                'response_time_ms' => $result['response_time'] ?? null,
            ], $result['success'] ? 200 : 503);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'platform' => $manager->platformName(),
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}
