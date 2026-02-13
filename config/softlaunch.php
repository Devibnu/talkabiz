<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SOFT-LAUNCH CONFIGURATION
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk soft-launch UMKM Pilot.
    | JANGAN UBAH TANPA APPROVAL OWNER/SA!
    |
    | Last Updated: 2026-01-30
    | Phase: UMKM_PILOT (HARD LOCKED)
    |
    */

    // =========================================================================
    // PHASE CONFIGURATION
    // =========================================================================
    
    'current_phase' => env('SOFTLAUNCH_PHASE', 'umkm_pilot'),
    
    'phases' => [
        'umkm_pilot' => [
            'enabled' => true,
            'locked' => true, // HARD LOCKED - tidak bisa diubah via UI
            'description' => 'UMKM Pilot Phase - 10-50 users terpilih',
        ],
        'umkm_scale' => [
            'enabled' => false,
            'locked' => true,
            'description' => 'UMKM Scale Phase - 100-300 users',
        ],
        'corporate' => [
            'enabled' => false,
            'locked' => true,
            'description' => 'Corporate Phase - Invite only',
        ],
    ],

    // =========================================================================
    // FEATURE FLAGS
    // =========================================================================
    
    'features' => [
        // Corporate features - SEMUA OFF
        'corporate_enabled' => env('FEATURE_CORPORATE', false),
        'corporate_registration' => env('FEATURE_CORPORATE_REGISTRATION', false),
        'corporate_sla' => env('FEATURE_CORPORATE_SLA', false),
        'enterprise_api' => env('FEATURE_ENTERPRISE_API', false),
        
        // UMKM features
        'self_service' => env('FEATURE_SELF_SERVICE', false), // Manual approval only
        'auto_upgrade' => env('FEATURE_AUTO_UPGRADE', false),
        
        // Promo & Marketing - OFF selama soft-launch
        'promo_enabled' => env('FEATURE_PROMO', false),
        'referral_enabled' => env('FEATURE_REFERRAL', false),
        'bulk_discount' => env('FEATURE_BULK_DISCOUNT', false),
    ],

    // =========================================================================
    // CAMPAIGN LIMITS (HARD ENFORCED)
    // =========================================================================
    
    'campaign' => [
        // Recipient limits
        'max_recipients_per_campaign' => env('CAMPAIGN_MAX_RECIPIENTS', 1000),
        'max_recipients_per_day' => env('CAMPAIGN_MAX_DAILY_RECIPIENTS', 2000),
        
        // Campaign limits per user
        'max_active_campaigns_per_user' => env('CAMPAIGN_MAX_ACTIVE', 1),
        'max_campaigns_per_day' => env('CAMPAIGN_MAX_PER_DAY', 3),
        
        // Rate limiting
        'min_delay_seconds' => env('CAMPAIGN_MIN_DELAY', 3),
        'max_delay_seconds' => env('CAMPAIGN_MAX_DELAY', 5),
        'messages_per_minute' => env('CAMPAIGN_RATE_LIMIT', 20),
        
        // Throttle levels based on risk
        'throttle_levels' => [
            'normal' => ['delay' => 3, 'rate' => 20],    // risk < 40
            'caution' => ['delay' => 5, 'rate' => 10],   // risk 40-59
            'warning' => ['delay' => 8, 'rate' => 5],    // risk 60-79
            'danger' => ['delay' => 15, 'rate' => 2],    // risk >= 80
        ],
        
        // Queue configuration
        'queue_name' => env('CAMPAIGN_QUEUE', 'campaigns'),
        'priority_queue' => env('CAMPAIGN_PRIORITY_QUEUE', 'campaigns-priority'),
        'max_queue_size' => env('CAMPAIGN_MAX_QUEUE', 5000),
    ],

    // =========================================================================
    // TEMPLATE POLICY (STRICT)
    // =========================================================================
    
    'template' => [
        // DISABLE free text - hanya approved template
        'free_text_enabled' => env('TEMPLATE_FREE_TEXT', false),
        'custom_template_enabled' => env('TEMPLATE_CUSTOM', false),
        
        // Approval
        'require_approval' => env('TEMPLATE_REQUIRE_APPROVAL', true),
        'auto_approve' => env('TEMPLATE_AUTO_APPROVE', false),
        'approval_timeout_hours' => env('TEMPLATE_APPROVAL_TIMEOUT', 24),
        
        // Content restrictions
        'max_length' => env('TEMPLATE_MAX_LENGTH', 1024),
        'max_variables' => env('TEMPLATE_MAX_VARIABLES', 5),
        'allowed_media_types' => ['image', 'document'],
        'max_media_size_mb' => env('TEMPLATE_MAX_MEDIA_MB', 5),
        
        // Banned patterns (regex)
        'banned_patterns' => [
            '/\b(pinjol|pinjaman online|kredit tanpa agunan)\b/i',
            '/\b(judi|togel|slot|casino)\b/i',
            '/\b(investasi|profit.*%|return.*%)\b/i',
            '/\b(gratis|free|hadiah|prize|winner)\b/i',
        ],
        
        // Link restrictions
        'allow_links' => env('TEMPLATE_ALLOW_LINKS', true),
        'allow_shortened_links' => env('TEMPLATE_ALLOW_SHORT_LINKS', false),
        'banned_domains' => [
            'bit.ly', 's.id', 'tinyurl.com', 'ow.ly', 't.co',
        ],
    ],

    // =========================================================================
    // AUTO SAFETY SYSTEM (CRITICAL)
    // =========================================================================
    
    'safety' => [
        // Failure thresholds
        'failure_rate_warning' => env('SAFETY_FAILURE_WARNING', 3),    // 3% → warning
        'failure_rate_pause' => env('SAFETY_FAILURE_PAUSE', 5),        // 5% → auto-pause
        'failure_rate_suspend' => env('SAFETY_FAILURE_SUSPEND', 10),   // 10% → auto-suspend
        
        // Risk score thresholds
        'risk_throttle_threshold' => env('SAFETY_RISK_THROTTLE', 60),  // ≥60 → throttle
        'risk_suspend_threshold' => env('SAFETY_RISK_SUSPEND', 80),    // ≥80 → auto-suspend
        'risk_ban_threshold' => env('SAFETY_RISK_BAN', 95),            // ≥95 → permanent ban
        
        // Abuse detection
        'abuse_rate_warning' => env('SAFETY_ABUSE_WARNING', 2),        // 2% → warning
        'abuse_rate_action' => env('SAFETY_ABUSE_ACTION', 5),          // 5% → action required
        
        // Queue health
        'queue_latency_warning_seconds' => env('SAFETY_QUEUE_WARNING', 30),
        'queue_latency_critical_seconds' => env('SAFETY_QUEUE_CRITICAL', 60),
        
        // Delivery health
        'delivery_rate_minimum' => env('SAFETY_DELIVERY_MIN', 90),     // <90% → investigate
        
        // Auto actions
        'auto_pause_enabled' => env('SAFETY_AUTO_PAUSE', true),
        'auto_suspend_enabled' => env('SAFETY_AUTO_SUSPEND', true),
        'auto_throttle_enabled' => env('SAFETY_AUTO_THROTTLE', true),
        
        // Cooldown periods
        'pause_cooldown_minutes' => env('SAFETY_PAUSE_COOLDOWN', 30),
        'suspend_cooldown_hours' => env('SAFETY_SUSPEND_COOLDOWN', 24),
        'throttle_duration_minutes' => env('SAFETY_THROTTLE_DURATION', 60),
    ],

    // =========================================================================
    // QUOTA & BILLING PROTECTION
    // =========================================================================
    
    'quota' => [
        // Minimum balance to send
        'minimum_balance' => env('QUOTA_MIN_BALANCE', 10000), // Rp 10.000
        'minimum_messages' => env('QUOTA_MIN_MESSAGES', 50),  // 50 messages
        
        // Overdraft protection
        'allow_overdraft' => env('QUOTA_ALLOW_OVERDRAFT', false),
        'overdraft_limit' => env('QUOTA_OVERDRAFT_LIMIT', 0),
        
        // Low balance warning
        'low_balance_threshold' => env('QUOTA_LOW_THRESHOLD', 50000), // Rp 50.000
        'low_messages_threshold' => env('QUOTA_LOW_MESSAGES', 200),
        
        // Auto top-up (disabled for pilot)
        'auto_topup_enabled' => env('QUOTA_AUTO_TOPUP', false),
    ],

    // =========================================================================
    // IDEMPOTENCY & RETRY
    // =========================================================================
    
    'idempotency' => [
        'enabled' => env('IDEMPOTENCY_ENABLED', true),
        'key_ttl_hours' => env('IDEMPOTENCY_TTL', 24),
        
        // Retry configuration
        'max_retries' => env('RETRY_MAX_ATTEMPTS', 3),
        'retry_delay_seconds' => env('RETRY_DELAY', 30),
        'retry_backoff_multiplier' => env('RETRY_BACKOFF', 2),
        
        // Duplicate detection
        'detect_duplicate_recipients' => env('DETECT_DUPLICATE_RECIPIENTS', true),
        'duplicate_window_hours' => env('DUPLICATE_WINDOW', 24),
    ],

    // =========================================================================
    // MONITORING & ALERTING
    // =========================================================================
    
    'monitoring' => [
        // Snapshot
        'snapshot_enabled' => env('MONITORING_SNAPSHOT', true),
        'snapshot_interval_minutes' => env('MONITORING_SNAPSHOT_INTERVAL', 60),
        
        // Alerts
        'alert_channels' => ['telegram', 'email'],
        'alert_cooldown_minutes' => env('ALERT_COOLDOWN', 15),
        
        // Thresholds for alerts
        'alert_on_failure_rate' => 5,
        'alert_on_risk_score' => 60,
        'alert_on_queue_latency' => 30,
        'alert_on_low_balance' => 50000,
    ],

    // =========================================================================
    // RESTRICTIONS (CANNOT BE OVERRIDDEN)
    // =========================================================================
    
    'restrictions' => [
        'corporate_access' => true,       // Corporate OFF
        'promo_campaigns' => true,        // Promo OFF
        'template_override' => true,      // No template bypass
        'auto_suspend_override' => true,  // No suspend bypass
        'limit_override' => true,         // No limit bypass
    ],
];
