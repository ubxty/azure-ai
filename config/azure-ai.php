<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default Azure OpenAI connection to use. This corresponds to a key
    | in the "connections" array below.
    |
    */
    'default' => env('AZURE_OPENAI_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Azure OpenAI Connections
    |--------------------------------------------------------------------------
    |
    | Each connection defines an Azure OpenAI resource endpoint and API key(s).
    | "keys" supports multiple credential sets for automatic failover.
    |
    */
    'connections' => [
        'default' => [
            'keys' => [
                [
                    'label' => env('AZURE_OPENAI_KEY_LABEL', 'Primary'),
                    'api_key' => env('AZURE_OPENAI_API_KEY', ''),
                    'endpoint' => env('AZURE_OPENAI_ENDPOINT', ''),
                    'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the client handles rate limiting and transient errors.
    |
    */
    'retry' => [
        'max_retries' => env('AZURE_OPENAI_MAX_RETRIES', 3),
        'base_delay' => env('AZURE_OPENAI_RETRY_DELAY', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost Limits
    |--------------------------------------------------------------------------
    |
    | Set daily and monthly spending limits. When exceeded, API calls will
    | throw a CostLimitExceededException. Set to null to disable.
    |
    */
    'limits' => [
        'daily' => env('AZURE_OPENAI_DAILY_LIMIT', null),
        'monthly' => env('AZURE_OPENAI_MONTHLY_LIMIT', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'models_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Filtering
    |--------------------------------------------------------------------------
    |
    | Control which model providers are visible.
    |
    */
    'providers' => [
        'disabled_providers' => explode(',', env('AZURE_OPENAI_DISABLED_PROVIDERS', '')),

        'chat' => [
            'disabled_providers' => explode(',', env('AZURE_OPENAI_CHAT_DISABLED_PROVIDERS', '')),
        ],

        'image' => [
            'disabled_providers' => explode(',', env('AZURE_OPENAI_IMAGE_DISABLED_PROVIDERS', '')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Invocation Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'max_tokens' => 4096,
        'temperature' => 0.7,
        'model' => env('AZURE_OPENAI_DEFAULT_MODEL', ''),
        'image_model' => env('AZURE_OPENAI_DEFAULT_IMAGE_MODEL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Aliases
    |--------------------------------------------------------------------------
    |
    | Short aliases for deployment names. Use the alias anywhere a deployment
    | ID is accepted and it will be resolved automatically.
    |
    */
    'aliases' => [
        // 'gpt4' => 'my-gpt-4o-deployment',
        // 'mini' => 'my-gpt-4o-mini-deployment',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invocation Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('AZURE_OPENAI_LOGGING_ENABLED', false),
        'channel' => env('AZURE_OPENAI_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    */
    'health_check' => [
        'enabled' => env('AZURE_OPENAI_HEALTH_CHECK_ENABLED', false),
        'path' => '/health/azure-openai',
        'middleware' => [],
    ],

];
