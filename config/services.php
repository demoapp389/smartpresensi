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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor Service (Windows Service / systemd)
    |--------------------------------------------------------------------------
    |
    | URL dan token untuk menghubungi supervisor lokal yang mengelola connector
    | dan queue worker. Dibaca lewat config() agar aman saat `config:cache`.
    |
    */
    'supervisor' => [
        'url' => env('SERVICE_SUPERVISOR_URL', 'http://127.0.0.1:4372'),
        'token' => env('SERVICE_SUPERVISOR_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprint Connector
    |--------------------------------------------------------------------------
    |
    | Konfigurasi connector Solution X601: driver utama, interpreter Python,
    | entrypoint script, dan API lokal connector.
    |
    */
    'connector' => [
        'primary_driver' => env('CONNECTOR_PRIMARY_DRIVER', 'auto'),
        'python_binary' => env('CONNECTOR_PYTHON_BIN'),
        'entrypoint' => env('CONNECTOR_ENTRYPOINT', 'connector/zkconnector.py'),
        'local_api_url' => env('CONNECTOR_LOCAL_API_URL', 'http://127.0.0.1:4371'),
        'local_api_token' => env('CONNECTOR_LOCAL_API_TOKEN', ''),
    ],

];
