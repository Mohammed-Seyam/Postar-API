<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => match (config('app.env')) {
        'local' => ['*'],
        'production' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        default => ['GET', 'POST', 'OPTIONS'],
    },

    // ===== Dynamic origins based on environment =====
    'allowed_origins' => match (config('app.env')) {
        'local' => [
            'http://localhost:4200',
            'http://127.0.0.1:4200',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
        ],
        'production' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://postar.blog')),
        default => [],
    },

    'allowed_origins_patterns' => match (config('app.env')) {
        'local' => ['/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/'],
        'production' => [],
        default => [],
    },

    'allowed_headers' => match (config('app.env')) {
        'local' => ['*'],
        'production' => [
            'Accept',
            'Content-Type',
            'Authorization',
            'X-Requested-With',
            'X-CSRF-Token',
        ],
        default => ['*'],
    },

    'exposed_headers' => match (config('app.env')) {
        'local' => [],
        'production' => [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ],
        default => [],
    },

    'max_age' => match (config('app.env')) {
        'local' => 0,
        'production' => 86400,
        default => 0,
    },

    'supports_credentials' => true,

];
