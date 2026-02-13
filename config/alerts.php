<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alert System Configuration
    |--------------------------------------------------------------------------
    |
    | KONSEP MUTLAK:
    | 1. Alert bersifat preventif, bukan pengganti Saldo Guard
    | 2. Semua threshold configurable untuk adaptasi bisnis
    | 3. Alert harus deduplicated dengan cooldown mechanism
    |
    | ATURAN KERAS:
    | - ❌ Dilarang spam alert
    | - ❌ Dilarang hardcode threshold  
    | - ❌ Dilarang block di alert layer
    |
    */

    // ==================== BALANCE ALERT THRESHOLDS ====================
    
    /*
     * Balance Low Alert Configuration
     */
    'balance_low_threshold_percentage' => env('ALERT_BALANCE_LOW_PERCENTAGE', 20),
    'balance_low_min_threshold' => env('ALERT_BALANCE_LOW_MIN', 50000), // Rp 50k minimum
    'balance_low_max_threshold' => env('ALERT_BALANCE_LOW_MAX', 500000), // Rp 500k maximum
    'balance_low_cooldown_minutes' => env('ALERT_BALANCE_LOW_COOLDOWN', 120), // 2 jam
    
    /*
     * Balance Zero Alert Configuration
     */
    'balance_zero_cooldown_minutes' => env('ALERT_BALANCE_ZERO_COOLDOWN', 60), // 1 jam
    
    // ==================== COST ANOMALY THRESHOLDS ====================
    
    /*
     * Daily Cost Spike Alert Configuration
     */
    'cost_spike_threshold_percentage' => env('ALERT_COST_SPIKE_PERCENTAGE', 150), // 150%
    'cost_spike_min_amount' => env('ALERT_COST_SPIKE_MIN_AMOUNT', 100000), // Rp 100k minimum  
    'cost_spike_cooldown_minutes' => env('ALERT_COST_SPIKE_COOLDOWN', 240), // 4 jam
    'cost_spike_baseline_days' => env('ALERT_COST_SPIKE_BASELINE_DAYS', 14), // 14 hari rata-rata
    
    /*
     * Real-time Cost Spike Configuration
     */
    'realtime_spike_threshold_percentage' => env('ALERT_REALTIME_SPIKE_PERCENTAGE', 200), // 200%
    'realtime_spike_min_hourly_amount' => env('ALERT_REALTIME_SPIKE_MIN_HOURLY', 50000), // Rp 50k per jam
    'realtime_spike_cooldown_hours' => env('ALERT_REALTIME_SPIKE_COOLDOWN_HOURS', 2), // 2 jam

    // ==================== MESSAGE FAILURE THRESHOLDS ====================
    
    /*
     * Message Failure Rate Alert Configuration
     */
    'failure_rate_threshold_percentage' => env('ALERT_FAILURE_RATE_PERCENTAGE', 15), // 15%
    'failure_rate_min_messages' => env('ALERT_FAILURE_RATE_MIN_MESSAGES', 10), // Minimal 10 messages
    'failure_rate_cooldown_minutes' => env('ALERT_FAILURE_RATE_COOLDOWN', 180), // 3 jam

    // ==================== ALERT DELIVERY LIMITS ====================
    
    /*
     * Daily Limits untuk mencegah spam
     */
    'max_alerts_per_user_per_day' => env('ALERT_MAX_PER_USER_PER_DAY', 20),
    'max_critical_alerts_per_user_per_day' => env('ALERT_MAX_CRITICAL_PER_USER_PER_DAY', 10),
    'max_owner_alerts_per_day' => env('ALERT_MAX_OWNER_PER_DAY', 50),
    
    /*
     * Alert Lifecycle Configuration
     */
    'alert_expiry_days' => env('ALERT_EXPIRY_DAYS', 7), // 7 hari
    'cleanup_expired_days' => env('ALERT_CLEANUP_EXPIRED_DAYS', 30), // 30 hari
    'auto_resolve_days' => env('ALERT_AUTO_RESOLVE_DAYS', 3), // Auto resolve after 3 days

    // ==================== NOTIFICATION CHANNELS ====================
    
    /*
     * Channel Configuration per Alert Type
     */
    'notification_channels' => [
        'balance_low' => ['in_app', 'email'],
        'balance_zero' => ['in_app', 'email'],
        'cost_spike' => ['in_app'],
        'failure_rate_high' => ['in_app'],
        'approaching_zero' => ['in_app'],
    ],
    
    /*
     * Email Notification Rules
     */
    'email_notifications' => [
        'enabled' => env('ALERT_EMAIL_ENABLED', true),
        'critical_only' => env('ALERT_EMAIL_CRITICAL_ONLY', true),
        'owner_cc' => env('ALERT_EMAIL_OWNER_CC', false),
        'daily_summary' => env('ALERT_EMAIL_DAILY_SUMMARY', true),
    ],
    
    /*
     * Owner/Admin Email Configuration
     */
    'admin_emails' => [
        env('ALERT_ADMIN_EMAIL_1', 'admin@talkabiz.com'),
        env('ALERT_ADMIN_EMAIL_2'),
        env('ALERT_ADMIN_EMAIL_3'),
    ],

    // ==================== MONITORING & ANALYSIS ====================
    
    /*
     * Analysis Job Configuration
     */
    'analysis_jobs' => [
        'daily_balance_check_time' => env('ALERT_DAILY_BALANCE_CHECK_TIME', '06:00'), // 6 AM
        'hourly_threshold_check' => env('ALERT_HOURLY_THRESHOLD_CHECK', true),
        'daily_cost_anomaly_check_time' => env('ALERT_DAILY_COST_ANOMALY_TIME', '07:00'), // 7 AM
        'realtime_monitoring_enabled' => env('ALERT_REALTIME_MONITORING', true),
    ],

    // ==================== FEATURE FLAGS ====================
    
    /*
     * Feature Toggle untuk enable/disable specific alerts
     */
    'features' => [
        'balance_alerts_enabled' => env('ALERT_FEATURE_BALANCE', true),
        'cost_anomaly_alerts_enabled' => env('ALERT_FEATURE_COST_ANOMALY', true),
        'failure_rate_alerts_enabled' => env('ALERT_FEATURE_FAILURE_RATE', true),
        'realtime_monitoring_enabled' => env('ALERT_FEATURE_REALTIME', true),
        'auto_resolution_enabled' => env('ALERT_FEATURE_AUTO_RESOLUTION', true),
    ],

    // ==================== LEGACY CONFIGURATION (Preserved) ====================
    
    /*
     * Existing Owner Alert Configuration (Preserved for backward compatibility)
     */
    'thresholds' => [
        // Profit thresholds
        'margin_warning' => env('ALERT_MARGIN_WARNING', 20), // %
        'margin_critical' => env('ALERT_MARGIN_CRITICAL', 10), // %
        'daily_cost_threshold' => env('ALERT_DAILY_COST', 5000000), // IDR

        // Quota thresholds
        'quota_warning' => env('ALERT_QUOTA_WARNING', 20), // %
        'quota_critical' => env('ALERT_QUOTA_CRITICAL', 5), // %
    ],

    'defaults' => [
        'throttle_minutes' => env('ALERT_THROTTLE_MINUTES', 15),
        'timezone' => env('ALERT_TIMEZONE', 'Asia/Jakarta'),
    ],

    'telegram' => [
        'enabled' => env('ALERT_TELEGRAM_ENABLED', true),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'email' => [
        'enabled' => env('ALERT_EMAIL_ENABLED', true),
        'owner_email' => env('ALERT_OWNER_EMAIL'),
        'digest_enabled' => env('ALERT_DIGEST_ENABLED', true),
        'digest_frequency' => env('ALERT_DIGEST_FREQUENCY', 'daily'), // hourly, daily, weekly
    ],

    // ==================== SECURITY CONFIGURATION ====================

    'security' => [
        // Security alerts NEVER sent to regular users
        'owner_only' => true,
        
        // Always send security alerts (ignore quiet hours)
        'ignore_quiet_hours' => true,
        
        // Always notify via both channels for security
        'always_notify_both' => true,
    ],

    // ==================== LEVEL CHANNEL MAPPING ====================

    'level_channels' => [
        'critical' => ['telegram', 'email'],
        'warning' => ['telegram'],
        'info' => ['email'],
    ],

];
