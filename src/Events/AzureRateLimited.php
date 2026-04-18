<?php

namespace Ubxty\AzureAi\Events;

use Ubxty\CoreAi\Events\AiRateLimited;

class AzureRateLimited extends AiRateLimited
{
    public function __construct(
        string $modelId,
        string $keyLabel,
        int $retryAttempt,
        int $waitSeconds,
    ) {
        parent::__construct($modelId, $keyLabel, $retryAttempt, $waitSeconds, 'Azure OpenAI');
    }
}
