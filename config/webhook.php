<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gupshup Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for securing Gupshup WhatsApp webhook endpoints.
    |
    */

    'gupshup' => [
        /*
         * Secret key for HMAC signature validation
         * WAJIB: Set di .env file
         */
        'secret' => env('GUPSHUP_WEBHOOK_SECRET'),

        /*
         * Header name for signature
         */
        'signature_header' => 'X-Gupshup-Signature',

        /*
         * HMAC algorithm
         */
        'algorithm' => 'sha256',

        /*
         * IP Whitelist - Gupshup Server IPs
         * Update sesuai dokumentasi Gupshup terbaru
         * https://docs.gupshup.io/docs/ip-addresses
         */
        'allowed_ips' => [
            // Gupshup Production IPs
            '52.66.99.214',
            '13.232.129.243',
            '13.127.181.236',
            '13.232.105.183',
            '52.66.195.227',
            '13.235.79.179',
            '65.0.118.226',
            '13.126.207.72',
            
            // Gupshup US IPs
            '3.7.44.11',
            '65.0.58.113',
            
            // Allow localhost for development
            '127.0.0.1',
            '::1',
        ],

        /*
         * Bypass IP check in local/testing environment
         */
        'bypass_ip_check' => env('GUPSHUP_BYPASS_IP_CHECK', false),

        /*
         * Rate limiting: requests per minute
         */
        'rate_limit' => env('GUPSHUP_WEBHOOK_RATE_LIMIT', 30),

        /*
         * Required fields in webhook payload
         */
        'required_fields' => [
            'app',          // app ID dari Gupshup
            'phone',        // nomor telepon
            'type',         // event type
        ],

        /*
         * Status mapping dari Gupshup ke internal status
         */
        'status_map' => [
            // Connected states
            'approved'    => 'connected',
            'connected'   => 'connected',
            'active'      => 'connected',
            'live'        => 'connected',
            
            // Failed states
            'rejected'    => 'failed',
            'failed'      => 'failed',
            'banned'      => 'failed',
            'disconnected'=> 'failed',
            
            // Pending states
            'pending'     => 'pending',
            'processing'  => 'pending',
            'submitted'   => 'pending',
            'in_review'   => 'pending',
        ],

        /*
         * Event TTL for idempotency check (in minutes)
         * Events older than this will be cleaned up
         */
        'event_ttl' => 1440, // 24 hours
    ],

];
