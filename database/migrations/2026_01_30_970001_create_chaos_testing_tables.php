<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * =============================================================================
 * CHAOS TESTING FRAMEWORK - DATABASE SCHEMA
 * =============================================================================
 * 
 * Tables untuk Chaos Engineering:
 * - chaos_experiments: Experiment definitions
 * - chaos_scenarios: Predefined failure scenarios
 * - chaos_experiment_results: Result & metrics per experiment run
 * - chaos_event_logs: Detailed event log during experiment
 * - chaos_guardrails: Safety limits & auto-rollback conditions
 * - chaos_flags: Feature flags for chaos injection
 * 
 * =============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== CHAOS SCENARIOS ====================
        // Predefined chaos scenarios (BAN, OUTAGE, FAILURE)
        Schema::create('chaos_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('category', 50);
            // ban_simulation, outage_simulation, internal_failure
            $table->text('description');
            $table->text('hypothesis'); // What should happen
            $table->json('blast_radius'); // Components affected
            $table->json('injection_config'); // How to inject failure
            $table->json('success_criteria'); // Metrics to validate
            $table->json('safety_guards'); // Limits & boundaries
            $table->json('rollback_conditions'); // When to auto-stop
            $table->integer('estimated_duration_seconds')->default(300);
            $table->string('severity', 20)->default('medium');
            // low, medium, high, critical
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['category', 'is_active']);
            $table->index('severity');
        });

        // ==================== CHAOS EXPERIMENTS ====================
        // Individual experiment runs
        Schema::create('chaos_experiments', function (Blueprint $table) {
            $table->id();
            $table->string('experiment_id', 50)->unique();
            $table->foreignId('scenario_id')->constrained('chaos_scenarios')->onDelete('restrict');
            $table->string('status', 30)->default('pending');
            // pending, approved, running, paused, completed, aborted, rolled_back
            $table->string('environment', 30)->default('staging');
            // staging, canary, production (production BLOCKED)
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('initiated_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->json('config_override')->nullable();
            $table->json('baseline_metrics')->nullable(); // Before experiment
            $table->json('experiment_metrics')->nullable(); // During experiment
            $table->json('final_metrics')->nullable(); // After experiment
            $table->text('notes')->nullable();
            $table->text('abort_reason')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'scheduled_at']);
            $table->index(['scenario_id', 'status']);
            $table->index('environment');
        });

        // ==================== EXPERIMENT RESULTS ====================
        // Detailed results & analysis
        Schema::create('chaos_experiment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained('chaos_experiments')->onDelete('cascade');
            $table->string('result_type', 50); // overall, component, metric, validation
            $table->string('component', 100)->nullable();
            $table->string('metric_name', 100)->nullable();
            $table->string('status', 30);
            // passed, failed, degraded, inconclusive
            $table->decimal('baseline_value', 12, 4)->nullable();
            $table->decimal('experiment_value', 12, 4)->nullable();
            $table->decimal('deviation_percent', 8, 2)->nullable();
            $table->decimal('threshold', 12, 4)->nullable();
            $table->text('observation')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
            
            $table->index(['experiment_id', 'result_type']);
            $table->index(['component', 'metric_name']);
        });

        // ==================== CHAOS EVENT LOGS ====================
        // Real-time event log during experiment
        Schema::create('chaos_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained('chaos_experiments')->onDelete('cascade');
            $table->string('event_type', 50);
            // injection_started, injection_stopped, auto_mitigation, 
            // threshold_breach, guardrail_triggered, system_response,
            // metric_recorded, anomaly_detected
            $table->string('component', 100)->nullable();
            $table->string('severity', 20)->default('info');
            // debug, info, warning, error, critical
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            
            $table->index(['experiment_id', 'occurred_at']);
            $table->index(['event_type', 'severity']);
        });

        // ==================== CHAOS GUARDRAILS ====================
        // Safety limits & auto-rollback conditions
        Schema::create('chaos_guardrails', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('guardrail_type', 50);
            // metric_threshold, time_limit, error_rate, user_impact
            $table->string('metric', 100);
            $table->string('operator', 20); // >, <, >=, <=, ==, !=
            $table->decimal('threshold', 12, 4);
            $table->string('action', 50)->default('abort');
            // warn, pause, abort, rollback
            $table->boolean('is_global')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index(['guardrail_type', 'is_active']);
        });

        // ==================== CHAOS FLAGS ====================
        // Feature flags for controlled chaos injection
        Schema::create('chaos_flags', function (Blueprint $table) {
            $table->id();
            $table->string('flag_key', 100)->unique();
            $table->string('flag_type', 50);
            // mock_response, inject_failure, delay, timeout, 
            // drop_webhook, kill_worker, cache_unavailable
            $table->string('target_component', 100)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            // percentage, delay_ms, error_code, mock_payload
            $table->foreignId('experiment_id')->nullable()->constrained('chaos_experiments')->onDelete('set null');
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('enabled_by')->nullable();
            $table->timestamps();
            
            $table->index(['is_enabled', 'expires_at']);
            $table->index('target_component');
        });

        // ==================== CHAOS MOCK RESPONSES ====================
        // Mock responses for provider simulation
        Schema::create('chaos_mock_responses', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            // whatsapp, midtrans, webhook_receiver
            $table->string('endpoint', 200);
            $table->string('method', 10)->default('POST');
            $table->string('scenario_type', 50);
            // ban, rejected, timeout, rate_limited, quality_downgrade
            $table->integer('http_status')->default(200);
            $table->json('response_body');
            $table->json('response_headers')->nullable();
            $table->integer('delay_ms')->default(0);
            $table->decimal('probability', 5, 2)->default(100.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['provider', 'scenario_type', 'is_active']);
        });

        // ==================== CHAOS INJECTION HISTORY ====================
        // History of all chaos injections
        Schema::create('chaos_injection_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->nullable()->constrained('chaos_experiments')->onDelete('set null');
            $table->string('flag_key', 100);
            $table->string('injection_type', 50);
            $table->string('target', 200);
            $table->json('config');
            $table->string('action', 30); // enabled, disabled, modified
            $table->unsignedBigInteger('performed_by');
            $table->timestamp('performed_at');
            $table->timestamps();
            
            $table->index(['experiment_id', 'performed_at']);
            $table->index(['injection_type', 'performed_at']);
        });

        // ==================== SEED DEFAULT SCENARIOS ====================
        $this->seedDefaultScenarios();
        $this->seedDefaultGuardrails();
        $this->seedDefaultMockResponses();
    }

    public function down(): void
    {
        Schema::dropIfExists('chaos_injection_history');
        Schema::dropIfExists('chaos_mock_responses');
        Schema::dropIfExists('chaos_flags');
        Schema::dropIfExists('chaos_guardrails');
        Schema::dropIfExists('chaos_event_logs');
        Schema::dropIfExists('chaos_experiment_results');
        Schema::dropIfExists('chaos_experiments');
        Schema::dropIfExists('chaos_scenarios');
    }

    private function seedDefaultScenarios(): void
    {
        $scenarios = [
            // ==================== BAN SIMULATIONS ====================
            [
                'slug' => 'ban-mass-rejection',
                'name' => 'Ban Simulation: Mass Message Rejection',
                'category' => 'ban_simulation',
                'description' => 'Simulates WhatsApp rejecting messages en masse (50%+ rejection rate)',
                'hypothesis' => 'System should: 1) Detect high rejection rate within 2 minutes, 2) Auto-pause affected campaigns, 3) Increase risk score, 4) Create incident record, 5) Notify operations team',
                'blast_radius' => json_encode([
                    'components' => ['campaign_sending', 'whatsapp_api'],
                    'user_impact' => 'medium',
                    'estimated_affected_campaigns' => '10-50'
                ]),
                'injection_config' => json_encode([
                    'type' => 'mock_response',
                    'provider' => 'whatsapp',
                    'rejection_rate' => 60,
                    'error_codes' => ['131047', '131051', '131056'],
                    'gradual_increase' => true,
                    'increase_interval_seconds' => 60
                ]),
                'success_criteria' => json_encode([
                    'detection_time_max_seconds' => 120,
                    'campaign_pause_triggered' => true,
                    'risk_score_increase' => true,
                    'incident_created' => true,
                    'notification_sent' => true,
                    'false_positive_rate_max' => 5
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 600,
                    'max_affected_users' => 10,
                    'max_real_messages_blocked' => 0,
                    'environment_allowed' => ['staging', 'canary']
                ]),
                'rollback_conditions' => json_encode([
                    'real_user_impact_detected' => true,
                    'production_traffic_affected' => true,
                    'guardrail_breach' => true
                ]),
                'estimated_duration_seconds' => 600,
                'severity' => 'high',
                'requires_approval' => true
            ],
            [
                'slug' => 'ban-quality-downgrade',
                'name' => 'Ban Simulation: Quality Rating Downgrade',
                'category' => 'ban_simulation',
                'description' => 'Simulates WhatsApp sending quality downgrade webhook (GREEN → YELLOW → RED)',
                'hypothesis' => 'System should: 1) Process quality webhook, 2) Auto-throttle rate, 3) Update sender risk score, 4) Notify affected users, 5) Suggest remediation',
                'blast_radius' => json_encode([
                    'components' => ['whatsapp_api', 'webhook_processing'],
                    'user_impact' => 'low',
                    'estimated_affected_senders' => '5-20'
                ]),
                'injection_config' => json_encode([
                    'type' => 'mock_webhook',
                    'webhook_type' => 'account_update',
                    'quality_rating' => 'RED',
                    'previous_rating' => 'GREEN'
                ]),
                'success_criteria' => json_encode([
                    'webhook_processed' => true,
                    'rate_throttled' => true,
                    'risk_score_updated' => true,
                    'user_notified' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 300,
                    'environment_allowed' => ['staging', 'canary']
                ]),
                'rollback_conditions' => json_encode([
                    'real_sender_affected' => true
                ]),
                'estimated_duration_seconds' => 300,
                'severity' => 'medium',
                'requires_approval' => true
            ],
            [
                'slug' => 'ban-delivery-rate-drop',
                'name' => 'Ban Simulation: Delivery Rate Drop',
                'category' => 'ban_simulation',
                'description' => 'Simulates gradual delivery rate drop from 95% to 40%',
                'hypothesis' => 'System should: 1) Detect anomaly in delivery rate, 2) Trigger investigation, 3) Reduce sending rate, 4) Alert operations',
                'blast_radius' => json_encode([
                    'components' => ['campaign_sending'],
                    'user_impact' => 'medium'
                ]),
                'injection_config' => json_encode([
                    'type' => 'mock_response',
                    'delivery_rate_target' => 40,
                    'degradation_duration_seconds' => 300,
                    'degradation_pattern' => 'gradual'
                ]),
                'success_criteria' => json_encode([
                    'anomaly_detected' => true,
                    'detection_time_max_seconds' => 180,
                    'rate_reduced' => true,
                    'alert_sent' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 600,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'real_user_impact_detected' => true
                ]),
                'estimated_duration_seconds' => 600,
                'severity' => 'high',
                'requires_approval' => true
            ],

            // ==================== OUTAGE SIMULATIONS ====================
            [
                'slug' => 'outage-whatsapp-timeout',
                'name' => 'Outage Simulation: WhatsApp API Timeout',
                'category' => 'outage_simulation',
                'description' => 'Simulates WhatsApp API becoming unresponsive (100% timeout)',
                'hypothesis' => 'System should: 1) Detect API unavailable, 2) Queue messages for retry, 3) Update status page, 4) Not lose any messages, 5) Resume automatically when API recovers',
                'blast_radius' => json_encode([
                    'components' => ['whatsapp_api', 'campaign_sending'],
                    'user_impact' => 'high',
                    'all_sending_affected' => true
                ]),
                'injection_config' => json_encode([
                    'type' => 'inject_failure',
                    'failure_type' => 'timeout',
                    'timeout_seconds' => 30,
                    'percentage' => 100
                ]),
                'success_criteria' => json_encode([
                    'detection_time_max_seconds' => 60,
                    'status_page_updated' => true,
                    'messages_queued_not_lost' => true,
                    'auto_resume_on_recovery' => true,
                    'incident_created' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 300,
                    'max_queued_messages' => 10000,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'queue_overflow' => true,
                    'memory_threshold_breach' => true
                ]),
                'estimated_duration_seconds' => 300,
                'severity' => 'critical',
                'requires_approval' => true
            ],
            [
                'slug' => 'outage-webhook-delay',
                'name' => 'Outage Simulation: Webhook Delivery Delay',
                'category' => 'outage_simulation',
                'description' => 'Simulates webhooks arriving with 5+ minute delay',
                'hypothesis' => 'System should: 1) Handle out-of-order webhooks, 2) Not create duplicate records, 3) Process stale webhooks correctly, 4) Alert on unusual delay patterns',
                'blast_radius' => json_encode([
                    'components' => ['webhook_processing', 'inbox'],
                    'user_impact' => 'medium'
                ]),
                'injection_config' => json_encode([
                    'type' => 'delay',
                    'delay_seconds' => 300,
                    'delay_probability' => 80
                ]),
                'success_criteria' => json_encode([
                    'no_duplicate_records' => true,
                    'webhooks_processed_correctly' => true,
                    'delay_alert_triggered' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 600,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'data_inconsistency_detected' => true
                ]),
                'estimated_duration_seconds' => 600,
                'severity' => 'medium',
                'requires_approval' => true
            ],
            [
                'slug' => 'outage-queue-backlog',
                'name' => 'Outage Simulation: Queue Backlog Explosion',
                'category' => 'outage_simulation',
                'description' => 'Simulates queue processing slowing down causing massive backlog',
                'hypothesis' => 'System should: 1) Detect queue depth anomaly, 2) Scale workers or pause new jobs, 3) Alert operations, 4) Gracefully handle backpressure',
                'blast_radius' => json_encode([
                    'components' => ['campaign_sending', 'webhook_processing'],
                    'user_impact' => 'medium'
                ]),
                'injection_config' => json_encode([
                    'type' => 'inject_failure',
                    'failure_type' => 'slow_processing',
                    'processing_delay_ms' => 5000,
                    'percentage' => 100
                ]),
                'success_criteria' => json_encode([
                    'queue_depth_alert_triggered' => true,
                    'backpressure_applied' => true,
                    'no_job_loss' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 300,
                    'max_queue_depth' => 50000,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'queue_overflow' => true,
                    'memory_exhaustion' => true
                ]),
                'estimated_duration_seconds' => 300,
                'severity' => 'high',
                'requires_approval' => true
            ],
            [
                'slug' => 'outage-payment-callback-delay',
                'name' => 'Outage Simulation: Payment Gateway Callback Delay',
                'category' => 'outage_simulation',
                'description' => 'Simulates payment gateway callback arriving late or not at all',
                'hypothesis' => 'System should: 1) Handle pending payments gracefully, 2) Retry status check, 3) Not block user if payment eventually succeeds, 4) Alert on stuck payments',
                'blast_radius' => json_encode([
                    'components' => ['billing'],
                    'user_impact' => 'low'
                ]),
                'injection_config' => json_encode([
                    'type' => 'delay',
                    'delay_seconds' => 600,
                    'drop_probability' => 20
                ]),
                'success_criteria' => json_encode([
                    'payment_status_check_triggered' => true,
                    'user_not_blocked_prematurely' => true,
                    'stuck_payment_alert' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 900,
                    'max_affected_payments' => 5,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'real_payment_affected' => true
                ]),
                'estimated_duration_seconds' => 900,
                'severity' => 'medium',
                'requires_approval' => true
            ],

            // ==================== INTERNAL FAILURES ====================
            [
                'slug' => 'failure-worker-crash',
                'name' => 'Internal Failure: Worker Process Crash',
                'category' => 'internal_failure',
                'description' => 'Simulates queue worker crashing mid-job',
                'hypothesis' => 'System should: 1) Detect worker failure, 2) Restart worker automatically, 3) Retry failed jobs, 4) Not lose any jobs, 5) Not create duplicate sends',
                'blast_radius' => json_encode([
                    'components' => ['campaign_sending', 'webhook_processing'],
                    'user_impact' => 'low'
                ]),
                'injection_config' => json_encode([
                    'type' => 'kill_worker',
                    'kill_signal' => 'SIGKILL',
                    'kill_probability' => 10,
                    'min_interval_seconds' => 30
                ]),
                'success_criteria' => json_encode([
                    'worker_restarted' => true,
                    'failed_jobs_retried' => true,
                    'no_job_loss' => true,
                    'no_duplicate_sends' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 300,
                    'max_kills' => 5,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'all_workers_down' => true
                ]),
                'estimated_duration_seconds' => 300,
                'severity' => 'high',
                'requires_approval' => true
            ],
            [
                'slug' => 'failure-redis-unavailable',
                'name' => 'Internal Failure: Redis/Cache Unavailable',
                'category' => 'internal_failure',
                'description' => 'Simulates Redis connection failure',
                'hypothesis' => 'System should: 1) Fallback to database or graceful degradation, 2) Continue processing without cache, 3) Alert operations, 4) Resume cache when available',
                'blast_radius' => json_encode([
                    'components' => ['dashboard', 'campaign_sending', 'webhook_processing'],
                    'user_impact' => 'medium'
                ]),
                'injection_config' => json_encode([
                    'type' => 'inject_failure',
                    'failure_type' => 'connection_refused',
                    'target' => 'redis',
                    'percentage' => 100
                ]),
                'success_criteria' => json_encode([
                    'graceful_degradation' => true,
                    'core_functionality_maintained' => true,
                    'alert_sent' => true,
                    'auto_resume_on_recovery' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 180,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'critical_functionality_lost' => true
                ]),
                'estimated_duration_seconds' => 180,
                'severity' => 'high',
                'requires_approval' => true
            ],
            [
                'slug' => 'failure-db-lock-contention',
                'name' => 'Internal Failure: Database Lock Contention',
                'category' => 'internal_failure',
                'description' => 'Simulates high database lock contention causing slow queries',
                'hypothesis' => 'System should: 1) Detect slow queries, 2) Timeout gracefully, 3) Retry with backoff, 4) Not cause cascading failures',
                'blast_radius' => json_encode([
                    'components' => ['campaign_sending', 'billing', 'inbox'],
                    'user_impact' => 'medium'
                ]),
                'injection_config' => json_encode([
                    'type' => 'inject_failure',
                    'failure_type' => 'lock_wait_timeout',
                    'probability' => 30,
                    'lock_duration_ms' => 5000
                ]),
                'success_criteria' => json_encode([
                    'slow_query_detected' => true,
                    'graceful_timeout' => true,
                    'retry_with_backoff' => true,
                    'no_cascading_failure' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 300,
                    'max_deadlock_count' => 10,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'database_unresponsive' => true
                ]),
                'estimated_duration_seconds' => 300,
                'severity' => 'high',
                'requires_approval' => true
            ],
            [
                'slug' => 'failure-duplicate-webhook',
                'name' => 'Internal Failure: Duplicate Webhook Events',
                'category' => 'internal_failure',
                'description' => 'Simulates same webhook being delivered multiple times',
                'hypothesis' => 'System should: 1) Detect duplicate via idempotency key, 2) Skip duplicate processing, 3) Not create duplicate records, 4) Log duplicate for analysis',
                'blast_radius' => json_encode([
                    'components' => ['webhook_processing', 'inbox'],
                    'user_impact' => 'none'
                ]),
                'injection_config' => json_encode([
                    'type' => 'replay_webhook',
                    'replay_count' => 5,
                    'replay_interval_seconds' => 10
                ]),
                'success_criteria' => json_encode([
                    'duplicates_detected' => true,
                    'duplicates_skipped' => true,
                    'no_duplicate_records' => true,
                    'duplicate_logged' => true
                ]),
                'safety_guards' => json_encode([
                    'max_duration_seconds' => 180,
                    'max_replays' => 20,
                    'environment_allowed' => ['staging']
                ]),
                'rollback_conditions' => json_encode([
                    'duplicate_records_created' => true
                ]),
                'estimated_duration_seconds' => 180,
                'severity' => 'medium',
                'requires_approval' => false
            ],
        ];

        foreach ($scenarios as $scenario) {
            \DB::table('chaos_scenarios')->insert(array_merge($scenario, [
                'created_at' => now(),
                'updated_at' => now()
            ]));
        }
    }

    private function seedDefaultGuardrails(): void
    {
        $guardrails = [
            [
                'name' => 'Maximum Experiment Duration',
                'guardrail_type' => 'time_limit',
                'metric' => 'experiment_duration_seconds',
                'operator' => '>',
                'threshold' => 900,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Abort experiment if running longer than 15 minutes'
            ],
            [
                'name' => 'Error Rate Threshold',
                'guardrail_type' => 'error_rate',
                'metric' => 'system_error_rate_percent',
                'operator' => '>',
                'threshold' => 50,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Abort if system error rate exceeds 50%'
            ],
            [
                'name' => 'Queue Depth Limit',
                'guardrail_type' => 'metric_threshold',
                'metric' => 'queue_depth',
                'operator' => '>',
                'threshold' => 100000,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Abort if queue depth exceeds 100k jobs'
            ],
            [
                'name' => 'Memory Usage Limit',
                'guardrail_type' => 'metric_threshold',
                'metric' => 'memory_usage_percent',
                'operator' => '>',
                'threshold' => 90,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Abort if memory usage exceeds 90%'
            ],
            [
                'name' => 'Real User Impact Detection',
                'guardrail_type' => 'user_impact',
                'metric' => 'real_user_affected_count',
                'operator' => '>',
                'threshold' => 0,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Immediately abort if real users are affected'
            ],
            [
                'name' => 'Production Traffic Detection',
                'guardrail_type' => 'user_impact',
                'metric' => 'production_traffic_affected',
                'operator' => '==',
                'threshold' => 1,
                'action' => 'abort',
                'is_global' => true,
                'description' => 'Immediately abort if production traffic is affected'
            ],
            [
                'name' => 'Incident Count Limit',
                'guardrail_type' => 'metric_threshold',
                'metric' => 'incident_count',
                'operator' => '>',
                'threshold' => 3,
                'action' => 'pause',
                'is_global' => true,
                'description' => 'Pause experiment if more than 3 incidents created'
            ],
        ];

        foreach ($guardrails as $guardrail) {
            \DB::table('chaos_guardrails')->insert(array_merge($guardrail, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]));
        }
    }

    private function seedDefaultMockResponses(): void
    {
        $mockResponses = [
            // WhatsApp Ban Responses
            [
                'provider' => 'whatsapp',
                'endpoint' => '/v1/messages',
                'method' => 'POST',
                'scenario_type' => 'rejected',
                'http_status' => 400,
                'response_body' => json_encode([
                    'error' => [
                        'message' => '(#131047) Re-engagement message',
                        'type' => 'OAuthException',
                        'code' => 131047,
                        'error_subcode' => 2494010,
                        'fbtrace_id' => 'chaos_test_trace_001'
                    ]
                ]),
                'response_headers' => json_encode(['X-Chaos-Injected' => 'true']),
                'delay_ms' => 100,
                'probability' => 100
            ],
            [
                'provider' => 'whatsapp',
                'endpoint' => '/v1/messages',
                'method' => 'POST',
                'scenario_type' => 'rate_limited',
                'http_status' => 429,
                'response_body' => json_encode([
                    'error' => [
                        'message' => 'Rate limit hit',
                        'type' => 'OAuthException',
                        'code' => 80007,
                        'fbtrace_id' => 'chaos_test_trace_002'
                    ]
                ]),
                'response_headers' => json_encode([
                    'X-Chaos-Injected' => 'true',
                    'Retry-After' => '60'
                ]),
                'delay_ms' => 50,
                'probability' => 100
            ],
            [
                'provider' => 'whatsapp',
                'endpoint' => '/v1/messages',
                'method' => 'POST',
                'scenario_type' => 'timeout',
                'http_status' => 504,
                'response_body' => json_encode([
                    'error' => [
                        'message' => 'Gateway Timeout',
                        'type' => 'InternalServerError',
                        'code' => 504
                    ]
                ]),
                'response_headers' => json_encode(['X-Chaos-Injected' => 'true']),
                'delay_ms' => 30000,
                'probability' => 100
            ],
            // Quality Downgrade Webhook
            [
                'provider' => 'whatsapp',
                'endpoint' => 'webhook',
                'method' => 'POST',
                'scenario_type' => 'quality_downgrade',
                'http_status' => 200,
                'response_body' => json_encode([
                    'object' => 'whatsapp_business_account',
                    'entry' => [[
                        'id' => 'CHAOS_TEST_WABA',
                        'changes' => [[
                            'value' => [
                                'event' => 'PHONE_NUMBER_QUALITY_UPDATE',
                                'display_phone_number' => '+1234567890',
                                'current_limit' => 'TIER_1K',
                                'current_quality_rating' => 'RED'
                            ],
                            'field' => 'account_update'
                        ]]
                    ]]
                ]),
                'response_headers' => json_encode(['X-Chaos-Injected' => 'true']),
                'delay_ms' => 0,
                'probability' => 100
            ],
            // Payment Gateway Mock
            [
                'provider' => 'midtrans',
                'endpoint' => '/v2/charge',
                'method' => 'POST',
                'scenario_type' => 'timeout',
                'http_status' => 504,
                'response_body' => json_encode([
                    'status_code' => '504',
                    'status_message' => 'Gateway Timeout'
                ]),
                'response_headers' => json_encode(['X-Chaos-Injected' => 'true']),
                'delay_ms' => 60000,
                'probability' => 100
            ],
        ];

        foreach ($mockResponses as $mock) {
            \DB::table('chaos_mock_responses')->insert(array_merge($mock, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]));
        }
    }
};
