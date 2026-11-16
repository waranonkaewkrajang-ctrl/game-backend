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
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Unified admin (NestJS) → internal read-only admin API. Hashes = sha256(token), comma separated.
    'internal_admin' => [
        'token_hashes' => env('INTERNAL_ADMIN_TOKEN_HASHES', ''),
        'allowed_ips'  => env('INTERNAL_ADMIN_ALLOWED_IPS', ''),
        'per_minute'   => (int) env('INTERNAL_ADMIN_PER_MINUTE', 300),
        'log_channel'  => env('INTERNAL_ADMIN_LOG_CHANNEL', 'stack'),
    ],

    // 🆕 แจ้งสถานะถอนเป็นการ์ด LINE (admin-api /withdraw-notify/events) — ว่าง = ปิด
    'withdraw_notify' => [
        'url'    => env('WITHDRAW_NOTIFY_URL'),
        'secret' => env('WITHDRAW_NOTIFY_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
