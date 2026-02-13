<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Midtrans Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk integrasi Midtrans Payment Gateway.
    | Pastikan env MIDTRANS_* sudah diisi dengan benar.
    |
    */

    // Server Key dari Midtrans Dashboard
    'server_key' => env('MIDTRANS_SERVER_KEY', ''),

    // Client Key dari Midtrans Dashboard
    'client_key' => env('MIDTRANS_CLIENT_KEY', ''),

    // Merchant ID
    'merchant_id' => env('MIDTRANS_MERCHANT_ID', ''),

    // Environment: 'sandbox' atau 'production'
    'is_production' => env('MIDTRANS_ENV', 'sandbox') === 'production',

    // Sanitize input
    'is_sanitized' => true,

    // 3DS untuk kartu kredit
    'is_3ds' => true,

    // Snap URL berdasarkan environment
    'snap_url' => env('MIDTRANS_ENV', 'sandbox') === 'production'
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js',

    // API URL
    'api_url' => env('MIDTRANS_ENV', 'sandbox') === 'production'
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com',

    // Callback/Webhook URL (akan diisi otomatis)
    'notification_url' => env('MIDTRANS_NOTIFICATION_URL', '/api/midtrans/webhook'),

    // Finish redirect URL
    'finish_url' => env('MIDTRANS_FINISH_URL', '/billing?status=finish'),

    // Unfinish redirect URL
    'unfinish_url' => env('MIDTRANS_UNFINISH_URL', '/billing?status=unfinish'),

    // Error redirect URL
    'error_url' => env('MIDTRANS_ERROR_URL', '/billing?status=error'),

    // Expiry time dalam menit (default 60 menit)
    'expiry_duration' => env('MIDTRANS_EXPIRY_DURATION', 60),

    // Enabled payment methods
    'enabled_payments' => [
        'credit_card',
        'bca_va',
        'bni_va',
        'bri_va',
        'permata_va',
        'gopay',
        'shopeepay',
        'qris',
    ],
];
