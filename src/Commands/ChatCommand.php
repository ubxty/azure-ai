<?php

namespace Ubxty\AzureAi\Commands;

use Ubxty\AzureAi\AzureManager;
use Ubxty\CoreAi\Commands\AbstractChatCommand;

class ChatCommand extends AbstractChatCommand
{
    protected $signature = 'azure:chat
        {model? : The deployment ID or model alias to chat with}
        {--system= : System prompt for the conversation}
        {--max-tokens=4096 : Maximum tokens per response}
        {--temperature=0.7 : Temperature (0.0 to 1.0)}
        {--connection= : Which Azure connection to use}
        {--no-stream : Disable streaming output}';

    protected $description = 'Start an interactive chat session with Azure OpenAI';

    public function handle(AzureManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeChat();
    }

    protected function platformName(): string
    {
        return 'Azure OpenAI';
    }
}
