<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Incident Response & Postmortem Framework
 * 
 * SEVERITY LEVELS:
 * - SEV-1: BAN massal / total outage (Respond: 5 min, Resolve: 1 hour)
 * - SEV-2: Delivery drop signifikan / partial outage (Respond: 15 min, Resolve: 4 hours)
 * - SEV-3: Degradasi performa / delay (Respond: 1 hour, Resolve: 24 hours)
 * - SEV-4: Minor issue / warning (Respond: 4 hours, Resolve: 72 hours)
 * 
 * LIFECYCLE:
 * detected → acknowledged → investigating → mitigating → resolved → postmortem_pending → closed
 * 
 * @author SRE & Incident Commander
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== ALERT RULES ====================
        // Detection rules for auto-creating incidents
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            
            // Alert type
            $table->string('alert_type', 50);  // delivery_rate, failure_spike, queue_backlog, webhook_error, risk_score, ban_detected
            $table->enum('severity', ['SEV-1', 'SEV-2', 'SEV-3', 'SEV-4'])->default('SEV-3');
            
            // Threshold configuration
            $table->string('metric', 100);  // delivery_rate, failure_rate, queue_size, etc.
            $table->string('operator', 10);  // <, >, <=, >=, ==, !=
            $table->decimal('threshold_value', 10, 2);
            $table->unsignedInteger('duration_seconds')->default(300);  // Sustained for X seconds
            $table->unsignedInteger('sample_size')->default(100);  // Minimum samples
            
            // Scope
            $table->string('scope', 30)->default('global');  // global, klien, sender, campaign
            $table->unsignedBigInteger('scope_id')->nullable();
            
            // Actions
            $table->boolean('auto_create_incident')->default(true);
            $table->boolean('auto_mitigate')->default(false);
            $table->json('mitigation_actions')->nullable();  // Auto actions to take
            
            // Escalation
            $table->unsignedInteger('escalation_minutes')->default(15);
            $table->string('escalation_channel', 50)->nullable();  // slack, pagerduty, email
            
            // Runbook
            $table->string('runbook_url', 500)->nullable();
            $table->text('quick_actions')->nullable();  // JSON array of quick actions
            
            // Deduplication
            $table->unsignedInteger('dedup_window_minutes')->default(30);  // Don't re-alert within X minutes
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            
            $table->timestamps();
            
            $table->index(['alert_type', 'is_active']);
            $table->index(['severity', 'is_active']);
        });

        // ==================== INCIDENTS ====================
        // Main incident record
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_id', 20)->unique();  // INC-20260130-001
            $table->uuid('uuid')->unique();
            
            // Classification
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->enum('severity', ['SEV-1', 'SEV-2', 'SEV-3', 'SEV-4']);
            $table->string('incident_type', 50);  // ban, outage, degradation, queue_overflow, webhook_failure
            
            // Status lifecycle
            $table->enum('status', [
                'detected',          // Auto-detected, not yet ack'd
                'acknowledged',      // Someone is looking
                'investigating',     // Actively investigating
                'mitigating',        // Mitigation in progress
                'resolved',          // Issue resolved
                'postmortem_pending', // Awaiting postmortem
                'closed'             // Postmortem complete
            ])->default('detected');
            
            // Ownership
            $table->unsignedBigInteger('detected_by')->nullable();  // System or user
            $table->unsignedBigInteger('commander_id')->nullable();  // Incident commander
            $table->unsignedBigInteger('assigned_to')->nullable();  // Primary responder
            $table->json('responders')->nullable();  // Array of user IDs
            
            // Impact
            $table->string('impact_scope', 50)->nullable();  // global, multiple_kliens, single_klien, single_sender
            $table->unsignedInteger('affected_kliens')->default(0);
            $table->unsignedInteger('affected_senders')->default(0);
            $table->unsignedInteger('affected_messages')->default(0);
            $table->decimal('estimated_revenue_impact', 12, 2)->default(0);
            $table->text('impact_description')->nullable();
            
            // Root cause (filled during/after postmortem)
            $table->string('root_cause_category', 50)->nullable();  // provider, internal, external, config, code
            $table->text('root_cause_description')->nullable();
            $table->json('root_cause_5_whys')->nullable();  // 5 Whys analysis
            
            // Trigger info
            $table->unsignedBigInteger('triggered_by_alert_id')->nullable();
            $table->json('trigger_context')->nullable();  // Metrics that triggered
            
            // Timestamps
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('investigation_started_at')->nullable();
            $table->timestamp('mitigation_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('postmortem_completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            // SLA tracking
            $table->unsignedInteger('time_to_acknowledge_seconds')->nullable();
            $table->unsignedInteger('time_to_mitigate_seconds')->nullable();
            $table->unsignedInteger('time_to_resolve_seconds')->nullable();
            $table->unsignedInteger('total_duration_seconds')->nullable();
            $table->boolean('sla_breached')->default(false);
            
            // External references
            $table->string('slack_channel', 100)->nullable();
            $table->string('slack_thread_ts', 50)->nullable();
            $table->string('jira_ticket', 50)->nullable();
            $table->string('pagerduty_incident_id', 50)->nullable();
            
            // Postmortem
            $table->text('postmortem_summary')->nullable();
            $table->text('what_went_well')->nullable();
            $table->text('what_went_wrong')->nullable();
            $table->text('detection_gap')->nullable();
            $table->text('lessons_learned')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'severity']);
            $table->index(['detected_at', 'status']);
            $table->index('commander_id');
            $table->index('incident_type');
        });

        // ==================== INCIDENT EVENTS (Timeline) ====================
        // Immutable timeline of incident events
        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('incident_id');
            
            // Event type
            $table->string('event_type', 50);  // status_change, action_taken, communication, metric_update, escalation
            $table->string('event_subtype', 50)->nullable();
            
            // Actor
            $table->string('actor_type', 20);  // user, system, automation
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            
            // Content
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();  // Additional structured data
            
            // Status change tracking
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            
            // Visibility
            $table->boolean('is_public')->default(false);  // Show on status page
            $table->boolean('is_internal')->default(true);
            
            $table->timestamp('occurred_at');
            $table->timestamps();
            
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('cascade');
            $table->index(['incident_id', 'occurred_at']);
            $table->index('event_type');
        });

        // ==================== INCIDENT ALERTS ====================
        // Alerts linked to incidents
        Schema::create('incident_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            $table->unsignedBigInteger('incident_id')->nullable();  // Can be orphan initially
            $table->unsignedBigInteger('alert_rule_id');
            
            // Alert info
            $table->enum('severity', ['SEV-1', 'SEV-2', 'SEV-3', 'SEV-4']);
            $table->string('title', 255);
            $table->text('description')->nullable();
            
            // Metrics
            $table->string('metric_name', 100);
            $table->decimal('metric_value', 15, 4);
            $table->decimal('threshold_value', 15, 4);
            $table->string('comparison', 50);  // "45.2% < 70% (threshold)"
            
            // Scope
            $table->string('scope', 30);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('context')->nullable();  // Additional context
            
            // Status
            $table->enum('status', ['firing', 'acknowledged', 'resolved', 'silenced'])->default('firing');
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            
            // Deduplication
            $table->string('dedup_key', 100);  // For grouping similar alerts
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_fired_at');
            $table->timestamp('last_fired_at');
            
            // Escalation
            $table->boolean('is_escalated')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedInteger('escalation_level')->default(0);
            
            $table->timestamps();
            
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('set null');
            $table->foreign('alert_rule_id')->references('id')->on('alert_rules');
            $table->index(['status', 'severity']);
            $table->index('dedup_key');
            $table->index(['first_fired_at', 'status']);
        });

        // ==================== INCIDENT ACTIONS (CAPA) ====================
        // Corrective and Preventive Actions
        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('incident_id');
            
            // Action type
            $table->enum('action_type', [
                'immediate',     // Immediate fix during incident
                'corrective',    // Fix the root cause
                'preventive',    // Prevent recurrence
                'detective',     // Improve detection
                'monitoring',    // Add/improve monitoring
            ]);
            
            // Action details
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('priority', ['P0', 'P1', 'P2', 'P3'])->default('P2');
            
            // Ownership
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('owner_team', 50)->nullable();
            
            // Status
            $table->enum('status', ['open', 'in_progress', 'completed', 'verified', 'cancelled'])->default('open');
            
            // Tracking
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('jira_ticket', 50)->nullable();
            $table->text('completion_notes')->nullable();
            
            // Verification
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
            $table->timestamps();
            
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('cascade');
            $table->index(['incident_id', 'action_type']);
            $table->index(['status', 'due_date']);
            $table->index('owner_id');
        });

        // ==================== INCIDENT COMMUNICATIONS ====================
        // Internal and external communications
        Schema::create('incident_communications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('incident_id');
            
            // Communication type
            $table->enum('comm_type', ['internal', 'external', 'status_page', 'email', 'slack']);
            $table->enum('audience', ['responders', 'stakeholders', 'customers', 'public']);
            
            // Content
            $table->string('subject', 255);
            $table->text('message');
            
            // Author
            $table->unsignedBigInteger('author_id');
            $table->string('author_name', 100);
            
            // Delivery
            $table->json('recipients')->nullable();
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            
            // Status page specific
            $table->string('status_page_state', 30)->nullable();  // investigating, identified, monitoring, resolved
            
            $table->timestamps();
            
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('cascade');
            $table->index(['incident_id', 'comm_type']);
        });

        // ==================== ON-CALL SCHEDULES ====================
        Schema::create('on_call_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('team', 50);  // platform, ops, dev
            
            // Schedule
            $table->unsignedBigInteger('primary_user_id');
            $table->unsignedBigInteger('secondary_user_id')->nullable();
            $table->unsignedBigInteger('escalation_user_id')->nullable();
            
            // Time range
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 50)->default('Asia/Jakarta');
            
            // Contact
            $table->string('primary_phone', 20)->nullable();
            $table->string('primary_slack', 50)->nullable();
            
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['team', 'starts_at', 'ends_at']);
            $table->index(['is_active', 'starts_at']);
        });

        // ==================== INCIDENT METRICS SNAPSHOT ====================
        // Capture metrics at time of incident
        Schema::create('incident_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            
            $table->string('metric_name', 100);
            $table->string('metric_source', 50);  // internal, provider, aggregate
            $table->decimal('value', 15, 4);
            $table->string('unit', 20)->nullable();
            
            // Context
            $table->string('scope', 30)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->json('dimensions')->nullable();  // Additional dimensions
            
            // Comparison
            $table->decimal('baseline_value', 15, 4)->nullable();
            $table->decimal('deviation_percent', 8, 2)->nullable();
            
            $table->timestamp('captured_at');
            $table->timestamps();
            
            $table->foreign('incident_id')->references('id')->on('incidents')->onDelete('cascade');
            $table->index(['incident_id', 'metric_name']);
        });

        // ==================== SEED ALERT RULES ====================
        $this->seedAlertRules();
    }

    protected function seedAlertRules(): void
    {
        $rules = [
            // ===== SEV-1: Critical =====
            [
                'code' => 'waba_ban_detected',
                'name' => 'WABA/Number BAN Detected',
                'description' => 'Detected BAN status from BSP or Meta',
                'alert_type' => 'ban_detected',
                'severity' => 'SEV-1',
                'metric' => 'ban_status',
                'operator' => '==',
                'threshold_value' => 1,
                'duration_seconds' => 0,
                'sample_size' => 1,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['pause_all_campaigns', 'switch_backup_sender']),
                'escalation_minutes' => 5,
                'dedup_window_minutes' => 60,
                'runbook_url' => '/runbooks/waba-ban',
                'priority' => 1,
            ],
            [
                'code' => 'total_outage',
                'name' => 'Total Sending Outage',
                'description' => 'No messages successfully sent in last 10 minutes',
                'alert_type' => 'outage',
                'severity' => 'SEV-1',
                'metric' => 'messages_sent_success',
                'operator' => '==',
                'threshold_value' => 0,
                'duration_seconds' => 600,
                'sample_size' => 50,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['pause_queue', 'check_provider_status']),
                'escalation_minutes' => 5,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/total-outage',
                'priority' => 2,
            ],
            [
                'code' => 'provider_outage',
                'name' => 'Provider Complete Outage',
                'description' => 'BSP/Provider returning 100% errors',
                'alert_type' => 'provider_outage',
                'severity' => 'SEV-1',
                'metric' => 'provider_error_rate',
                'operator' => '>=',
                'threshold_value' => 99,
                'duration_seconds' => 300,
                'sample_size' => 100,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['switch_provider', 'pause_queue']),
                'escalation_minutes' => 5,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/provider-outage',
                'priority' => 3,
            ],

            // ===== SEV-2: Major =====
            [
                'code' => 'delivery_rate_critical',
                'name' => 'Delivery Rate Critical Drop',
                'description' => 'Delivery rate dropped below 50%',
                'alert_type' => 'delivery_rate',
                'severity' => 'SEV-2',
                'metric' => 'delivery_rate',
                'operator' => '<',
                'threshold_value' => 50,
                'duration_seconds' => 600,
                'sample_size' => 200,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['reduce_throughput', 'alert_ops']),
                'escalation_minutes' => 15,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/delivery-drop',
                'priority' => 10,
            ],
            [
                'code' => 'failure_spike_critical',
                'name' => 'Failure Rate Spike (>40%)',
                'description' => 'Message failure rate exceeded 40%',
                'alert_type' => 'failure_spike',
                'severity' => 'SEV-2',
                'metric' => 'failure_rate',
                'operator' => '>',
                'threshold_value' => 40,
                'duration_seconds' => 300,
                'sample_size' => 100,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['throttle_global', 'investigate_errors']),
                'escalation_minutes' => 15,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/failure-spike',
                'priority' => 11,
            ],
            [
                'code' => 'queue_overflow',
                'name' => 'Queue Overflow Critical',
                'description' => 'Message queue exceeded 50,000 pending',
                'alert_type' => 'queue_backlog',
                'severity' => 'SEV-2',
                'metric' => 'queue_size',
                'operator' => '>',
                'threshold_value' => 50000,
                'duration_seconds' => 300,
                'sample_size' => 1,
                'auto_create_incident' => true,
                'auto_mitigate' => true,
                'mitigation_actions' => json_encode(['pause_new_campaigns', 'scale_workers']),
                'escalation_minutes' => 15,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/queue-overflow',
                'priority' => 12,
            ],
            [
                'code' => 'webhook_failure_critical',
                'name' => 'Webhook Processing Failure Critical',
                'description' => 'Webhook error rate exceeded 50%',
                'alert_type' => 'webhook_error',
                'severity' => 'SEV-2',
                'metric' => 'webhook_error_rate',
                'operator' => '>',
                'threshold_value' => 50,
                'duration_seconds' => 300,
                'sample_size' => 100,
                'auto_create_incident' => true,
                'escalation_minutes' => 15,
                'dedup_window_minutes' => 30,
                'runbook_url' => '/runbooks/webhook-failure',
                'priority' => 13,
            ],

            // ===== SEV-3: Moderate =====
            [
                'code' => 'delivery_rate_warning',
                'name' => 'Delivery Rate Below Threshold',
                'description' => 'Delivery rate dropped below 70%',
                'alert_type' => 'delivery_rate',
                'severity' => 'SEV-3',
                'metric' => 'delivery_rate',
                'operator' => '<',
                'threshold_value' => 70,
                'duration_seconds' => 900,
                'sample_size' => 300,
                'auto_create_incident' => false,
                'escalation_minutes' => 60,
                'dedup_window_minutes' => 60,
                'runbook_url' => '/runbooks/delivery-warning',
                'priority' => 20,
            ],
            [
                'code' => 'failure_rate_elevated',
                'name' => 'Failure Rate Elevated',
                'description' => 'Message failure rate exceeded 20%',
                'alert_type' => 'failure_spike',
                'severity' => 'SEV-3',
                'metric' => 'failure_rate',
                'operator' => '>',
                'threshold_value' => 20,
                'duration_seconds' => 600,
                'sample_size' => 200,
                'auto_create_incident' => false,
                'escalation_minutes' => 60,
                'dedup_window_minutes' => 60,
                'runbook_url' => '/runbooks/failure-elevated',
                'priority' => 21,
            ],
            [
                'code' => 'risk_score_aggregate_high',
                'name' => 'Aggregate Risk Score High',
                'description' => 'Platform-wide risk score exceeded threshold',
                'alert_type' => 'risk_score',
                'severity' => 'SEV-3',
                'metric' => 'aggregate_risk_score',
                'operator' => '>',
                'threshold_value' => 70,
                'duration_seconds' => 900,
                'sample_size' => 1,
                'auto_create_incident' => false,
                'escalation_minutes' => 60,
                'dedup_window_minutes' => 120,
                'runbook_url' => '/runbooks/risk-high',
                'priority' => 22,
            ],
            [
                'code' => 'queue_backlog_warning',
                'name' => 'Queue Backlog Warning',
                'description' => 'Message queue exceeded 20,000 pending',
                'alert_type' => 'queue_backlog',
                'severity' => 'SEV-3',
                'metric' => 'queue_size',
                'operator' => '>',
                'threshold_value' => 20000,
                'duration_seconds' => 600,
                'sample_size' => 1,
                'auto_create_incident' => false,
                'escalation_minutes' => 60,
                'dedup_window_minutes' => 60,
                'runbook_url' => '/runbooks/queue-warning',
                'priority' => 23,
            ],

            // ===== SEV-4: Minor =====
            [
                'code' => 'latency_elevated',
                'name' => 'Message Latency Elevated',
                'description' => 'Average message latency exceeded 30 seconds',
                'alert_type' => 'latency',
                'severity' => 'SEV-4',
                'metric' => 'avg_latency_seconds',
                'operator' => '>',
                'threshold_value' => 30,
                'duration_seconds' => 600,
                'sample_size' => 100,
                'auto_create_incident' => false,
                'escalation_minutes' => 240,
                'dedup_window_minutes' => 120,
                'runbook_url' => '/runbooks/latency',
                'priority' => 30,
            ],
            [
                'code' => 'reject_rate_elevated',
                'name' => 'Reject Rate Elevated',
                'description' => 'Message reject rate exceeded 10%',
                'alert_type' => 'reject_rate',
                'severity' => 'SEV-4',
                'metric' => 'reject_rate',
                'operator' => '>',
                'threshold_value' => 10,
                'duration_seconds' => 900,
                'sample_size' => 500,
                'auto_create_incident' => false,
                'escalation_minutes' => 240,
                'dedup_window_minutes' => 120,
                'runbook_url' => '/runbooks/reject-rate',
                'priority' => 31,
            ],
            [
                'code' => 'webhook_delay',
                'name' => 'Webhook Processing Delay',
                'description' => 'Webhook processing time exceeded 5 minutes',
                'alert_type' => 'webhook_delay',
                'severity' => 'SEV-4',
                'metric' => 'webhook_processing_delay_seconds',
                'operator' => '>',
                'threshold_value' => 300,
                'duration_seconds' => 600,
                'sample_size' => 50,
                'auto_create_incident' => false,
                'escalation_minutes' => 240,
                'dedup_window_minutes' => 120,
                'runbook_url' => '/runbooks/webhook-delay',
                'priority' => 32,
            ],
        ];

        $now = now();
        foreach ($rules as &$rule) {
            $rule['is_active'] = true;
            $rule['created_at'] = $now;
            $rule['updated_at'] = $now;
            
            // Ensure all fields have defaults
            $rule['scope'] = $rule['scope'] ?? 'global';
            $rule['scope_id'] = $rule['scope_id'] ?? null;
            $rule['auto_mitigate'] = $rule['auto_mitigate'] ?? false;
            $rule['mitigation_actions'] = $rule['mitigation_actions'] ?? null;
            $rule['escalation_channel'] = $rule['escalation_channel'] ?? null;
            $rule['quick_actions'] = $rule['quick_actions'] ?? null;
        }

        DB::table('alert_rules')->insert($rules);
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_metric_snapshots');
        Schema::dropIfExists('on_call_schedules');
        Schema::dropIfExists('incident_communications');
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('incident_alerts');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('alert_rules');
    }
};
