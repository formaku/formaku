<?php

return [
    'prefix' => env('FORMAKU_PREFIX', 'formaku'),
    'public_routes' => env('FORMAKU_PUBLIC_ROUTES', false),
    'sitemap' => env('FORMAKU_SITEMAP', false),
    'guard' => env('FORMAKU_GUARD', 'web'),
    'authorize' => null,
    'roles' => [],
    'registration' => env('FORMAKU_REGISTRATION', false),
    'ai' => [
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL'),
            'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 3000),
        ],
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL'),
            'max_output_tokens' => env('GEMINI_MAX_OUTPUT_TOKENS', 3000),
        ],
    ],
    'github' => ['token' => env('GITHUB_TOKEN'), 'repository' => env('GITHUB_REPOSITORY'), 'workflow' => 'release.yml'],
];
