<?php

namespace Ubxty\AzureAi\Events;

use Ubxty\CoreAi\Events\AiInvoked;

class AzureInvoked extends AiInvoked
{
    public function __construct(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        float $cost,
        int $latencyMs,
        string $keyUsed,
        ?string $connection = null,
    ) {
        parent::__construct($modelId, $inputTokens, $outputTokens, $cost, $latencyMs, $keyUsed, $connection, 'Azure OpenAI');
    }
}
