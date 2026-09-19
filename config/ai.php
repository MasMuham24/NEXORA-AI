<?php

return [
    'provider' => env('AI_PROVIDER', 'pateway'),

    'timeout' => 60,
    'stream_timeout' => 120,
    'max_tokens' => 2048,

    'providers' => [
        'pateway' => [
            'class' => App\Services\AI\Providers\PatewayProvider::class,
            'api_key' => env('PATEWAY_API_KEY'),
            'model' => env('PATEWAY_MODEL'),
            'base_url' => env(
                'PATEWAY_BASE_URL',
                'https://api.pateway.ai/v1'
            ),
        ],

        'openai' => [
            'class' => App\Services\AI\Providers\OpenAIProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env(
                'OPENAI_BASE_URL',
                'https://api.openai.com/v1'
            ),
        ],
    ],
];
