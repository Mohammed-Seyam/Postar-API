<?php

return [
    'prices' => [
        'creator_monthly' => env('PADDLE_PRICE_CREATOR_MONTHLY'),
        'creator_yearly' => env('PADDLE_PRICE_CREATOR_YEARLY'),
        'pro_monthly' => env('PADDLE_PRICE_PRO_MONTHLY'),
        'pro_yearly' => env('PADDLE_PRICE_PRO_YEARLY'),
    ],
    'api_key' => env('PADDLE_API_KEY'),
    'sandbox' => (bool) env('PADDLE_SANDBOX', true),
    'client_token' => env('PADDLE_CLIENT_TOKEN'),
    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
];
