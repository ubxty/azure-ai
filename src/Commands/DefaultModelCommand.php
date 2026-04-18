<?php

namespace Ubxty\AzureAi\Commands;

use Ubxty\AzureAi\AzureManager;
use Ubxty\CoreAi\Commands\AbstractDefaultModelCommand;

class DefaultModelCommand extends AbstractDefaultModelCommand
{
    protected $signature = 'azure:default-model
        {model? : The deployment ID to set as default}
        {--connection= : Which Azure connection to use}';

    protected $description = 'Set the default Azure OpenAI deployment';

    public function handle(AzureManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeDefaultModel();
    }

    protected function platformName(): string
    {
        return 'Azure OpenAI';
    }

    protected function envKeyMap(): array
    {
        return [
            'default' => 'AZURE_OPENAI_DEFAULT_MODEL',
            'image' => 'AZURE_OPENAI_DEFAULT_IMAGE_MODEL',
        ];
    }
}
