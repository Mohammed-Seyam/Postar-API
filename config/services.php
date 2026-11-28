<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'waitlist' => [
        'referral_boost' => env('WAITLIST_REFERRAL_BOOST', 10),
        'turnstile_site_key' => env('TURNSTILE_SITE_KEY'),
        'turnstile_secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI', 'https://api.postar.blog/api/auth/tiktok/callback'),
        'scopes' => env('TIKTOK_SCOPES', 'user.info.basic,user.info.profile,video.publish,video.list'),
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
    ],

    // Instagram API with Instagram Login (graph.instagram.com).
    'instagram' => [
        'app_id' => env('INSTAGRAM_APP_ID'),
        'app_secret' => env('INSTAGRAM_APP_SECRET'),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI', 'https://api.postar.blog/api/auth/instagram/callback'),
        'api_version' => env('INSTAGRAM_API_VERSION', 'v26.0'),
        'scopes' => env('INSTAGRAM_SCOPES', 'instagram_business_basic,instagram_business_content_publish'),
        'graph_url' => 'https://graph.instagram.com',
        // Reels container polling: ~once a minute for up to ~5 minutes.
        'poll_interval_seconds' => (int) env('INSTAGRAM_POLL_INTERVAL_SECONDS', 60),
        'poll_max_attempts' => (int) env('INSTAGRAM_POLL_MAX_ATTEMPTS', 6),
    ],

];
