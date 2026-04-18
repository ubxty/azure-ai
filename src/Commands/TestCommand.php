<?php

namespace Ubxty\AzureAi\Commands;

use Ubxty\AzureAi\AzureManager;
use Ubxty\CoreAi\Commands\AbstractTestCommand;

class TestCommand extends AbstractTestCommand
{
    protected $signature = 'azure:test
        {model? : Deployment ID to test directly}
        {--connection= : Which Azure connection to use}
        {--all-keys : Test all configured API keys}
        {--sync : Sync deployments to database before testing}
        {--legacy : Include inactive deployments in picker}
        {--prompt= : Custom prompt for model testing}
        {--max-tokens=100 : Max tokens for test invocation}
        {--json : Output results as JSON}';

    protected $description = 'Test Azure OpenAI connection and model invocation';

    public function handle(AzureManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeTest();
    }

    protected function platformName(): string
    {
        return 'Azure OpenAI';
    }
}
