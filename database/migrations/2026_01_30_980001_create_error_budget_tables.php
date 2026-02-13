<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * ERROR BUDGET & RELIABILITY POLICY TABLES
 * =============================================================================
 * 
 * Sistem untuk tracking SLI/SLO, menghitung error budget, dan
 * mengambil tindakan otomatis berdasarkan budget consumption.
 * 
 * KONSEP:
 * - SLI (Service Level Indicator): Metrik yang diukur
 * - SLO (Service Level Objective): Target yang harus dicapai
 * - Error Budget = 100% - SLO (margin kesalahan yang diizinkan)
 * - Policy: Aturan aksi berdasarkan budget consumption
 * 
 * =============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. SLI DEFINITIONS - Definisi metrik yang diukur
        // =====================================================================
        Schema::create('sli_definitions', function (Blueprint $table) {
            $table->id();
            
            // Identity
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            
            // Category
            $table->enum('category', [
                'messaging',      // Send rate, delivery rate, rejection rate
                'performance',    // Latency, response time
                'availability',   // Uptime, error rate
                'billing',        // Payment success, callback processing
                'reliability',    // Queue health, worker health
            ]);
            
            // Component yang diukur
            $table->string('component', 100); // whatsapp, queue, payment, webhook, api
            
            // Measurement type
            $table->enum('measurement_type', [
                'ratio',          // success/total (percentage)
                'threshold',      // value < threshold (latency)
                'availability',   // uptime percentage
                'count',          // absolute count
            ]);
            
            // Calculation
            $table->string('good_events_query', 500)->nullable(); // SQL or metric name for good events
            $table->string('total_events_query', 500)->nullable(); // SQL or metric name for total events
            $table->string('metric_source', 100)->default('database'); // database, redis, prometheus
            
            // Unit
            $table->string('unit', 50)->default('percent'); // percent, milliseconds, seconds, count
            $table->boolean('higher_is_better')->default(true); // true for success rate, false for latency
            
            // Metadata
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('tags')->nullable();
            
            $table->timestamps();
            
            $table->index(['category', 'is_active']);
            $table->index(['component', 'is_active']);
        });

        // =====================================================================
        // 2. SLO DEFINITIONS - Target yang harus dicapai
        // =====================================================================
        Schema::create('slo_definitions', function (Blueprint $table) {
            $table->id();
            
            // Link to SLI
            $table->foreignId('sli_id')->constrained('sli_definitions')->onDelete('cascade');
            
            // Identity
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            
            // Target
            $table->decimal('target_value', 10, 4); // e.g., 99.00 for 99%
            $table->enum('comparison_operator', ['>=', '<=', '>', '<', '=']);
            
            // Threshold levels for alerting
            $table->decimal('warning_threshold', 10, 4)->nullable(); // e.g., 99.5 (yellow)
            $table->decimal('critical_threshold', 10, 4)->nullable(); // e.g., 98.5 (red)
            
            // Time window
            $table->enum('window_type', [
                'rolling',    // Rolling window (last N days)
                'calendar',   // Calendar period (monthly, weekly)
            ]);
            $table->unsignedInteger('window_days')->default(30); // 7 for weekly, 30 for monthly
            
            // Error budget
            $table->decimal('error_budget_percent', 10, 4)->storedAs('100 - target_value');
            
            // Ownership
            $table->string('owner_team', 100)->nullable();
            $table->string('owner_email', 200)->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false); // Primary SLO for component
            
            // External
            $table->boolean('is_customer_facing')->default(false);
            $table->string('external_reference')->nullable(); // Link to SLA document
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['sli_id', 'is_active']);
            $table->index(['is_primary', 'is_active']);
        });

        // =====================================================================
        // 3. SLI MEASUREMENTS - Pengukuran aktual
        // =====================================================================
        Schema::create('sli_measurements', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('sli_id')->constrained('sli_definitions')->onDelete('cascade');
            
            // Time period
            $table->date('measurement_date');
            $table->enum('granularity', ['hourly', 'daily', 'weekly', 'monthly']);
            $table->unsignedTinyInteger('hour')->nullable(); // For hourly measurements
            
            // Measurements
            $table->unsignedBigInteger('good_events')->default(0);
            $table->unsignedBigInteger('total_events')->default(0);
            // Use CAST to avoid BIGINT UNSIGNED subtraction issues
            $table->unsignedBigInteger('bad_events')
                ->storedAs('GREATEST(0, CAST(total_events AS SIGNED) - CAST(good_events AS SIGNED))');
            
            // Calculated values
            $table->decimal('value', 10, 4)->nullable(); // Calculated SLI value
            $table->decimal('value_percent', 10, 4)->nullable(); // As percentage
            
            // For threshold-based SLIs (latency)
            $table->decimal('p50_value', 10, 2)->nullable();
            $table->decimal('p95_value', 10, 2)->nullable();
            $table->decimal('p99_value', 10, 2)->nullable();
            $table->decimal('avg_value', 10, 2)->nullable();
            $table->decimal('max_value', 10, 2)->nullable();
            
            // Breakdown
            $table->json('breakdown')->nullable(); // Breakdown by sub-component
            
            // Metadata
            $table->string('data_source', 100)->nullable();
            $table->boolean('is_complete')->default(false); // For partial day data
            
            $table->timestamps();
            
            $table->unique(['sli_id', 'measurement_date', 'granularity', 'hour'], 'sli_measurement_unique');
            $table->index(['measurement_date', 'granularity']);
        });

        // =====================================================================
        // 4. ERROR BUDGET STATUS - Status budget per SLO
        // =====================================================================
        Schema::create('error_budget_status', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('slo_id')->constrained('slo_definitions')->onDelete('cascade');
            
            // Period
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('period_type', ['weekly', 'monthly', 'quarterly']);
            
            // Budget calculation
            $table->unsignedBigInteger('total_events')->default(0);
            $table->unsignedBigInteger('allowed_bad_events')->default(0); // Error budget in absolute
            $table->unsignedBigInteger('actual_bad_events')->default(0);
            // Use CAST to avoid BIGINT UNSIGNED subtraction issues
            $table->unsignedBigInteger('remaining_bad_events')
                ->storedAs('GREATEST(0, CAST(allowed_bad_events AS SIGNED) - CAST(actual_bad_events AS SIGNED))');
            
            // Percentages
            $table->decimal('budget_total_percent', 10, 4)->default(0); // Error budget %
            $table->decimal('budget_consumed_percent', 10, 4)->default(0); // How much consumed
            $table->decimal('budget_remaining_percent', 10, 4)->default(100); // How much remaining
            
            // SLI performance
            $table->decimal('current_sli_value', 10, 4)->nullable();
            $table->boolean('slo_met')->default(true);
            
            // Burn rate
            $table->decimal('burn_rate_1h', 10, 4)->nullable(); // Last 1 hour
            $table->decimal('burn_rate_6h', 10, 4)->nullable(); // Last 6 hours
            $table->decimal('burn_rate_24h', 10, 4)->nullable(); // Last 24 hours
            $table->decimal('burn_rate_7d', 10, 4)->nullable(); // Last 7 days
            
            // Projection
            $table->decimal('projected_consumption_eom', 10, 4)->nullable(); // End of month projection
            $table->date('projected_exhaustion_date')->nullable(); // When budget will be exhausted
            
            // Status
            $table->enum('status', [
                'healthy',    // Budget > 75%
                'warning',    // Budget 25-75%
                'critical',   // Budget < 25%
                'exhausted',  // Budget = 0%
            ])->default('healthy');
            
            // Policy actions taken
            $table->json('active_policies')->nullable();
            
            $table->timestamps();
            
            $table->unique(['slo_id', 'period_start', 'period_type'], 'error_budget_period_unique');
            $table->index(['status', 'period_type']);
            $table->index(['period_end']);
        });

        // =====================================================================
        // 5. BUDGET BURN EVENTS - Log perubahan signifikan
        // =====================================================================
        Schema::create('budget_burn_events', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('slo_id')->constrained('slo_definitions')->onDelete('cascade');
            $table->foreignId('budget_status_id')->nullable()->constrained('error_budget_status')->onDelete('set null');
            
            // Event details
            $table->dateTime('occurred_at');
            $table->enum('event_type', [
                'threshold_crossed',  // Crossed warning/critical threshold
                'burn_rate_spike',    // Sudden increase in burn rate
                'budget_exhausted',   // Budget reached 0
                'budget_recovered',   // Budget improved
                'status_changed',     // Status changed (healthy -> warning)
                'policy_triggered',   // Policy action taken
                'slo_breached',       // SLO target breached
            ]);
            
            // Severity
            $table->enum('severity', ['info', 'warning', 'critical', 'emergency']);
            
            // Values
            $table->decimal('previous_value', 10, 4)->nullable();
            $table->decimal('current_value', 10, 4)->nullable();
            $table->decimal('change_percent', 10, 4)->nullable();
            
            // Context
            $table->string('message', 500);
            $table->json('context')->nullable(); // Additional data
            
            // Related incidents
            $table->unsignedBigInteger('incident_id')->nullable();
            
            // Actions taken
            $table->json('actions_taken')->nullable();
            
            // Notification
            $table->boolean('notification_sent')->default(false);
            $table->dateTime('notified_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['slo_id', 'occurred_at']);
            $table->index(['event_type', 'severity']);
            $table->index(['occurred_at']);
        });

        // =====================================================================
        // 6. RELIABILITY POLICIES - Aturan aksi berdasarkan budget
        // =====================================================================
        Schema::create('reliability_policies', function (Blueprint $table) {
            $table->id();
            
            // Identity
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            
            // Trigger condition
            $table->enum('trigger_type', [
                'budget_threshold',   // When budget crosses threshold
                'burn_rate',          // When burn rate exceeds limit
                'slo_breach',         // When SLO is breached
                'status_change',      // When status changes
                'time_based',         // Time-based (e.g., during incidents)
            ]);
            
            // Condition parameters
            $table->decimal('threshold_value', 10, 4)->nullable(); // e.g., 25 for "budget < 25%"
            $table->enum('threshold_operator', ['<', '<=', '>', '>=', '='])->nullable();
            $table->string('threshold_status', 50)->nullable(); // e.g., 'critical'
            
            // Scope
            $table->json('applies_to_slos')->nullable(); // Specific SLO IDs, null = all
            $table->json('applies_to_categories')->nullable(); // messaging, billing, etc.
            $table->json('applies_to_components')->nullable(); // whatsapp, queue, etc.
            
            // Actions
            $table->json('actions'); // Array of actions to take
            /*
             * Actions format:
             * [
             *   {"type": "block_deploy", "params": {"severity": ["critical"]}},
             *   {"type": "throttle", "params": {"reduction_percent": 50}},
             *   {"type": "feature_freeze", "params": {}},
             *   {"type": "alert", "params": {"channels": ["slack", "email"]}},
             *   {"type": "page", "params": {"team": "sre"}},
             * ]
             */
            
            // Priority
            $table->unsignedInteger('priority')->default(100); // Lower = higher priority
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_automatic')->default(true); // Auto-enforce or manual
            
            // Override
            $table->boolean('can_override')->default(true);
            $table->string('override_approval_level', 50)->nullable(); // 'tech_lead', 'cto', etc.
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['trigger_type', 'is_active']);
            $table->index(['priority', 'is_active']);
        });

        // =====================================================================
        // 7. POLICY ACTIVATIONS - Log aktivasi policy
        // =====================================================================
        Schema::create('policy_activations', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('policy_id')->constrained('reliability_policies')->onDelete('cascade');
            $table->foreignId('slo_id')->nullable()->constrained('slo_definitions')->onDelete('set null');
            
            // Activation details
            $table->dateTime('activated_at');
            $table->dateTime('deactivated_at')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Trigger reason
            $table->string('trigger_reason', 500);
            $table->json('trigger_context')->nullable();
            
            // Actions taken
            $table->json('actions_executed')->nullable();
            $table->json('actions_results')->nullable();
            
            // Override
            $table->boolean('was_overridden')->default(false);
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->dateTime('overridden_at')->nullable();
            
            // Resolution
            $table->enum('resolution', [
                'auto_resolved',      // Automatically resolved when condition cleared
                'manually_resolved',  // Manually deactivated
                'overridden',         // Overridden by authorized person
                'expired',            // Time-based expiration
                'superseded',         // Replaced by higher priority policy
            ])->nullable();
            $table->string('resolution_notes', 500)->nullable();
            
            $table->timestamps();
            
            $table->index(['policy_id', 'is_active']);
            $table->index(['activated_at']);
            $table->index(['slo_id', 'is_active']);
        });

        // =====================================================================
        // 8. DEPLOY DECISIONS - Gate decisions untuk deployment
        // =====================================================================
        Schema::create('deploy_decisions', function (Blueprint $table) {
            $table->id();
            
            // Deploy info
            $table->string('deploy_id', 100); // CI/CD job ID
            $table->string('deploy_type', 50); // feature, hotfix, rollback
            $table->string('deploy_name', 200);
            $table->string('deploy_branch', 100)->nullable();
            $table->string('deploy_commit', 100)->nullable();
            
            // Decision
            $table->enum('decision', ['allowed', 'blocked', 'warning', 'manual_override']);
            $table->string('decision_reason', 500);
            
            // Budget state at decision time
            $table->json('budget_snapshot')->nullable(); // Current budget status
            $table->json('active_policies_snapshot')->nullable(); // Active policies
            
            // Blocking policies
            $table->json('blocking_policies')->nullable();
            
            // Override
            $table->boolean('was_overridden')->default(false);
            $table->unsignedBigInteger('override_by')->nullable();
            $table->string('override_reason', 500)->nullable();
            
            // Result
            $table->enum('result', ['deployed', 'cancelled', 'pending'])->default('pending');
            $table->dateTime('deployed_at')->nullable();
            
            // Requestor
            $table->unsignedBigInteger('requested_by')->nullable();
            
            $table->timestamps();
            
            $table->index(['deploy_id']);
            $table->index(['decision', 'created_at']);
            $table->index(['result']);
        });

        // =====================================================================
        // 9. BUDGET REPORTS - Periodic reports
        // =====================================================================
        Schema::create('budget_reports', function (Blueprint $table) {
            $table->id();
            
            // Report period
            $table->date('report_date');
            $table->enum('report_type', ['daily', 'weekly', 'monthly']);
            $table->date('period_start');
            $table->date('period_end');
            
            // Summary
            $table->json('slo_summary')->nullable(); // Status of all SLOs
            $table->json('budget_summary')->nullable(); // Budget status for all
            $table->json('top_contributors')->nullable(); // Top failure contributors
            
            // Statistics
            $table->unsignedInteger('total_slos')->default(0);
            $table->unsignedInteger('slos_met')->default(0);
            $table->unsignedInteger('slos_breached')->default(0);
            $table->unsignedInteger('slos_at_risk')->default(0);
            
            // Budget statistics
            $table->decimal('avg_budget_remaining', 10, 4)->nullable();
            $table->decimal('min_budget_remaining', 10, 4)->nullable();
            $table->unsignedInteger('budgets_exhausted')->default(0);
            $table->unsignedInteger('budgets_critical')->default(0);
            
            // Policy statistics
            $table->unsignedInteger('policies_activated')->default(0);
            $table->unsignedInteger('deploys_blocked')->default(0);
            $table->unsignedInteger('deploys_total')->default(0);
            
            // Week-over-week comparison
            $table->json('wow_comparison')->nullable();
            
            // Trends
            $table->json('trends')->nullable();
            
            // Recommendations
            $table->json('recommendations')->nullable();
            
            // Report file
            $table->string('report_file_path', 500)->nullable();
            
            $table->timestamps();
            
            $table->unique(['report_date', 'report_type'], 'budget_report_unique');
            $table->index(['report_type', 'report_date']);
        });

        // =====================================================================
        // SEED DEFAULT SLI DEFINITIONS
        // =====================================================================
        $this->seedSliDefinitions();
        $this->seedSloDefinitions();
        $this->seedReliabilityPolicies();
    }

    private function seedSliDefinitions(): void
    {
        $slis = [
            // ===== MESSAGING SLIs =====
            [
                'slug' => 'message-send-success-rate',
                'name' => 'Message Send Success Rate',
                'description' => 'Percentage of messages successfully sent to WhatsApp API (not rejected)',
                'category' => 'messaging',
                'component' => 'whatsapp',
                'measurement_type' => 'ratio',
                'good_events_query' => 'status IN (sent, delivered, read)',
                'total_events_query' => 'status NOT IN (pending, queued)',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 1,
            ],
            [
                'slug' => 'message-delivery-rate',
                'name' => 'Message Delivery Rate',
                'description' => 'Percentage of sent messages that were delivered to recipient',
                'category' => 'messaging',
                'component' => 'whatsapp',
                'measurement_type' => 'ratio',
                'good_events_query' => 'status IN (delivered, read)',
                'total_events_query' => 'status = sent',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 2,
            ],
            [
                'slug' => 'message-rejection-rate',
                'name' => 'Message Rejection Rate',
                'description' => 'Percentage of messages rejected by WhatsApp API',
                'category' => 'messaging',
                'component' => 'whatsapp',
                'measurement_type' => 'ratio',
                'good_events_query' => 'status != rejected', // Inverted - lower is better
                'total_events_query' => 'all messages',
                'unit' => 'percent',
                'higher_is_better' => false, // Lower rejection = better
                'display_order' => 3,
            ],
            
            // ===== PERFORMANCE SLIs =====
            [
                'slug' => 'queue-latency-p95',
                'name' => 'Queue Processing Latency (P95)',
                'description' => '95th percentile time from queue to send',
                'category' => 'performance',
                'component' => 'queue',
                'measurement_type' => 'threshold',
                'unit' => 'seconds',
                'higher_is_better' => false,
                'display_order' => 10,
            ],
            [
                'slug' => 'queue-latency-p99',
                'name' => 'Queue Processing Latency (P99)',
                'description' => '99th percentile time from queue to send',
                'category' => 'performance',
                'component' => 'queue',
                'measurement_type' => 'threshold',
                'unit' => 'seconds',
                'higher_is_better' => false,
                'display_order' => 11,
            ],
            [
                'slug' => 'api-response-time-p95',
                'name' => 'API Response Time (P95)',
                'description' => '95th percentile API response time',
                'category' => 'performance',
                'component' => 'api',
                'measurement_type' => 'threshold',
                'unit' => 'milliseconds',
                'higher_is_better' => false,
                'display_order' => 12,
            ],
            [
                'slug' => 'webhook-processing-delay',
                'name' => 'Webhook Processing Delay',
                'description' => 'Average delay in processing incoming webhooks',
                'category' => 'performance',
                'component' => 'webhook',
                'measurement_type' => 'threshold',
                'unit' => 'seconds',
                'higher_is_better' => false,
                'display_order' => 13,
            ],
            
            // ===== AVAILABILITY SLIs =====
            [
                'slug' => 'api-availability',
                'name' => 'API Availability',
                'description' => 'Percentage of time API is available and responding',
                'category' => 'availability',
                'component' => 'api',
                'measurement_type' => 'availability',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 20,
            ],
            [
                'slug' => 'webhook-availability',
                'name' => 'Webhook Receiver Availability',
                'description' => 'Percentage of time webhook endpoint is available',
                'category' => 'availability',
                'component' => 'webhook',
                'measurement_type' => 'availability',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 21,
            ],
            [
                'slug' => 'queue-worker-availability',
                'name' => 'Queue Worker Availability',
                'description' => 'Percentage of time queue workers are running',
                'category' => 'availability',
                'component' => 'queue',
                'measurement_type' => 'availability',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 22,
            ],
            
            // ===== BILLING SLIs =====
            [
                'slug' => 'payment-success-rate',
                'name' => 'Payment Success Rate',
                'description' => 'Percentage of payment transactions completed successfully',
                'category' => 'billing',
                'component' => 'payment',
                'measurement_type' => 'ratio',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 30,
            ],
            [
                'slug' => 'payment-callback-success-rate',
                'name' => 'Payment Callback Processing Success Rate',
                'description' => 'Percentage of payment callbacks processed successfully',
                'category' => 'billing',
                'component' => 'payment',
                'measurement_type' => 'ratio',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 31,
            ],
            
            // ===== RELIABILITY SLIs =====
            [
                'slug' => 'webhook-processing-success-rate',
                'name' => 'Webhook Processing Success Rate',
                'description' => 'Percentage of webhooks processed without error',
                'category' => 'reliability',
                'component' => 'webhook',
                'measurement_type' => 'ratio',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 40,
            ],
            [
                'slug' => 'queue-job-success-rate',
                'name' => 'Queue Job Success Rate',
                'description' => 'Percentage of queue jobs completed successfully',
                'category' => 'reliability',
                'component' => 'queue',
                'measurement_type' => 'ratio',
                'unit' => 'percent',
                'higher_is_better' => true,
                'display_order' => 41,
            ],
        ];

        $now = now();
        foreach ($slis as $sli) {
            $sli['created_at'] = $now;
            $sli['updated_at'] = $now;
            DB::table('sli_definitions')->insert($sli);
        }
    }

    private function seedSloDefinitions(): void
    {
        $slos = [
            // ===== MESSAGING SLOs =====
            [
                'sli_slug' => 'message-send-success-rate',
                'slug' => 'slo-message-send-99',
                'name' => 'Message Send Success ≥99%',
                'description' => 'At least 99% of messages must be successfully sent',
                'target_value' => 99.00,
                'comparison_operator' => '>=',
                'warning_threshold' => 99.50,
                'critical_threshold' => 98.50,
                'window_type' => 'rolling',
                'window_days' => 30,
                'owner_team' => 'messaging',
                'is_primary' => true,
                'is_customer_facing' => true,
            ],
            [
                'sli_slug' => 'message-delivery-rate',
                'slug' => 'slo-message-delivery-95',
                'name' => 'Message Delivery Rate ≥95%',
                'description' => 'At least 95% of sent messages must be delivered',
                'target_value' => 95.00,
                'comparison_operator' => '>=',
                'warning_threshold' => 96.00,
                'critical_threshold' => 93.00,
                'window_type' => 'rolling',
                'window_days' => 30,
                'owner_team' => 'messaging',
                'is_primary' => true,
                'is_customer_facing' => true,
            ],
            [
                'sli_slug' => 'message-rejection-rate',
                'slug' => 'slo-message-rejection-1',
                'name' => 'Message Rejection Rate ≤1%',
                'description' => 'Less than 1% of messages should be rejected',
                'target_value' => 1.00,
                'comparison_operator' => '<=',
                'warning_threshold' => 0.50,
                'critical_threshold' => 2.00,
                'window_type' => 'rolling',
                'window_days' => 7,
                'owner_team' => 'messaging',
                'is_primary' => false,
                'is_customer_facing' => false,
            ],
            
            // ===== PERFORMANCE SLOs =====
            [
                'sli_slug' => 'queue-latency-p95',
                'slug' => 'slo-queue-latency-30s',
                'name' => 'Queue Latency P95 ≤30s',
                'description' => '95% of messages processed within 30 seconds',
                'target_value' => 30.00,
                'comparison_operator' => '<=',
                'warning_threshold' => 20.00,
                'critical_threshold' => 45.00,
                'window_type' => 'rolling',
                'window_days' => 7,
                'owner_team' => 'platform',
                'is_primary' => true,
                'is_customer_facing' => false,
            ],
            [
                'sli_slug' => 'api-response-time-p95',
                'slug' => 'slo-api-response-500ms',
                'name' => 'API Response Time P95 ≤500ms',
                'description' => '95% of API requests respond within 500ms',
                'target_value' => 500.00,
                'comparison_operator' => '<=',
                'warning_threshold' => 300.00,
                'critical_threshold' => 750.00,
                'window_type' => 'rolling',
                'window_days' => 7,
                'owner_team' => 'platform',
                'is_primary' => true,
                'is_customer_facing' => true,
            ],
            
            // ===== AVAILABILITY SLOs =====
            [
                'sli_slug' => 'api-availability',
                'slug' => 'slo-api-availability-999',
                'name' => 'API Availability ≥99.9%',
                'description' => 'API must be available 99.9% of the time',
                'target_value' => 99.90,
                'comparison_operator' => '>=',
                'warning_threshold' => 99.95,
                'critical_threshold' => 99.50,
                'window_type' => 'calendar',
                'window_days' => 30,
                'owner_team' => 'platform',
                'is_primary' => true,
                'is_customer_facing' => true,
            ],
            
            // ===== BILLING SLOs =====
            [
                'sli_slug' => 'payment-success-rate',
                'slug' => 'slo-payment-success-995',
                'name' => 'Payment Success Rate ≥99.5%',
                'description' => 'At least 99.5% of payments must succeed',
                'target_value' => 99.50,
                'comparison_operator' => '>=',
                'warning_threshold' => 99.70,
                'critical_threshold' => 99.00,
                'window_type' => 'rolling',
                'window_days' => 30,
                'owner_team' => 'billing',
                'is_primary' => true,
                'is_customer_facing' => true,
            ],
            
            // ===== RELIABILITY SLOs =====
            [
                'sli_slug' => 'webhook-processing-success-rate',
                'slug' => 'slo-webhook-processing-999',
                'name' => 'Webhook Processing ≥99.9%',
                'description' => 'At least 99.9% of webhooks must be processed successfully',
                'target_value' => 99.90,
                'comparison_operator' => '>=',
                'warning_threshold' => 99.95,
                'critical_threshold' => 99.50,
                'window_type' => 'rolling',
                'window_days' => 7,
                'owner_team' => 'platform',
                'is_primary' => true,
                'is_customer_facing' => false,
            ],
        ];

        $now = now();
        foreach ($slos as $sloData) {
            $sliSlug = $sloData['sli_slug'];
            unset($sloData['sli_slug']);
            
            $sliId = DB::table('sli_definitions')->where('slug', $sliSlug)->value('id');
            if ($sliId) {
                $sloData['sli_id'] = $sliId;
                $sloData['created_at'] = $now;
                $sloData['updated_at'] = $now;
                DB::table('slo_definitions')->insert($sloData);
            }
        }
    }

    private function seedReliabilityPolicies(): void
    {
        $policies = [
            // ===== BUDGET > 75% (GREEN) =====
            [
                'slug' => 'policy-green-normal',
                'name' => 'Normal Operations (Budget ≥75%)',
                'description' => 'Standard operations when error budget is healthy',
                'trigger_type' => 'budget_threshold',
                'threshold_value' => 75.00,
                'threshold_operator' => '>=',
                'threshold_status' => 'healthy',
                'actions' => json_encode([
                    ['type' => 'allow_deploy', 'params' => ['all' => true]],
                    ['type' => 'allow_campaign', 'params' => ['scale' => 'normal']],
                ]),
                'priority' => 100,
                'is_automatic' => true,
            ],
            
            // ===== BUDGET 50-75% (YELLOW WARNING) =====
            [
                'slug' => 'policy-yellow-caution',
                'name' => 'Caution Mode (Budget 50-75%)',
                'description' => 'Increased monitoring when budget is being consumed',
                'trigger_type' => 'budget_threshold',
                'threshold_value' => 75.00,
                'threshold_operator' => '<',
                'threshold_status' => 'warning',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack'], 'level' => 'warning']],
                    ['type' => 'increase_monitoring', 'params' => ['frequency' => '5m']],
                    ['type' => 'deploy_warning', 'params' => ['message' => 'Budget below 75%, deploy with caution']],
                ]),
                'priority' => 80,
                'is_automatic' => true,
            ],
            
            // ===== BUDGET 25-50% (ORANGE ALERT) =====
            [
                'slug' => 'policy-orange-restricted',
                'name' => 'Restricted Mode (Budget 25-50%)',
                'description' => 'Restricted operations when budget is low',
                'trigger_type' => 'budget_threshold',
                'threshold_value' => 50.00,
                'threshold_operator' => '<',
                'threshold_status' => 'warning',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack', 'email'], 'level' => 'high']],
                    ['type' => 'block_deploy', 'params' => ['except' => ['hotfix', 'rollback']]],
                    ['type' => 'throttle', 'params' => ['reduction_percent' => 25]],
                    ['type' => 'campaign_limit', 'params' => ['max_concurrent' => 3]],
                ]),
                'priority' => 60,
                'is_automatic' => true,
                'can_override' => true,
                'override_approval_level' => 'tech_lead',
            ],
            
            // ===== BUDGET < 25% (RED CRITICAL) =====
            [
                'slug' => 'policy-red-critical',
                'name' => 'Critical Mode (Budget <25%)',
                'description' => 'Severe restrictions when budget is nearly exhausted',
                'trigger_type' => 'budget_threshold',
                'threshold_value' => 25.00,
                'threshold_operator' => '<',
                'threshold_status' => 'critical',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack', 'email', 'sms'], 'level' => 'critical']],
                    ['type' => 'page', 'params' => ['team' => 'sre']],
                    ['type' => 'feature_freeze', 'params' => []],
                    ['type' => 'block_deploy', 'params' => ['except' => ['rollback']]],
                    ['type' => 'throttle', 'params' => ['reduction_percent' => 50]],
                    ['type' => 'campaign_pause', 'params' => ['priority' => 'low']],
                ]),
                'priority' => 40,
                'is_automatic' => true,
                'can_override' => true,
                'override_approval_level' => 'cto',
            ],
            
            // ===== BUDGET = 0% (EXHAUSTED) =====
            [
                'slug' => 'policy-exhausted-emergency',
                'name' => 'Emergency Mode (Budget Exhausted)',
                'description' => 'Emergency measures when budget is completely exhausted',
                'trigger_type' => 'budget_threshold',
                'threshold_value' => 5.00,
                'threshold_operator' => '<',
                'threshold_status' => 'exhausted',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack', 'email', 'sms', 'phone'], 'level' => 'emergency']],
                    ['type' => 'page', 'params' => ['team' => 'sre', 'escalate' => true]],
                    ['type' => 'full_freeze', 'params' => []],
                    ['type' => 'block_deploy', 'params' => ['all' => true]],
                    ['type' => 'throttle', 'params' => ['reduction_percent' => 75]],
                    ['type' => 'campaign_pause', 'params' => ['all' => true]],
                    ['type' => 'incident_create', 'params' => ['severity' => 'SEV-1', 'title' => 'Error Budget Exhausted']],
                ]),
                'priority' => 20,
                'is_automatic' => true,
                'can_override' => false,
            ],
            
            // ===== BURN RATE SPIKE =====
            [
                'slug' => 'policy-burn-rate-spike',
                'name' => 'Burn Rate Spike Alert',
                'description' => 'Alert when burn rate is 10x normal',
                'trigger_type' => 'burn_rate',
                'threshold_value' => 10.00,
                'threshold_operator' => '>',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack', 'email'], 'level' => 'high']],
                    ['type' => 'page', 'params' => ['team' => 'on-call']],
                    ['type' => 'investigate', 'params' => ['auto_runbook' => true]],
                ]),
                'priority' => 30,
                'is_automatic' => true,
            ],
            
            // ===== SLO BREACH =====
            [
                'slug' => 'policy-slo-breach',
                'name' => 'SLO Breach Response',
                'description' => 'Actions when SLO target is breached',
                'trigger_type' => 'slo_breach',
                'actions' => json_encode([
                    ['type' => 'alert', 'params' => ['channels' => ['slack', 'email'], 'level' => 'critical']],
                    ['type' => 'incident_create', 'params' => ['severity' => 'SEV-2', 'title' => 'SLO Breach Detected']],
                    ['type' => 'block_deploy', 'params' => ['except' => ['hotfix', 'rollback']]],
                ]),
                'priority' => 35,
                'is_automatic' => true,
            ],
        ];

        $now = now();
        foreach ($policies as $policy) {
            $policy['created_at'] = $now;
            $policy['updated_at'] = $now;
            DB::table('reliability_policies')->insert($policy);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_reports');
        Schema::dropIfExists('deploy_decisions');
        Schema::dropIfExists('policy_activations');
        Schema::dropIfExists('reliability_policies');
        Schema::dropIfExists('budget_burn_events');
        Schema::dropIfExists('error_budget_status');
        Schema::dropIfExists('sli_measurements');
        Schema::dropIfExists('slo_definitions');
        Schema::dropIfExists('sli_definitions');
    }
};
