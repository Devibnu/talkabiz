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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk WhatsApp provider.
    | Driver yang tersedia: mock, fonnte
    |
    */

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'mock'),
        'mock_delay' => env('WHATSAPP_MOCK_DELAY', 500), // dalam milidetik
        'mock_success_rate' => env('WHATSAPP_MOCK_SUCCESS_RATE', 95), // persentase
        'force_real' => env('WHATSAPP_FORCE_REAL', false), // paksa pakai real di local
        
        // WhatsApp Gateway (Node.js/Baileys) untuk QR connection
        'gateway_url' => env('WHATSAPP_GATEWAY_URL', 'http://localhost:3001'),
        'gateway_api_key' => env('WHATSAPP_GATEWAY_API_KEY', ''),
        'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET', ''),
    ],

    'fonnte' => [
        'token' => env('FONNTE_API_TOKEN'),
        'base_url' => env('FONNTE_BASE_URL', 'https://api.fonnte.com'),
        'timeout' => env('FONNTE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gupshup WhatsApp Cloud API Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk Gupshup WhatsApp Business Cloud API.
    | PRODUCTION-READY: Cloud API only, NO QR code, NO device session.
    |
    | Get credentials from: https://apps.gupshup.io/whatsapp/overview
    |
    */

    'gupshup' => [
        'app_id' => env('GUPSHUP_APP_ID', 'd36d75a4-7a75-4546-a781-aff2dfba52de'),
        'api_key' => env('GUPSHUP_API_KEY'),
        'app_name' => env('GUPSHUP_APP_NAME'),
        'source_number' => env('GUPSHUP_SOURCE_NUMBER'),
        'webhook_secret' => env('GUPSHUP_WEBHOOK_SECRET'),
        'base_url' => env('GUPSHUP_BASE_URL', 'https://api.gupshup.io/wa/api/v1'),
        'partner_url' => env('GUPSHUP_PARTNER_URL', 'https://partner.gupshup.io/partner/app'),
    ],

];
