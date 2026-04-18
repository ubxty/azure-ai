<?php

namespace Ubxty\AzureAi\Commands;

use Ubxty\AzureAi\AzureManager;
use Ubxty\CoreAi\Commands\AbstractModelsCommand;

class ModelsCommand extends AbstractModelsCommand
{
    protected $signature = 'azure:models
        {--connection= : Which Azure connection to use}
        {--filter= : Filter models by name or ID}
        {--provider= : Filter by provider name}
        {--legacy : Include inactive/deprecated deployments}
        {--json : Output as JSON}';

    protected $description = 'List available Azure OpenAI deployments';

    public function handle(AzureManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeModels();
    }

    protected function platformName(): string
    {
        return 'Azure OpenAI';
    }
}
