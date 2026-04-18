<?php

namespace Ubxty\AzureAi\Facades;

use Illuminate\Support\Facades\Facade;
use Ubxty\AzureAi\AzureManager;

/**
 * @method static array invoke(string $modelId = '', string $systemPrompt = '', string $userMessage = '', int $maxTokens = 4096, float $temperature = 0.7, ?array $pricing = null, ?string $connection = null)
 * @method static array converse(string $modelId, array $messages, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)
 * @method static array converseStream(string $modelId, array $messages, callable $onChunk, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7, ?string $connection = null, ?array $pricing = null)
 * @method static \Ubxty\CoreAi\Conversation\ConversationBuilder conversation(string $modelId)
 * @method static array testConnection(?string $connection = null)
 * @method static array listModels(?string $connection = null)
 * @method static array fetchModels(?string $connection = null)
 * @method static int syncModels(?string $connection = null)
 * @method static array<string, array> getModelsGrouped(?string $connection = null, ?string $context = null)
 * @method static string defaultModel()
 * @method static string defaultImageModel()
 * @method static string resolveAlias(string $modelIdOrAlias)
 * @method static \Ubxty\CoreAi\Client\ModelAliasResolver aliases()
 * @method static \Ubxty\CoreAi\Logging\InvocationLogger getLogger()
 * @method static bool isConfigured(?string $connection = null)
 * @method static bool supportsStreaming(?string $connection = null)
 * @method static array getConfig()
 * @method static string platformName()
 *
 * @see AzureManager
 */
class Azure extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AzureManager::class;
    }
}
