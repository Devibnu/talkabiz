<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Abuse Scoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure abuse detection, scoring rules, thresholds, and automatic
    | policy enforcement. All values are configurable to prevent hardcoding.
    |
    */

    // ==================== SCORE THRESHOLDS ====================
    
    /**
     * Abuse level thresholds based on cumulative score
     * 
     * Levels: none, low, medium, high, critical
     */
    'thresholds' => [
        'none' => [
            'min' => 0,
            'max' => 10,
        ],
        'low' => [
            'min' => 10,
            'max' => 30,
        ],
        'medium' => [
            'min' => 30,
            'max' => 60,
        ],
        'high' => [
            'min' => 60,
            'max' => 100,
        ],
        'critical' => [
            'min' => 100,
            'max' => PHP_INT_MAX,
        ],
    ],

    // ==================== POLICY ACTIONS ====================
    
    /**
     * Automatic policy enforcement per abuse level
     * 
     * Actions: none, throttle, require_approval, suspend
     */
    'policy_actions' => [
        'none' => 'none',
        'low' => 'none',
        'medium' => 'throttle',
        'high' => 'require_approval',
        'critical' => 'suspend',
    ],

    // ==================== SIGNAL WEIGHTS ====================
    
    /**
     * Score impact per event type
     * 
     * Higher = more severe violation
     */
    'signal_weights' => [
        // Usage-based signals
        'excessive_messages' => 15,
        'rate_limit_exceeded' => 20,
        'burst_activity' => 10,
        'quota_exceeded' => 12,
        
        // Pattern-based signals
        'suspicious_pattern' => 25,
        'spam_detected' => 30,
        'bot_like_behavior' => 35,
        'unusual_timing' => 8,
        
        // Security signals
        'multiple_failed_auth' => 40,
        'ip_change_rapid' => 15,
        'suspicious_payload' => 25,
        'api_abuse' => 30,
        
        // Violation signals
        'tos_violation' => 50,
        'copyright_violation' => 60,
        'fraud_detected' => 100,
        'illegal_content' => 100,
        
        // Manual signals
        'user_report' => 20,
        'admin_flag' => 50,
        'manual_review' => 0, // No auto-score, manual decision
    ],

    // ==================== RECIPIENT COMPLAINT WEIGHTS ====================
    
    /**
     * Score impact for recipient complaints
     * 
     * Different complaint types have different severity levels
     */
    'recipient_complaint_weights' => [
        // Complaint type weights
        'spam' => 25,              // Unsolicited messages
        'abuse' => 50,             // Harassment or threatening content
        'phishing' => 100,         // Fraudulent/scam attempts (CRITICAL)
        'inappropriate' => 35,     // Offensive content
        'frequency' => 20,         // Too many messages
        'other' => 15,             // Generic complaint
        
        // Severity multipliers (applied on top of base weight)
        'severity_multipliers' => [
            'low' => 1.0,          // No multiplier
            'medium' => 1.5,       // 50% increase
            'high' => 2.0,         // 100% increase (double score)
            'critical' => 3.0,     // 200% increase (triple score)
        ],
        
        // Source credibility multipliers
        'source_multipliers' => [
            'provider_webhook' => 1.0,   // Full weight (verified by provider)
            'manual_report' => 0.8,      // 80% weight (needs verification)
            'internal_flag' => 0.9,      // 90% weight (system flagged)
            'third_party' => 0.7,        // 70% weight (external source)
        ],
        
        // Provider-specific adjustments (some providers have stricter criteria)
        'provider_multipliers' => [
            'gupshup' => 1.0,
            'twilio' => 1.0,
            'vonage' => 1.0,
            'default' => 1.0,
        ],
    ],

    // ==================== COMPLAINT ESCALATION ====================
    
    /**
     * Automatic escalation rules based on complaint patterns
     */
    'complaint_escalation' => [
        'enabled' => true,
        
        // Escalate immediately for critical complaint types
        'critical_types' => ['phishing', 'abuse'],
        'critical_action' => 'suspend', // Action to take for critical complaints
        
        // Volume-based escalation
        'volume_thresholds' => [
            'auto_suspend_count' => 10,      // Auto-suspend after X complaints in window
            'require_approval_count' => 5,   // Require approval after X complaints
            'high_risk_count' => 3,          // High risk flag after X complaints
            'window_days' => 30,             // Time window for counting
        ],
        
        // Pattern-based escalation
        'pattern_detection' => [
            'same_recipient_count' => 3,      // Flag if same recipient complains X times
            'same_type_count' => 5,           // Flag if X complaints of same type
            'multiple_sources_count' => 2,    // Flag if complaints from multiple sources
        ],
        
        // Auto-actions
        'auto_actions' => [
            'create_abuse_event' => true,     // Automatically create abuse_events record
            'notify_admin' => true,           // Send notification to admin
            'notify_klien' => false,          // Don't notify klien (avoid tipping off abusers)
            'log_to_audit' => true,           // Log to audit trail
        ],
        
        // Rate limiting after complaints
        'apply_rate_limit' => true,
        'rate_limit_severity' => 'high', // Apply high-risk rate limiting after complaints
    ],

    // ==================== COMPLAINT PROCESSING ====================
    
    /**
     * How complaints are processed and scored
     */
    'complaint_processing' => [
        'auto_process' => true,           // Automatically process complaints
        'require_manual_review' => false, // Set true to require admin review before scoring
        
        // Duplicate detection
        'deduplicate_enabled' => true,
        'deduplicate_window_hours' => 24, // Ignore duplicate complaints within X hours
        'deduplicate_fields' => ['klien_id', 'recipient_phone', 'complaint_type'],
        
        // Score calculation
        'cumulative_scoring' => true,     // Add to existing score (vs replace)
        'apply_decay_to_complaints' => true, // Allow complaint scores to decay over time
        
        // Immediate effects
        'immediate_enforcement' => true,  // Apply policy actions immediately
        'immediate_rate_limit' => true,   // Apply rate limits immediately
        
        // Metadata tracking
        'track_complaint_metadata' => true,
        'store_message_sample' => true,   // Store first 500 chars of complained message
        'max_message_sample_length' => 500,
    ],

    // ==================== THROTTLE LIMITS ====================
    
    /**
     * Rate limits when throttle policy is active
     */
    'throttle_limits' => [
        'messages_per_minute' => 5,
        'messages_per_hour' => 100,
        'api_calls_per_minute' => 10,
        'campaigns_per_day' => 2,
    ],

    // ==================== DECAY SETTINGS ====================
    
    /**
     * Score decay configuration
     * 
     * Score decreases over time when no new violations occur
     */
    'decay' => [
        'enabled' => true,
        'rate_per_day' => 2, // Points to decay per day
        'min_days_without_event' => 3, // Wait X days before starting decay
        'min_score' => 0, // Never decay below this
        'max_decay_per_run' => 10, // Max points to decay in single run
    ],

    // ==================== AUTO-SUSPENSION ====================
    
    /**
     * Automatic suspension triggers
     */
    'auto_suspend' => [
        'enabled' => true,
        'score_threshold' => 100, // Auto-suspend at this score
        'critical_events_count' => 3, // Auto-suspend after X critical events in window
        'critical_events_window_hours' => 24,
    ],

    // ==================== SUSPENSION COOLDOWN ====================
    
    /**
     * Temporary suspension and auto-unlock settings
     */
    'suspension_cooldown' => [
        'enabled' => true,
        'default_temp_suspension_days' => 7, // Default cooldown period for temporary suspensions
        'auto_unlock_enabled' => true, // Enable automatic unlocking
        'auto_unlock_score_threshold' => 30, // Score must be below this to auto-unlock
        'require_score_improvement' => true, // Score must have decreased from suspension
        'min_cooldown_days' => 3, // Minimum cooldown period
        'max_cooldown_days' => 30, // Maximum cooldown period
        'check_frequency' => 'daily', // How often to check for auto-unlock (daily/hourly)
        'approval_on_unlock' => false, // Require approval after unlock
        'notify_on_unlock' => true, // Send notification when auto-unlocked
        'log_all_checks' => true, // Log every check (for debugging)
    ],

    // ==================== MONITORING ====================
    
    /**
     * Monitoring and alerting thresholds
     */
    'monitoring' => [
        'alert_on_high_risk' => true,
        'alert_on_critical' => true,
        'alert_on_auto_suspend' => true,
        'daily_digest' => true,
        'log_all_events' => true,
    ],

    // ==================== GRACE PERIODS ====================
    
    /**
     * Grace periods for new accounts
     */
    'grace_period' => [
        'enabled' => true,
        'days' => 7, // Days after registration
        'reduced_scoring' => true, // 50% score impact during grace
        'multiplier' => 0.5,
    ],

    // ==================== WHITELISTING ====================
    
    /**
     * Abuse scoring exemptions
     */
    'whitelist' => [
        'business_types' => ['pt', 'cv'], // Lower risk business types
        'score_multiplier' => 0.7, // 30% reduction in score impact
    ],

    // ==================== EVENT RETENTION ====================
    
    /**
     * How long to keep abuse events
     */
    'retention' => [
        'events_days' => 365, // Keep events for 1 year
        'scores_days' => null, // Keep scores forever (null = no deletion)
        'cleanup_enabled' => true,
    ],

    // ==================== NOTIFICATION SETTINGS ====================
    
    /**
     * Who to notify when certain thresholds are reached
     */
    'notifications' => [
        'admin_email' => env('ABUSE_ADMIN_EMAIL', 'admin@talkabiz.com'),
        'notify_on_high_risk' => true,
        'notify_on_critical' => true,
        'notify_on_suspension' => true,
        'notify_klien_on_suspend' => true,
    ],

    // ==================== REVIEW REQUIREMENTS ====================
    
    /**
     * When admin review is required
     */
    'review_required' => [
        'critical_events' => true,
        'auto_suspend' => true,
        'high_risk_level' => true,
        'multiple_violations_count' => 5, // Review after X violations
    ],

    // ==================== SCORING WINDOWS ====================
    
    /**
     * Time windows for calculating event frequency
     */
    'windows' => [
        'short_term_minutes' => 60, // 1 hour
        'medium_term_hours' => 24, // 1 day
        'long_term_days' => 7, // 1 week
    ],

    // ==================== ENFORCEMENT ====================
    
    /**
     * Enforcement settings
     */
    'enforcement' => [
        'block_suspended' => true, // Block all actions from suspended klien
        'require_approval_immediate' => true, // Immediately enforce approval requirement
        'throttle_immediate' => true, // Immediately apply throttle limits
        'bypass_roles' => ['owner', 'super_admin'], // Roles that bypass abuse checks
    ],

];
