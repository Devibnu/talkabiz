<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Abuse Detection & Auto Suspend System
 * 
 * Tables:
 * - abuse_rules: Configurable detection rules
 * - abuse_events: Append-only abuse incident log
 * - user_restrictions: Current user restriction state
 * - suspension_history: Complete suspension audit trail
 * 
 * @author Trust & Safety Lead
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== ABUSE RULES ====================
        // Configurable rules for abuse detection
        Schema::create('abuse_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            
            // Rule configuration
            $table->string('signal_type', 50);  // rate_limit, failure_ratio, volume_spike, etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->json('thresholds');  // {"count": 5, "window_minutes": 60, "ratio": 0.15}
            $table->json('applies_to')->nullable();  // ["umkm", "corporate"] or null for all
            
            // Scoring
            $table->unsignedInteger('abuse_points')->default(10);
            $table->boolean('auto_action')->default(true);
            $table->string('action_type', 50)->nullable();  // warn, throttle, pause, suspend
            
            // Cooldown before rule can trigger again
            $table->unsignedInteger('cooldown_minutes')->default(60);
            
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            
            $table->timestamps();
            
            $table->index(['signal_type', 'is_active']);
            $table->index(['severity', 'is_active']);
        });

        // ==================== ABUSE EVENTS ====================
        // Append-only log of detected abuse incidents
        Schema::create('abuse_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            
            // Entity
            $table->unsignedBigInteger('klien_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entity_type', 20)->default('user');  // user, sender, campaign
            $table->unsignedBigInteger('entity_id')->nullable();
            
            // Rule that triggered
            $table->unsignedBigInteger('abuse_rule_id')->nullable();
            $table->string('rule_code', 50);
            
            // Detection details
            $table->string('signal_type', 50);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->unsignedInteger('abuse_points');
            
            // Evidence (append-only, never modify)
            $table->json('evidence');  // {"metric_value": 0.25, "threshold": 0.15, ...}
            $table->text('description');
            
            // Action taken
            $table->string('action_taken', 50)->nullable();
            $table->boolean('auto_action')->default(false);
            $table->boolean('admin_reviewed')->default(false);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            
            // Source
            $table->string('detection_source', 50);  // realtime, scheduled, webhook, manual
            $table->string('trigger_event', 100)->nullable();
            
            $table->timestamp('detected_at');
            $table->timestamps();
            
            $table->index(['klien_id', 'detected_at']);
            $table->index(['severity', 'detected_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('rule_code');
            $table->index('admin_reviewed');
        });

        // ==================== USER RESTRICTIONS ====================
        // Current restriction state for each user
        Schema::create('user_restrictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klien_id')->unique();
            
            // State Machine Status
            $table->enum('status', [
                'active',      // Normal operation
                'warned',      // Has warning, still operational
                'throttled',   // Rate reduced
                'paused',      // Campaign paused, no sending
                'suspended',   // Fully suspended
                'restored'     // Recovered from suspension
            ])->default('active');
            
            $table->string('previous_status', 20)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_reason', 255)->nullable();
            
            // Abuse tracking
            $table->unsignedInteger('total_abuse_points')->default(0);
            $table->unsignedInteger('active_abuse_points')->default(0);  // After decay
            $table->unsignedInteger('incident_count_30d')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('suspension_count')->default(0);
            
            // Current restrictions
            $table->float('throttle_multiplier')->default(1.0);  // 1.0 = normal, 0.5 = half
            $table->boolean('can_send')->default(true);
            $table->boolean('can_create_campaign')->default(true);
            
            // Timing
            $table->timestamp('restriction_expires_at')->nullable();
            $table->timestamp('last_incident_at')->nullable();
            $table->timestamp('last_evaluation_at')->nullable();
            $table->unsignedInteger('clean_days')->default(0);  // Days without incident
            
            // Admin override
            $table->boolean('admin_override')->default(false);
            $table->string('override_type', 20)->nullable();  // whitelist, blacklist
            $table->unsignedBigInteger('override_by')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamp('override_expires_at')->nullable();
            
            // User type for different rules
            $table->string('user_tier', 20)->default('umkm');  // umkm, corporate, enterprise
            
            $table->timestamps();
            
            $table->index(['status', 'updated_at']);
            $table->index('active_abuse_points');
            $table->index('restriction_expires_at');
        });

        // ==================== SUSPENSION HISTORY ====================
        // Complete audit trail of all suspensions
        Schema::create('suspension_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('suspension_uuid')->unique();
            $table->unsignedBigInteger('klien_id');
            
            // What triggered this
            $table->unsignedBigInteger('abuse_event_id')->nullable();
            $table->string('trigger_rule', 50)->nullable();
            
            // Suspension details
            $table->enum('action_type', ['warn', 'throttle', 'pause', 'suspend']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->string('status_before', 20);
            $table->string('status_after', 20);
            
            // Evidence snapshot (immutable for audit)
            $table->json('evidence_snapshot');
            $table->text('reason');
            
            // Duration
            $table->unsignedInteger('duration_hours')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            
            // Resolution
            $table->enum('resolution', ['expired', 'admin_lifted', 'auto_recovered', 'escalated', 'pending'])->default('pending');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            
            // Notification
            $table->boolean('user_notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->boolean('admin_notified')->default(false);
            
            // Audit
            $table->boolean('is_auto')->default(true);
            $table->string('applied_by', 50)->default('system');  // system, admin email
            $table->ipAddress('ip_address')->nullable();
            
            $table->timestamps();
            
            $table->index(['klien_id', 'started_at']);
            $table->index(['action_type', 'resolution']);
            $table->index('expires_at');
            $table->index('resolution');
        });

        // ==================== SEED DEFAULT ABUSE RULES ====================
        $this->seedDefaultRules();
    }

    protected function seedDefaultRules(): void
    {
        $rules = [
            // ===== RATE LIMIT VIOLATIONS =====
            [
                'code' => 'rate_limit_exceeded',
                'name' => 'Rate Limit Exceeded',
                'description' => 'User exceeded message rate limit multiple times in window',
                'signal_type' => 'rate_limit',
                'severity' => 'low',
                'thresholds' => json_encode([
                    'count' => 3,           // 3 violations
                    'window_minutes' => 60, // in 1 hour
                ]),
                'abuse_points' => 5,
                'auto_action' => true,
                'action_type' => 'warn',
                'cooldown_minutes' => 120,
                'priority' => 100,
            ],
            [
                'code' => 'rate_limit_persistent',
                'name' => 'Persistent Rate Limit Violation',
                'description' => 'User persistently hitting rate limits',
                'signal_type' => 'rate_limit',
                'severity' => 'medium',
                'thresholds' => json_encode([
                    'count' => 10,
                    'window_minutes' => 180,
                ]),
                'abuse_points' => 15,
                'auto_action' => true,
                'action_type' => 'throttle',
                'cooldown_minutes' => 360,
                'priority' => 90,
            ],

            // ===== FAILURE RATIO =====
            [
                'code' => 'high_failure_ratio',
                'name' => 'High Message Failure Ratio',
                'description' => 'Too many message failures in window',
                'signal_type' => 'failure_ratio',
                'severity' => 'medium',
                'thresholds' => json_encode([
                    'ratio' => 0.15,         // 15% failure
                    'min_messages' => 50,    // minimum sample
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 15,
                'auto_action' => true,
                'action_type' => 'throttle',
                'cooldown_minutes' => 120,
                'priority' => 85,
            ],
            [
                'code' => 'extreme_failure_ratio',
                'name' => 'Extreme Message Failure Ratio',
                'description' => 'Extremely high failure rate indicates abuse or bad list',
                'signal_type' => 'failure_ratio',
                'severity' => 'high',
                'thresholds' => json_encode([
                    'ratio' => 0.30,
                    'min_messages' => 50,
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 30,
                'auto_action' => true,
                'action_type' => 'pause',
                'cooldown_minutes' => 360,
                'priority' => 80,
            ],

            // ===== REJECTION RATIO =====
            [
                'code' => 'high_reject_ratio',
                'name' => 'High Rejection Ratio',
                'description' => 'WhatsApp rejecting too many messages',
                'signal_type' => 'reject_ratio',
                'severity' => 'high',
                'thresholds' => json_encode([
                    'ratio' => 0.10,         // 10% rejection is serious
                    'min_messages' => 30,
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 25,
                'auto_action' => true,
                'action_type' => 'pause',
                'cooldown_minutes' => 240,
                'priority' => 75,
            ],
            [
                'code' => 'critical_reject_ratio',
                'name' => 'Critical Rejection Ratio',
                'description' => 'Critical rejection rate - immediate intervention needed',
                'signal_type' => 'reject_ratio',
                'severity' => 'critical',
                'thresholds' => json_encode([
                    'ratio' => 0.25,
                    'min_messages' => 20,
                    'window_minutes' => 30,
                ]),
                'abuse_points' => 50,
                'auto_action' => true,
                'action_type' => 'suspend',
                'cooldown_minutes' => 720,
                'priority' => 70,
            ],

            // ===== VOLUME SPIKE =====
            [
                'code' => 'volume_spike_moderate',
                'name' => 'Moderate Volume Spike',
                'description' => 'Sudden increase in sending volume',
                'signal_type' => 'volume_spike',
                'severity' => 'low',
                'thresholds' => json_encode([
                    'spike_multiplier' => 3,  // 3x normal
                    'baseline_days' => 7,
                ]),
                'abuse_points' => 5,
                'auto_action' => true,
                'action_type' => 'warn',
                'cooldown_minutes' => 240,
                'priority' => 95,
            ],
            [
                'code' => 'volume_spike_extreme',
                'name' => 'Extreme Volume Spike',
                'description' => 'Massive volume increase without history',
                'signal_type' => 'volume_spike',
                'severity' => 'high',
                'thresholds' => json_encode([
                    'spike_multiplier' => 10,
                    'baseline_days' => 7,
                    'min_daily_baseline' => 100,  // only for users with baseline
                ]),
                'abuse_points' => 25,
                'auto_action' => true,
                'action_type' => 'pause',
                'cooldown_minutes' => 360,
                'priority' => 78,
            ],

            // ===== TEMPLATE ABUSE =====
            [
                'code' => 'template_no_personalization',
                'name' => 'Template Without Personalization',
                'description' => 'Sending same content to many recipients',
                'signal_type' => 'template_abuse',
                'severity' => 'medium',
                'thresholds' => json_encode([
                    'unique_ratio' => 0.05,   // < 5% unique content
                    'min_messages' => 100,
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 15,
                'auto_action' => true,
                'action_type' => 'warn',
                'cooldown_minutes' => 180,
                'priority' => 88,
            ],

            // ===== RETRY ABUSE =====
            [
                'code' => 'excessive_retry',
                'name' => 'Excessive Message Retry',
                'description' => 'Repeatedly retrying failed messages',
                'signal_type' => 'retry_abuse',
                'severity' => 'medium',
                'thresholds' => json_encode([
                    'retry_count' => 5,
                    'same_recipient' => true,
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 20,
                'auto_action' => true,
                'action_type' => 'throttle',
                'cooldown_minutes' => 120,
                'priority' => 82,
            ],

            // ===== OFF-HOURS SENDING =====
            [
                'code' => 'offhours_heavy',
                'name' => 'Heavy Off-Hours Sending',
                'description' => 'High volume during off-business hours',
                'signal_type' => 'offhours',
                'severity' => 'low',
                'thresholds' => json_encode([
                    'offhours_ratio' => 0.50,  // 50% messages off-hours
                    'min_messages' => 100,
                    'offhours_start' => 22,
                    'offhours_end' => 6,
                ]),
                'abuse_points' => 10,
                'auto_action' => true,
                'action_type' => 'warn',
                'cooldown_minutes' => 480,
                'priority' => 92,
            ],

            // ===== RISK SCORE TRIGGERS =====
            [
                'code' => 'risk_score_high',
                'name' => 'High Risk Score Detected',
                'description' => 'Anti-ban system detected high risk',
                'signal_type' => 'risk_score',
                'severity' => 'high',
                'thresholds' => json_encode([
                    'score_threshold' => 61,  // HIGH_RISK level
                ]),
                'abuse_points' => 25,
                'auto_action' => true,
                'action_type' => 'pause',
                'cooldown_minutes' => 240,
                'priority' => 50,
            ],
            [
                'code' => 'risk_score_critical',
                'name' => 'Critical Risk Score',
                'description' => 'Anti-ban system detected critical risk',
                'signal_type' => 'risk_score',
                'severity' => 'critical',
                'thresholds' => json_encode([
                    'score_threshold' => 81,  // CRITICAL level
                ]),
                'abuse_points' => 50,
                'auto_action' => true,
                'action_type' => 'suspend',
                'cooldown_minutes' => 720,
                'priority' => 40,
            ],

            // ===== BLOCK/REPORT =====
            [
                'code' => 'user_blocks',
                'name' => 'Recipients Blocking Sender',
                'description' => 'Recipients are blocking the sender number',
                'signal_type' => 'block_report',
                'severity' => 'critical',
                'thresholds' => json_encode([
                    'block_count' => 3,
                    'window_minutes' => 60,
                ]),
                'abuse_points' => 40,
                'auto_action' => true,
                'action_type' => 'suspend',
                'cooldown_minutes' => 720,
                'priority' => 30,
            ],
        ];

        $now = now();
        foreach ($rules as &$rule) {
            $rule['created_at'] = $now;
            $rule['updated_at'] = $now;
            $rule['is_active'] = true;
            $rule['applies_to'] = null;
        }

        DB::table('abuse_rules')->insert($rules);
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_history');
        Schema::dropIfExists('user_restrictions');
        Schema::dropIfExists('abuse_events');
        Schema::dropIfExists('abuse_rules');
    }
};
