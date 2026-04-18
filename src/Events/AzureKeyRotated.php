<?php

namespace Ubxty\AzureAi\Events;

use Ubxty\CoreAi\Events\AiKeyRotated;

class AzureKeyRotated extends AiKeyRotated
{
    public function __construct(
        string $fromKeyLabel,
        string $toKeyLabel,
        string $reason,
        string $modelId,
    ) {
        parent::__construct($fromKeyLabel, $toKeyLabel, $reason, $modelId, 'Azure OpenAI');
    }
}
