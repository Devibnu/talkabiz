<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk integrasi WhatsApp Business API.
    | Provider yang didukung: gupshup, wablas, fonnte
    |
    */

    'provider' => env('WA_PROVIDER', 'gupshup'),

    /*
    |--------------------------------------------------------------------------
    | Common Configuration (used directly by WhatsAppTemplateProvider)
    |--------------------------------------------------------------------------
    */
    'api_key' => env('WA_API_KEY'),
    'app_name' => env('WA_APP_NAME'),
    'source_number' => env('WA_SOURCE_NUMBER'),
    'base_url' => env('WA_BASE_URL', 'https://api.gupshup.io/wa/api/v1'),
    'timeout' => env('WA_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Gupshup Configuration
    |--------------------------------------------------------------------------
    */
    'gupshup' => [
        'api_key' => env('WA_API_KEY'),
        'app_name' => env('WA_APP_NAME'),
        'source_number' => env('WA_SOURCE_NUMBER'), // nomor pengirim
        'base_url' => env('WA_BASE_URL', 'https://api.gupshup.io/wa/api/v1'),
        'webhook_secret' => env('WA_WEBHOOK_SECRET'),
        
        // Endpoint
        'endpoints' => [
            'send_message' => '/msg',
            'send_template' => '/template/msg',
            'upload_media' => '/media',
        ],
        
        // Timeout settings (seconds)
        'timeout' => env('WA_TIMEOUT', 30),
        'connect_timeout' => env('WA_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Untuk mencegah spam dan mematuhi policy WhatsApp
    |
    */
    'rate_limit' => [
        'per_second' => env('WA_RATE_PER_SECOND', 30),
        'per_minute' => env('WA_RATE_PER_MINUTE', 1000),
        'delay_between_messages' => env('WA_DELAY_MS', 100), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts' => env('WA_RETRY_ATTEMPTS', 3),
        'delay_seconds' => env('WA_RETRY_DELAY', 5),
        'backoff_multiplier' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign Settings
    |--------------------------------------------------------------------------
    */
    'campaign' => [
        'batch_size' => env('WA_BATCH_SIZE', 50),
        'pause_on_error_count' => env('WA_PAUSE_ON_ERROR', 10), // pause jika error berturut-turut
        'auto_pause_low_balance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('WA_LOG_ENABLED', true),
        'channel' => env('WA_LOG_CHANNEL', 'whatsapp'),
        'log_request_body' => env('WA_LOG_REQUEST', true),
        'log_response_body' => env('WA_LOG_RESPONSE', true),
    ],
];
