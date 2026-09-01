<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'env_s3' => [
        'enabled' => env('LOAD_ENV_FROM_S3', false),
        'bucket' => env('ENV_S3_BUCKET', env('AWS_BUCKET')),
        'key' => env('ENV_S3_KEY', 'api/.env'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'public' => env('PAYSTACK_PUBLIC_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'allow_demo_fulfill' => env('PAYSTACK_ALLOW_DEMO_FULFILL', false),
    ],

    'wema' => [
        'public' => env('WEMA_ALATPAY_PUBLIC_KEY'),
        'secret' => env('WEMA_ALATPAY_SECRET_KEY'),
        'business_id' => env('WEMA_ALATPAY_BUSINESS_ID'),
        'webhook_secret' => env('WEMA_ALATPAY_WEBHOOK_SECRET'),
        'base' => env('WEMA_ALATPAY_BASE_URL', 'https://api.alatpay.ng'),
    ],

    'prembly' => [
        'key' => env('PREMBLY_API_KEY'),
        'app_id' => env('PREMBLY_APP_ID'),
        'base' => env('PREMBLY_BASE_URL', 'https://api.prembly.com'),
        // Empty = demo biodata only when APP_ENV is not production.
        'allow_demo' => env('PREMBLY_ALLOW_DEMO', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
