<?php

namespace Ubxty\AzureAi\Commands;

use Ubxty\AzureAi\AzureManager;
use Ubxty\CoreAi\Commands\AbstractConfigureCommand;

class ConfigureCommand extends AbstractConfigureCommand
{
    protected $signature = 'azure:configure
        {--show : Show current configuration without modifying}';

    protected $description = 'Configure Azure OpenAI credentials';

    public function handle(AzureManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeConfigure();
    }

    protected function platformName(): string
    {
        return 'Azure OpenAI';
    }

    protected function envPrefix(): string
    {
        return 'AZURE_OPENAI';
    }

    protected function requiredEnvKeys(): array
    {
        return [
            [
                'key' => 'AZURE_OPENAI_ENDPOINT',
                'label' => 'Azure OpenAI Endpoint',
                'secret' => false,
                'hint' => 'The BASE endpoint — do NOT include /responses, /chat/completions etc.'
                    .PHP_EOL.'  Traditional:  https://YOUR-RESOURCE.openai.azure.com'
                    .PHP_EOL.'  AI Foundry:   https://YOUR-RESOURCE.openai.azure.com/openai/v1'
                    .PHP_EOL.'  AI Foundry:   https://YOUR-RESOURCE.services.ai.azure.com/api/projects/PROJECT/openai/v1'
                    .PHP_EOL.'  Portal → Azure OpenAI resource → Keys and Endpoint',
            ],
            [
                'key' => 'AZURE_OPENAI_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'hint' => 'Portal → Your Azure OpenAI resource → Keys and Endpoint → KEY 1 or KEY 2',
            ],
            [
                'key' => 'AZURE_OPENAI_DEFAULT_MODEL',
                'label' => 'Default Deployment / Model ID',
                'secret' => false,
                'hint' => 'The model/deployment to use by default (e.g. gpt-4.1, gpt-4o)',
            ],
        ];
    }
}
