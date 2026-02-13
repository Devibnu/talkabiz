<?php

/**
 * =============================================================================
 * CHAOS TESTING CONFIGURATION
 * =============================================================================
 * 
 * Konfigurasi untuk Chaos Testing Framework.
 * 
 * PENTING: Chaos testing TIDAK BOLEH dijalankan di production!
 * 
 * =============================================================================
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Environments
    |--------------------------------------------------------------------------
    |
    | Daftar environment yang diizinkan untuk menjalankan chaos testing.
    | Production TIDAK BOLEH ada di daftar ini.
    |
    */
    'allowed_environments' => [
        'local',
        'staging',
        'canary',
        'testing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Experiment Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        // Default duration in seconds (5 minutes)
        'duration' => 300,

        // Maximum duration in seconds (15 minutes)
        'max_duration' => 900,

        // Monitoring interval in seconds
        'monitoring_interval' => 10,

        // Auto-approval for non-production
        'auto_approve_non_production' => true,

        // Require approval for these scenarios
        'require_approval' => [
            'outage-whatsapp-timeout',
            'failure-worker-crash',
            'failure-redis-unavailable',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrail Settings
    |--------------------------------------------------------------------------
    */
    'guardrails' => [
        // Maximum error rate before auto-stop (percentage)
        'max_error_rate' => 50,

        // Maximum queue depth before warning
        'max_queue_depth' => 100000,

        // Maximum memory usage before warning (percentage)
        'max_memory_usage' => 90,

        // Maximum concurrent incidents
        'max_incidents' => 3,

        // User impact threshold (0 = no real users affected)
        'max_user_impact' => 0,

        // Block if production traffic detected
        'block_production_traffic' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    */
    'alerting' => [
        // Slack webhook for chaos alerts
        'slack_webhook_url' => env('CHAOS_SLACK_WEBHOOK_URL'),

        // Email for chaos alerts
        'alert_email' => env('CHAOS_ALERT_EMAIL', 'ops@talkabiz.com'),

        // Alert on experiment start
        'alert_on_start' => true,

        // Alert on experiment end
        'alert_on_end' => true,

        // Alert on guardrail trigger
        'alert_on_guardrail' => true,

        // Alert on emergency rollback
        'alert_on_emergency' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock Response Settings
    |--------------------------------------------------------------------------
    */
    'mocks' => [
        'whatsapp' => [
            // Default rejection rate for mass rejection simulation (percentage)
            'default_rejection_rate' => 60,

            // Timeout duration in seconds
            'timeout_seconds' => 30,

            // Webhook delay in seconds for delay simulation
            'webhook_delay_seconds' => 300,
        ],

        'payment' => [
            // Payment callback delay in seconds
            'callback_delay_seconds' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Injection Settings
    |--------------------------------------------------------------------------
    */
    'injection' => [
        // Default failure probability (percentage)
        'default_failure_rate' => 30,

        // Default delay in milliseconds
        'default_delay_ms' => 1000,

        // Queue backlog delay in seconds
        'queue_backlog_delay' => 5,

        // Database lock wait timeout in seconds
        'db_lock_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Collection
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        // How often to collect metrics during experiment (seconds)
        'collection_interval' => 10,

        // Metrics to collect
        'collect' => [
            'delivery_rate',
            'rejection_rate',
            'failure_rate',
            'queue_depth',
            'queue_latency',
            'risk_score',
            'memory_usage',
            'api_response_time',
            'incident_count',
            'auto_pause_count',
            'auto_throttle_count',
        ],

        // Thresholds for anomaly detection
        'anomaly_thresholds' => [
            'delivery_rate_drop' => 20,      // % drop from baseline
            'failure_rate_increase' => 100,  // % increase from baseline
            'queue_depth_increase' => 200,   // % increase from baseline
            'response_time_increase' => 300, // % increase from baseline
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Settings
    |--------------------------------------------------------------------------
    */
    'reports' => [
        // Where to store report files
        'storage_path' => 'chaos/reports',

        // Auto-generate report on experiment end
        'auto_generate' => true,

        // Include detailed event log
        'include_events' => true,

        // Include metrics history
        'include_metrics_history' => true,

        // Report formats to generate
        'formats' => ['json'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        // Dedicated queue for chaos jobs
        'name' => 'chaos',

        // Queue connection
        'connection' => env('CHAOS_QUEUE_CONNECTION', 'redis'),

        // Retry attempts for chaos jobs
        'retry_attempts' => 1,

        // Timeout for chaos jobs (seconds)
        'timeout' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scenario Categories
    |--------------------------------------------------------------------------
    */
    'categories' => [
        'ban_simulation' => [
            'label' => 'Ban Simulation',
            'description' => 'Simulasi kondisi yang menyebabkan akun ter-ban',
            'color' => 'red',
            'icon' => '🚫',
        ],
        'outage_simulation' => [
            'label' => 'Outage Simulation',
            'description' => 'Simulasi gangguan layanan eksternal',
            'color' => 'orange',
            'icon' => '⚡',
        ],
        'internal_failure' => [
            'label' => 'Internal Failure',
            'description' => 'Simulasi kegagalan sistem internal',
            'color' => 'yellow',
            'icon' => '🔧',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Levels
    |--------------------------------------------------------------------------
    */
    'severity_levels' => [
        'low' => [
            'label' => 'Low',
            'color' => 'green',
            'max_blast_radius' => 5,
        ],
        'medium' => [
            'label' => 'Medium',
            'color' => 'yellow',
            'max_blast_radius' => 15,
        ],
        'high' => [
            'label' => 'High',
            'color' => 'orange',
            'max_blast_radius' => 30,
        ],
        'critical' => [
            'label' => 'Critical',
            'color' => 'red',
            'max_blast_radius' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Features
    |--------------------------------------------------------------------------
    */
    'safety' => [
        // Automatically disable all flags after this duration (seconds)
        'auto_disable_after' => 1800,

        // Kill switch - if true, ALL chaos is disabled
        'kill_switch' => env('CHAOS_KILL_SWITCH', false),

        // Require confirmation for high severity scenarios
        'require_confirmation_for_high_severity' => true,

        // Lock file path - prevent concurrent experiments
        'lock_file' => storage_path('chaos/experiment.lock'),

        // Maximum concurrent experiments
        'max_concurrent_experiments' => 1,
    ],

];
