<?php
return [
 'prefix' => env('FORMAKU_PREFIX', 'formaku'),
 'public_routes' => env('FORMAKU_PUBLIC_ROUTES', false),
 'sitemap' => env('FORMAKU_SITEMAP', false),
 'guard' => env('FORMAKU_GUARD', 'web'),
 'authorize' => null,
 'registration' => env('FORMAKU_REGISTRATION', false),
 'ai' => ['openai' => ['key' => env('OPENAI_API_KEY'), 'model' => env('OPENAI_MODEL')], 'gemini' => ['key' => env('GEMINI_API_KEY'), 'model' => env('GEMINI_MODEL')]],
 'github' => ['token' => env('GITHUB_TOKEN'), 'repository' => env('GITHUB_REPOSITORY'), 'workflow' => 'release.yml'],
];
