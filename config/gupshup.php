<?php

/**
 * Gupshup Configuration
 * 
 * Configuration untuk integrasi Gupshup WhatsApp API.
 * 
 * SECURITY SETTINGS:
 * - webhook_secret: HMAC SHA256 secret untuk validasi signature
 * - allowed_ips: IP whitelist dari Gupshup
 * - bypass_ip_check: Bypass IP check di local/testing (JANGAN aktifkan di production!)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Gupshup API Credentials
    |--------------------------------------------------------------------------
    */
    'api_key' => env('GUPSHUP_API_KEY', ''),
    'app_name' => env('GUPSHUP_APP_NAME', ''),
    'app_id' => env('GUPSHUP_APP_ID', ''),
    
    /*
    |--------------------------------------------------------------------------
    | Webhook Security
    |--------------------------------------------------------------------------
    |
    | webhook_secret: Secret key untuk validasi HMAC SHA256 signature
    | Pastikan sama dengan yang dikonfigurasi di Gupshup dashboard
    |
    */
    'webhook_secret' => env('GUPSHUP_WEBHOOK_SECRET', ''),
    
    /*
    |--------------------------------------------------------------------------
    | IP Whitelist
    |--------------------------------------------------------------------------
    |
    | Daftar IP address yang diizinkan untuk mengirim webhook.
    | IP ini dari dokumentasi resmi Gupshup.
    |
    | Referensi: https://www.gupshup.io/developer/docs/bot-platform/guide/whatsapp-api
    |
    */
    'allowed_ips' => [
        // Gupshup Production IPs
        '34.202.224.208',
        '52.66.99.214',
        '13.232.18.100',
        '13.126.25.140',
        '3.7.35.214',
        '13.127.85.57',
        '3.6.95.91',
        '65.0.188.214',
        '3.110.115.30',
        
        // Gupshup Sandbox IPs (jika menggunakan sandbox)
        '3.7.73.165',
        '13.127.45.192',
        
        // AWS CloudFront (Gupshup CDN)
        '52.84.0.0/15',
        
        // Allow localhost for testing (remove in production)
        // '127.0.0.1',
        // '::1',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Bypass IP Check (Development Only)
    |--------------------------------------------------------------------------
    |
    | PERINGATAN: Hanya aktifkan di environment local atau testing!
    | Di production, selalu set ke false.
    |
    */
    'bypass_ip_check' => env('GUPSHUP_BYPASS_IP_CHECK', false),
    
    /*
    |--------------------------------------------------------------------------
    | Replay Attack Prevention
    |--------------------------------------------------------------------------
    |
    | max_timestamp_age: Maksimal umur timestamp webhook (dalam detik)
    | Webhook dengan timestamp lebih tua dari ini akan ditolak.
    |
    */
    'max_timestamp_age' => env('GUPSHUP_MAX_TIMESTAMP_AGE', 300), // 5 minutes
    
    /*
    |--------------------------------------------------------------------------
    | Webhook Event Cache TTL
    |--------------------------------------------------------------------------
    |
    | TTL untuk cache event ID (untuk idempotency check)
    | Event ID akan disimpan selama waktu ini untuk mencegah duplikasi.
    |
    */
    'event_cache_ttl' => env('GUPSHUP_EVENT_CACHE_TTL', 3600), // 1 hour
    
    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'base_url' => env('GUPSHUP_BASE_URL', 'https://api.gupshup.io'),
    'send_message_url' => env('GUPSHUP_SEND_MESSAGE_URL', 'https://api.gupshup.io/sm/api/v1/msg'),
    'template_url' => env('GUPSHUP_TEMPLATE_URL', 'https://api.gupshup.io/sm/api/v1/template/msg'),
    
    /*
    |--------------------------------------------------------------------------
    | Source Phone (Business Number)
    |--------------------------------------------------------------------------
    */
    'source_phone' => env('GUPSHUP_SOURCE_PHONE', ''),
];
