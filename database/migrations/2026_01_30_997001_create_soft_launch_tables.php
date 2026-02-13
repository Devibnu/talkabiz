<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SOFT-LAUNCH MANAGEMENT SYSTEM
     * 
     * Strategi peluncuran bertahap: UMKM Pilot → UMKM Scale → Corporate
     * 
     * Tables:
     * 1. launch_phases - Definisi fase peluncuran
     * 2. launch_phase_metrics - Metrik per fase (go/no-go criteria)
     * 3. launch_metric_snapshots - Snapshot metrik harian
     * 4. pilot_users - User dalam program pilot
     * 5. pilot_tiers - Tier/paket untuk setiap fase
     * 6. pilot_user_metrics - Metrik per user pilot
     * 7. phase_transition_logs - Log transisi antar fase
     * 8. corporate_prospects - Pipeline corporate
     * 9. launch_communications - Template komunikasi per fase
     * 10. launch_checklists - Checklist per fase
     */
    public function up(): void
    {
        // =====================================================
        // 1. LAUNCH PHASES
        // Definisi fase peluncuran dengan target & kriteria
        // =====================================================
        Schema::create('launch_phases', function (Blueprint $table) {
            $table->id();
            $table->string('phase_code', 50)->unique(); // umkm_pilot, umkm_scale, corporate
            $table->string('phase_name');
            $table->text('description');
            
            // Target & Duration
            $table->integer('target_users_min');
            $table->integer('target_users_max');
            $table->integer('estimated_duration_days');
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            
            // Actual Progress
            $table->enum('status', ['planned', 'active', 'completed', 'paused', 'skipped'])->default('planned');
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->integer('current_user_count')->default(0);
            
            // Limits untuk fase ini
            $table->integer('max_daily_messages_per_user')->default(1000);
            $table->integer('max_campaign_size')->default(500);
            $table->integer('max_messages_per_minute')->default(20);
            $table->boolean('require_manual_approval')->default(true);
            $table->boolean('self_service_enabled')->default(false);
            
            // Go/No-Go Thresholds
            $table->decimal('min_delivery_rate', 5, 2)->default(90);
            $table->decimal('max_abuse_rate', 5, 2)->default(5);
            $table->decimal('min_error_budget', 5, 2)->default(50);
            $table->integer('max_incidents_per_week')->default(2);
            $table->integer('max_support_tickets_per_user_week')->default(3);
            
            // Revenue targets
            $table->decimal('target_revenue_min', 15, 2)->default(0);
            $table->decimal('target_revenue_max', 15, 2)->nullable();
            $table->decimal('actual_revenue', 15, 2)->default(0);
            
            // Order
            $table->integer('phase_order')->default(1);
            
            $table->timestamps();
            
            $table->index('status');
            $table->index('phase_order');
        });

        // =====================================================
        // 2. LAUNCH PHASE METRICS
        // Metrik yang di-track per fase (go/no-go criteria)
        // =====================================================
        Schema::create('launch_phase_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_phase_id')->constrained()->onDelete('cascade');
            
            // Metric Definition
            $table->string('metric_code', 50); // delivery_rate, abuse_rate, etc.
            $table->string('metric_name');
            $table->text('description');
            $table->string('unit', 20); // %, count, seconds, currency
            
            // Threshold
            $table->enum('comparison', ['gte', 'lte', 'eq', 'between']);
            $table->decimal('threshold_value', 15, 4);
            $table->decimal('threshold_value_max', 15, 4)->nullable(); // for 'between'
            
            // Go/No-Go
            $table->boolean('is_go_criteria')->default(true); // true = harus terpenuhi untuk lanjut
            $table->boolean('is_blocking')->default(false); // true = jika fail, STOP
            $table->integer('weight')->default(10); // importance weight
            
            // Current Value
            $table->decimal('current_value', 15, 4)->nullable();
            $table->enum('current_status', ['passing', 'warning', 'failing', 'unknown'])->default('unknown');
            $table->timestamp('last_evaluated_at')->nullable();
            
            $table->timestamps();
            
            $table->unique(['launch_phase_id', 'metric_code']);
            $table->index('current_status');
        });

        // =====================================================
        // 3. LAUNCH METRIC SNAPSHOTS
        // Snapshot metrik harian untuk tracking progress
        // =====================================================
        Schema::create('launch_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_phase_id')->constrained()->onDelete('cascade');
            $table->date('snapshot_date');
            
            // Core Metrics
            $table->integer('total_users')->default(0);
            $table->integer('active_users')->default(0);
            $table->integer('new_users_today')->default(0);
            $table->integer('churned_users_today')->default(0);
            
            // Volume Metrics
            $table->bigInteger('messages_sent')->default(0);
            $table->bigInteger('messages_delivered')->default(0);
            $table->bigInteger('messages_failed')->default(0);
            $table->decimal('delivery_rate', 5, 2)->default(0);
            
            // Quality Metrics
            $table->decimal('abuse_rate', 5, 2)->default(0);
            $table->integer('abuse_incidents')->default(0);
            $table->integer('banned_users')->default(0);
            $table->integer('suspended_users')->default(0);
            
            // Reliability Metrics
            $table->decimal('error_budget_remaining', 5, 2)->default(100);
            $table->integer('incidents_count')->default(0);
            $table->string('highest_incident_severity', 10)->nullable();
            $table->integer('downtime_minutes')->default(0);
            
            // Support Metrics
            $table->integer('support_tickets')->default(0);
            $table->decimal('avg_resolution_hours', 8, 2)->nullable();
            $table->integer('complaints')->default(0);
            
            // Revenue Metrics
            $table->decimal('revenue_today', 15, 2)->default(0);
            $table->decimal('revenue_mtd', 15, 2)->default(0);
            $table->decimal('arpu', 10, 2)->default(0); // Average Revenue Per User
            
            // Go/No-Go Summary
            $table->integer('metrics_passing')->default(0);
            $table->integer('metrics_warning')->default(0);
            $table->integer('metrics_failing')->default(0);
            $table->boolean('ready_for_next_phase')->default(false);
            $table->text('blockers')->nullable(); // JSON array of blocking issues
            
            $table->timestamps();
            
            $table->unique(['launch_phase_id', 'snapshot_date']);
            $table->index('snapshot_date');
        });

        // =====================================================
        // 4. PILOT TIERS (moved before pilot_users due to FK)
        // Tier/paket yang tersedia per fase
        // =====================================================
        Schema::create('pilot_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_code', 50)->unique();
            $table->string('tier_name');
            $table->text('description');
            
            // Target Segment
            $table->enum('target_segment', ['umkm', 'sme', 'corporate', 'enterprise']);
            $table->foreignId('launch_phase_id')->nullable()->constrained()->nullOnDelete();
            
            // Pricing
            $table->decimal('price_monthly', 15, 2);
            $table->decimal('price_yearly', 15, 2)->nullable();
            $table->decimal('price_per_message', 10, 6)->nullable();
            $table->integer('included_messages')->default(0);
            $table->decimal('overage_price', 10, 6)->nullable();
            
            // Limits
            $table->integer('max_daily_messages');
            $table->integer('max_campaign_size');
            $table->integer('max_contacts');
            $table->integer('rate_limit_per_minute');
            
            // Features
            $table->boolean('api_access')->default(false);
            $table->boolean('webhook_support')->default(false);
            $table->boolean('dedicated_number')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->boolean('analytics_advanced')->default(false);
            $table->json('features_list')->nullable(); // additional features
            
            // SLA (for corporate)
            $table->decimal('sla_uptime', 5, 2)->nullable();
            $table->decimal('sla_delivery_rate', 5, 2)->nullable();
            $table->integer('sla_response_hours')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->integer('display_order')->default(0);
            
            $table->timestamps();
            
            $table->index(['target_segment', 'is_active']);
        });

        // =====================================================
        // 5. PILOT USERS
        // User yang participate dalam pilot program
        // =====================================================
        Schema::create('pilot_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('pilot_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('launch_phase_id')->constrained()->onDelete('cascade');
            
            // User Info
            $table->string('company_name');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            $table->enum('business_type', ['umkm', 'sme', 'corporate', 'enterprise']);
            $table->string('industry')->nullable();
            
            // Pilot Status
            $table->enum('status', [
                'pending_approval',
                'approved',
                'active',
                'paused',
                'churned',
                'graduated', // lulus ke fase berikutnya
                'rejected',
                'banned'
            ])->default('pending_approval');
            
            // Dates
            $table->timestamp('applied_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('graduated_at')->nullable();
            $table->timestamp('churned_at')->nullable();
            
            // Tier & Limits
            $table->foreignId('pilot_tier_id')->nullable()->constrained('pilot_tiers')->nullOnDelete();
            $table->integer('custom_daily_limit')->nullable();
            $table->integer('custom_rate_limit')->nullable();
            
            // Performance Summary
            $table->bigInteger('total_messages_sent')->default(0);
            $table->decimal('avg_delivery_rate', 5, 2)->default(0);
            $table->decimal('abuse_score', 5, 2)->default(0);
            $table->integer('abuse_incidents')->default(0);
            $table->integer('support_tickets')->default(0);
            
            // Revenue
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('monthly_revenue', 15, 2)->default(0);
            
            // Feedback
            $table->integer('nps_score')->nullable(); // -100 to 100
            $table->text('feedback_notes')->nullable();
            $table->boolean('willing_to_testimonial')->default(false);
            $table->boolean('willing_to_case_study')->default(false);
            
            // Internal Notes
            $table->text('internal_notes')->nullable();
            $table->string('assigned_to')->nullable(); // account manager
            
            $table->timestamps();
            
            $table->index(['launch_phase_id', 'status']);
            $table->index('business_type');
        });

        // =====================================================
        // 6. PILOT USER METRICS
        // Metrik detail per user pilot
        // =====================================================
        Schema::create('pilot_user_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pilot_user_id')->constrained()->onDelete('cascade');
            $table->date('metric_date');
            
            // Volume
            $table->integer('messages_sent')->default(0);
            $table->integer('messages_delivered')->default(0);
            $table->integer('messages_failed')->default(0);
            $table->decimal('delivery_rate', 5, 2)->default(0);
            
            // Quality
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->integer('abuse_flags')->default(0);
            $table->integer('spam_reports')->default(0);
            
            // Engagement
            $table->integer('campaigns_sent')->default(0);
            $table->integer('api_calls')->default(0);
            $table->integer('login_count')->default(0);
            
            // Support
            $table->integer('support_tickets')->default(0);
            $table->integer('feature_requests')->default(0);
            
            // Revenue
            $table->decimal('revenue', 15, 2)->default(0);
            $table->integer('messages_billed')->default(0);
            
            $table->timestamps();
            
            $table->unique(['pilot_user_id', 'metric_date']);
            $table->index('metric_date');
        });

        // =====================================================
        // 7. PHASE TRANSITION LOGS
        // Log perpindahan antar fase
        // =====================================================
        Schema::create('phase_transition_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('transition_id')->unique();
            
            // From -> To
            $table->foreignId('from_phase_id')->nullable()->constrained('launch_phases')->nullOnDelete();
            $table->foreignId('to_phase_id')->constrained('launch_phases')->onDelete('cascade');
            
            // Decision
            $table->enum('decision', ['proceed', 'extend', 'pause', 'rollback']);
            $table->text('decision_reason');
            $table->string('decided_by');
            $table->timestamp('decided_at');
            
            // Metrics at Transition
            $table->json('metrics_snapshot'); // all metrics at transition point
            $table->integer('go_criteria_met')->default(0);
            $table->integer('go_criteria_total')->default(0);
            $table->json('blockers_resolved')->nullable();
            $table->json('risks_accepted')->nullable();
            
            // Conditions
            $table->json('conditions')->nullable(); // conditions for transition
            $table->text('action_items')->nullable(); // things to do after transition
            
            // Execution
            $table->timestamp('executed_at')->nullable();
            $table->enum('execution_status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->text('execution_notes')->nullable();
            
            $table->timestamps();
            
            $table->index('decision');
            $table->index('execution_status');
        });

        // =====================================================
        // 8. CORPORATE PROSPECTS
        // Pipeline untuk corporate (sebelum jadi pilot)
        // =====================================================
        Schema::create('corporate_prospects', function (Blueprint $table) {
            $table->id();
            $table->uuid('prospect_id')->unique();
            
            // Company Info
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable(); // employees range
            $table->string('website')->nullable();
            
            // Contact
            $table->string('contact_name');
            $table->string('contact_title')->nullable();
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            
            // Source & Referral
            $table->string('source')->nullable(); // referral, inbound, outbound
            $table->string('referral_from')->nullable();
            $table->text('how_found_us')->nullable();
            
            // Requirements
            $table->integer('estimated_monthly_volume')->nullable();
            $table->text('use_case')->nullable();
            $table->json('requirements')->nullable();
            $table->text('current_solution')->nullable();
            
            // Pipeline Status
            $table->enum('status', [
                'lead',
                'qualified',
                'demo_scheduled',
                'demo_completed',
                'proposal_sent',
                'negotiation',
                'won',
                'lost',
                'on_hold'
            ])->default('lead');
            
            // Deal Info
            $table->decimal('deal_value', 15, 2)->nullable();
            $table->string('deal_term')->nullable(); // monthly, yearly
            $table->integer('probability_percent')->nullable();
            $table->date('expected_close_date')->nullable();
            
            // Objections & Notes
            $table->text('objections')->nullable();
            $table->text('notes')->nullable();
            
            // Assignment
            $table->string('assigned_to')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            
            // Conversion
            $table->foreignId('converted_to_pilot_id')->nullable()->constrained('pilot_users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            
            $table->timestamps();
            
            $table->index('status');
            $table->index('assigned_to');
        });

        // =====================================================
        // 9. LAUNCH COMMUNICATIONS
        // Template komunikasi per fase
        // =====================================================
        Schema::create('launch_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_phase_id')->nullable()->constrained()->nullOnDelete();
            
            // Template Info
            $table->string('template_code', 50)->unique();
            $table->string('template_name');
            $table->enum('channel', ['email', 'whatsapp', 'sms', 'in_app']);
            $table->enum('purpose', [
                'welcome',
                'onboarding',
                'tips',
                'milestone',
                'warning',
                'upgrade_offer',
                'feedback_request',
                'phase_transition',
                'incident_notification'
            ]);
            
            // Target
            $table->enum('target_segment', ['all', 'umkm', 'sme', 'corporate']);
            
            // Content
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('variables')->nullable(); // {{name}}, {{company}}, etc.
            
            // Timing
            $table->enum('trigger', ['manual', 'event', 'scheduled', 'milestone']);
            $table->string('trigger_event')->nullable();
            $table->integer('delay_hours')->default(0);
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->integer('times_sent')->default(0);
            $table->decimal('open_rate', 5, 2)->nullable();
            $table->decimal('click_rate', 5, 2)->nullable();
            
            $table->timestamps();
            
            $table->index(['launch_phase_id', 'purpose']);
        });

        // =====================================================
        // 10. LAUNCH CHECKLISTS
        // Checklist yang harus dipenuhi per fase
        // =====================================================
        Schema::create('launch_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('launch_phase_id')->constrained()->onDelete('cascade');
            
            // Item
            $table->string('item_code', 50);
            $table->string('item_title');
            $table->text('item_description');
            $table->enum('category', [
                'technical',
                'operational',
                'commercial',
                'legal',
                'marketing',
                'support'
            ]);
            
            // Requirements
            $table->boolean('is_required')->default(true);
            $table->enum('when_required', ['before_start', 'during', 'before_next_phase']);
            
            // Status
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('evidence_url')->nullable();
            
            // Order
            $table->integer('display_order')->default(0);
            
            $table->timestamps();
            
            $table->unique(['launch_phase_id', 'item_code']);
            $table->index('is_completed');
        });

        // =====================================================
        // SEED DEFAULT DATA
        // =====================================================
        $this->seedDefaultData();
    }

    private function seedDefaultData(): void
    {
        $now = now();

        // =====================================================
        // LAUNCH PHASES
        // =====================================================
        $phases = [
            [
                'id' => 1,
                'phase_code' => 'umkm_pilot',
                'phase_name' => 'UMKM Pilot',
                'description' => 'Fase pilot dengan 10-50 UMKM terpilih untuk validasi sistem dengan traffic nyata. Volume kecil, monitoring ketat, manual approval.',
                'target_users_min' => 10,
                'target_users_max' => 50,
                'estimated_duration_days' => 30,
                'status' => 'active',
                'max_daily_messages_per_user' => 500,
                'max_campaign_size' => 200,
                'max_messages_per_minute' => 10,
                'require_manual_approval' => true,
                'self_service_enabled' => false,
                'min_delivery_rate' => 90,
                'max_abuse_rate' => 3,
                'min_error_budget' => 60,
                'max_incidents_per_week' => 2,
                'max_support_tickets_per_user_week' => 5,
                'target_revenue_min' => 5000000,
                'phase_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'phase_code' => 'umkm_scale',
                'phase_name' => 'UMKM Scale',
                'description' => 'Scaling ke 100-300 UMKM dengan self-service penuh. Auto-suspend aktif, risk scoring berjalan, optimasi pricing.',
                'target_users_min' => 100,
                'target_users_max' => 300,
                'estimated_duration_days' => 60,
                'status' => 'planned',
                'max_daily_messages_per_user' => 2000,
                'max_campaign_size' => 1000,
                'max_messages_per_minute' => 30,
                'require_manual_approval' => false,
                'self_service_enabled' => true,
                'min_delivery_rate' => 92,
                'max_abuse_rate' => 5,
                'min_error_budget' => 50,
                'max_incidents_per_week' => 3,
                'max_support_tickets_per_user_week' => 3,
                'target_revenue_min' => 50000000,
                'phase_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'phase_code' => 'corporate_onboard',
                'phase_name' => 'Corporate Onboarding',
                'description' => 'Onboarding 5-20 corporate dengan invite-only, paket custom, SLA tertulis, dedicated support.',
                'target_users_min' => 5,
                'target_users_max' => 20,
                'estimated_duration_days' => 90,
                'status' => 'planned',
                'max_daily_messages_per_user' => 50000,
                'max_campaign_size' => 10000,
                'max_messages_per_minute' => 100,
                'require_manual_approval' => true,
                'self_service_enabled' => false,
                'min_delivery_rate' => 95,
                'max_abuse_rate' => 1,
                'min_error_budget' => 70,
                'max_incidents_per_week' => 1,
                'max_support_tickets_per_user_week' => 10,
                'target_revenue_min' => 200000000,
                'phase_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('launch_phases')->insert($phases);

        // =====================================================
        // PILOT TIERS
        // =====================================================
        $tiers = [
            // UMKM Tiers
            [
                'tier_code' => 'umkm_basic',
                'tier_name' => 'UMKM Basic',
                'description' => 'Paket dasar untuk UMKM dengan volume kecil. Cocok untuk notifikasi dan promo sederhana.',
                'target_segment' => 'umkm',
                'launch_phase_id' => 1,
                'price_monthly' => 299000,
                'price_yearly' => 2990000,
                'price_per_message' => 150,
                'included_messages' => 2000,
                'overage_price' => 175,
                'max_daily_messages' => 500,
                'max_campaign_size' => 200,
                'max_contacts' => 1000,
                'rate_limit_per_minute' => 10,
                'api_access' => false,
                'webhook_support' => false,
                'dedicated_number' => false,
                'priority_support' => false,
                'analytics_advanced' => false,
                'sla_uptime' => null,
                'sla_delivery_rate' => null,
                'sla_response_hours' => null,
                'is_active' => true,
                'display_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tier_code' => 'umkm_pro',
                'tier_name' => 'UMKM Pro',
                'description' => 'Paket pro untuk UMKM dengan volume menengah. Termasuk API access dan analytics.',
                'target_segment' => 'umkm',
                'launch_phase_id' => 1,
                'price_monthly' => 599000,
                'price_yearly' => 5990000,
                'price_per_message' => 125,
                'included_messages' => 5000,
                'overage_price' => 150,
                'max_daily_messages' => 2000,
                'max_campaign_size' => 500,
                'max_contacts' => 5000,
                'rate_limit_per_minute' => 20,
                'api_access' => true,
                'webhook_support' => true,
                'dedicated_number' => false,
                'priority_support' => false,
                'analytics_advanced' => true,
                'sla_uptime' => null,
                'sla_delivery_rate' => null,
                'sla_response_hours' => null,
                'is_active' => true,
                'display_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // SME Tier
            [
                'tier_code' => 'sme_business',
                'tier_name' => 'SME Business',
                'description' => 'Paket bisnis untuk SME. Volume tinggi dengan priority support.',
                'target_segment' => 'sme',
                'launch_phase_id' => 2,
                'price_monthly' => 1499000,
                'price_yearly' => 14990000,
                'price_per_message' => 100,
                'included_messages' => 15000,
                'overage_price' => 125,
                'max_daily_messages' => 10000,
                'max_campaign_size' => 2000,
                'max_contacts' => 20000,
                'rate_limit_per_minute' => 50,
                'api_access' => true,
                'webhook_support' => true,
                'dedicated_number' => false,
                'priority_support' => true,
                'analytics_advanced' => true,
                'sla_uptime' => null,
                'sla_delivery_rate' => null,
                'sla_response_hours' => null,
                'is_active' => true,
                'display_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Corporate Tiers
            [
                'tier_code' => 'corporate_standard',
                'tier_name' => 'Corporate Standard',
                'description' => 'Paket corporate dengan SLA dasar. Dedicated number dan priority support.',
                'target_segment' => 'corporate',
                'launch_phase_id' => 3,
                'price_monthly' => 4999000,
                'price_yearly' => 49990000,
                'price_per_message' => 75,
                'included_messages' => 75000,
                'overage_price' => 100,
                'max_daily_messages' => 50000,
                'max_campaign_size' => 10000,
                'max_contacts' => 100000,
                'rate_limit_per_minute' => 100,
                'api_access' => true,
                'webhook_support' => true,
                'dedicated_number' => true,
                'priority_support' => true,
                'analytics_advanced' => true,
                'sla_uptime' => 99.5,
                'sla_delivery_rate' => 95,
                'sla_response_hours' => 4,
                'is_active' => true,
                'display_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tier_code' => 'corporate_premium',
                'tier_name' => 'Corporate Premium',
                'description' => 'Paket premium untuk enterprise. SLA tinggi, dedicated account manager.',
                'target_segment' => 'enterprise',
                'launch_phase_id' => 3,
                'price_monthly' => 14999000,
                'price_yearly' => 149990000,
                'price_per_message' => 50,
                'included_messages' => 300000,
                'overage_price' => 75,
                'max_daily_messages' => 200000,
                'max_campaign_size' => 50000,
                'max_contacts' => 500000,
                'rate_limit_per_minute' => 200,
                'api_access' => true,
                'webhook_support' => true,
                'dedicated_number' => true,
                'priority_support' => true,
                'analytics_advanced' => true,
                'sla_uptime' => 99.9,
                'sla_delivery_rate' => 97,
                'sla_response_hours' => 1,
                'is_active' => true,
                'display_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('pilot_tiers')->insert($tiers);

        // =====================================================
        // LAUNCH PHASE METRICS (Go/No-Go Criteria)
        // =====================================================
        $metrics = [
            // UMKM Pilot Metrics
            ['launch_phase_id' => 1, 'metric_code' => 'delivery_rate', 'metric_name' => 'Delivery Rate', 'description' => 'Persentase pesan yang berhasil terkirim', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 90, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 25],
            ['launch_phase_id' => 1, 'metric_code' => 'abuse_rate', 'metric_name' => 'Abuse Rate', 'description' => 'Persentase user yang melakukan abuse', 'unit' => '%', 'comparison' => 'lte', 'threshold_value' => 3, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            ['launch_phase_id' => 1, 'metric_code' => 'error_budget', 'metric_name' => 'Error Budget Remaining', 'description' => 'Sisa error budget', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 60, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            ['launch_phase_id' => 1, 'metric_code' => 'weekly_incidents', 'metric_name' => 'Weekly Incidents', 'description' => 'Jumlah incident per minggu', 'unit' => 'count', 'comparison' => 'lte', 'threshold_value' => 2, 'is_go_criteria' => true, 'is_blocking' => false, 'weight' => 15],
            ['launch_phase_id' => 1, 'metric_code' => 'support_tickets', 'metric_name' => 'Support Tickets per User', 'description' => 'Rata-rata ticket support per user per minggu', 'unit' => 'count', 'comparison' => 'lte', 'threshold_value' => 5, 'is_go_criteria' => true, 'is_blocking' => false, 'weight' => 10],
            ['launch_phase_id' => 1, 'metric_code' => 'user_count', 'metric_name' => 'Active Users', 'description' => 'Jumlah user aktif', 'unit' => 'count', 'comparison' => 'gte', 'threshold_value' => 10, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 10],
            
            // UMKM Scale Metrics
            ['launch_phase_id' => 2, 'metric_code' => 'delivery_rate', 'metric_name' => 'Delivery Rate', 'description' => 'Persentase pesan yang berhasil terkirim', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 92, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 25],
            ['launch_phase_id' => 2, 'metric_code' => 'abuse_rate', 'metric_name' => 'Abuse Rate', 'description' => 'Persentase user yang melakukan abuse', 'unit' => '%', 'comparison' => 'lte', 'threshold_value' => 5, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            ['launch_phase_id' => 2, 'metric_code' => 'error_budget', 'metric_name' => 'Error Budget Remaining', 'description' => 'Sisa error budget', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 50, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            ['launch_phase_id' => 2, 'metric_code' => 'revenue_target', 'metric_name' => 'Revenue Achievement', 'description' => 'Pencapaian target revenue', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 70, 'is_go_criteria' => true, 'is_blocking' => false, 'weight' => 15],
            ['launch_phase_id' => 2, 'metric_code' => 'user_count', 'metric_name' => 'Active Users', 'description' => 'Jumlah user aktif', 'unit' => 'count', 'comparison' => 'gte', 'threshold_value' => 100, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            
            // Corporate Metrics
            ['launch_phase_id' => 3, 'metric_code' => 'delivery_rate', 'metric_name' => 'Delivery Rate', 'description' => 'Persentase pesan yang berhasil terkirim', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 95, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 30],
            ['launch_phase_id' => 3, 'metric_code' => 'sla_uptime', 'metric_name' => 'SLA Uptime', 'description' => 'Uptime sesuai SLA', 'unit' => '%', 'comparison' => 'gte', 'threshold_value' => 99.5, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 25],
            ['launch_phase_id' => 3, 'metric_code' => 'abuse_rate', 'metric_name' => 'Abuse Rate', 'description' => 'Persentase user yang melakukan abuse', 'unit' => '%', 'comparison' => 'lte', 'threshold_value' => 1, 'is_go_criteria' => true, 'is_blocking' => true, 'weight' => 20],
            ['launch_phase_id' => 3, 'metric_code' => 'case_studies', 'metric_name' => 'Case Studies', 'description' => 'Jumlah case study dari UMKM', 'unit' => 'count', 'comparison' => 'gte', 'threshold_value' => 3, 'is_go_criteria' => true, 'is_blocking' => false, 'weight' => 15],
            ['launch_phase_id' => 3, 'metric_code' => 'nps_score', 'metric_name' => 'NPS Score', 'description' => 'Net Promoter Score dari pilot users', 'unit' => 'score', 'comparison' => 'gte', 'threshold_value' => 30, 'is_go_criteria' => true, 'is_blocking' => false, 'weight' => 10],
        ];

        foreach ($metrics as $metric) {
            DB::table('launch_phase_metrics')->insert(array_merge($metric, [
                'current_status' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // =====================================================
        // LAUNCH CHECKLISTS
        // =====================================================
        $checklists = [
            // UMKM Pilot Checklists
            ['launch_phase_id' => 1, 'item_code' => 'pilot_billing_ready', 'item_title' => 'Billing System Ready', 'item_description' => 'Sistem billing sudah terintegrasi dan teruji', 'category' => 'technical', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 1],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_antiban_active', 'item_title' => 'Anti-Ban System Active', 'item_description' => 'Sistem anti-ban dan throttling sudah aktif', 'category' => 'technical', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 2],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_monitoring_setup', 'item_title' => 'Monitoring Dashboard', 'item_description' => 'Dashboard monitoring untuk owner sudah siap', 'category' => 'operational', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 3],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_support_ready', 'item_title' => 'Support Channel Ready', 'item_description' => 'Channel support (WA/Email) sudah siap', 'category' => 'support', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 4],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_tos_ready', 'item_title' => 'Terms of Service', 'item_description' => 'ToS dan kebijakan penggunaan sudah final', 'category' => 'legal', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 5],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_onboarding_docs', 'item_title' => 'Onboarding Documentation', 'item_description' => 'Dokumentasi onboarding untuk pilot users', 'category' => 'marketing', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 6],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_feedback_form', 'item_title' => 'Feedback Collection', 'item_description' => 'Form feedback untuk pilot users', 'category' => 'operational', 'is_required' => true, 'when_required' => 'during', 'display_order' => 7],
            ['launch_phase_id' => 1, 'item_code' => 'pilot_metrics_review', 'item_title' => 'Weekly Metrics Review', 'item_description' => 'Review metrik mingguan dengan tim', 'category' => 'operational', 'is_required' => true, 'when_required' => 'during', 'display_order' => 8],
            
            // UMKM Scale Checklists
            ['launch_phase_id' => 2, 'item_code' => 'scale_selfservice', 'item_title' => 'Self-Service Registration', 'item_description' => 'Sistem registrasi self-service sudah aktif', 'category' => 'technical', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 1],
            ['launch_phase_id' => 2, 'item_code' => 'scale_autosuspend', 'item_title' => 'Auto-Suspend System', 'item_description' => 'Sistem auto-suspend untuk abuse sudah aktif', 'category' => 'technical', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 2],
            ['launch_phase_id' => 2, 'item_code' => 'scale_pricing_final', 'item_title' => 'Pricing Finalized', 'item_description' => 'Pricing sudah divalidasi dari pilot', 'category' => 'commercial', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 3],
            ['launch_phase_id' => 2, 'item_code' => 'scale_faq_ready', 'item_title' => 'FAQ & Help Center', 'item_description' => 'FAQ dan help center sudah lengkap', 'category' => 'support', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 4],
            
            // Corporate Checklists
            ['launch_phase_id' => 3, 'item_code' => 'corp_sla_template', 'item_title' => 'SLA Template Ready', 'item_description' => 'Template SLA untuk corporate sudah final', 'category' => 'legal', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 1],
            ['launch_phase_id' => 3, 'item_code' => 'corp_case_studies', 'item_title' => 'Case Studies Ready', 'item_description' => 'Minimal 3 case study dari UMKM pilot', 'category' => 'marketing', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 2],
            ['launch_phase_id' => 3, 'item_code' => 'corp_sales_deck', 'item_title' => 'Sales Deck Ready', 'item_description' => 'Presentation deck untuk corporate sales', 'category' => 'marketing', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 3],
            ['launch_phase_id' => 3, 'item_code' => 'corp_dedicated_support', 'item_title' => 'Dedicated Support Ready', 'item_description' => 'Tim support dedicated untuk corporate', 'category' => 'support', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 4],
            ['launch_phase_id' => 3, 'item_code' => 'corp_reporting', 'item_title' => 'Corporate Reporting', 'item_description' => 'Sistem reporting untuk SLA dan analytics', 'category' => 'technical', 'is_required' => true, 'when_required' => 'before_start', 'display_order' => 5],
        ];

        foreach ($checklists as $item) {
            DB::table('launch_checklists')->insert(array_merge($item, [
                'is_completed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // =====================================================
        // LAUNCH COMMUNICATIONS
        // =====================================================
        $communications = [
            [
                'launch_phase_id' => 1,
                'template_code' => 'pilot_welcome',
                'template_name' => 'Pilot Welcome Email',
                'channel' => 'email',
                'purpose' => 'welcome',
                'target_segment' => 'umkm',
                'subject' => 'Selamat Datang di Program Pilot Talkabiz! 🎉',
                'body' => "Halo {{name}} dari {{company}}!\n\nTerima kasih sudah bergabung dengan program pilot Talkabiz. Anda adalah bagian dari {{pilot_count}} bisnis terpilih yang akan mencoba platform kami terlebih dahulu.\n\nYang perlu Anda ketahui:\n- Akun Anda sudah aktif dengan paket {{tier_name}}\n- Limit harian: {{daily_limit}} pesan\n- Tim kami akan memantau dan membantu Anda secara langsung\n\nMulai kirim pesan pertama Anda sekarang!\n\nSalam,\nTim Talkabiz",
                'variables' => json_encode(['name', 'company', 'pilot_count', 'tier_name', 'daily_limit']),
                'trigger' => 'event',
                'trigger_event' => 'pilot_approved',
                'is_active' => true,
            ],
            [
                'launch_phase_id' => 1,
                'template_code' => 'pilot_feedback_request',
                'template_name' => 'Pilot Feedback Request',
                'channel' => 'email',
                'purpose' => 'feedback_request',
                'target_segment' => 'umkm',
                'subject' => 'Pendapat Anda Sangat Berharga 🙏',
                'body' => "Halo {{name}}!\n\nSudah {{days_active}} hari Anda menggunakan Talkabiz. Kami ingin mendengar pengalaman Anda!\n\nStatistik Anda:\n- Pesan terkirim: {{messages_sent}}\n- Delivery rate: {{delivery_rate}}%\n\nBantu kami dengan mengisi feedback singkat (2 menit):\n{{feedback_url}}\n\nTerima kasih atas partisipasi Anda dalam program pilot!\n\nSalam,\nTim Talkabiz",
                'variables' => json_encode(['name', 'days_active', 'messages_sent', 'delivery_rate', 'feedback_url']),
                'trigger' => 'scheduled',
                'delay_hours' => 168, // 7 days
                'is_active' => true,
            ],
            [
                'launch_phase_id' => 2,
                'template_code' => 'scale_upgrade_offer',
                'template_name' => 'Upgrade Offer',
                'channel' => 'email',
                'purpose' => 'upgrade_offer',
                'target_segment' => 'umkm',
                'subject' => 'Bisnis Anda Berkembang? Upgrade Sekarang! 🚀',
                'body' => "Halo {{name}}!\n\nKami lihat bisnis Anda berkembang pesat dengan {{messages_this_month}} pesan bulan ini.\n\nSaat ini Anda menggunakan paket {{current_tier}}. Upgrade ke {{recommended_tier}} untuk:\n- Limit lebih tinggi\n- Fitur tambahan\n- Harga per pesan lebih murah\n\nGunakan kode UPGRADE20 untuk diskon 20% bulan pertama!\n\nSalam,\nTim Talkabiz",
                'variables' => json_encode(['name', 'messages_this_month', 'current_tier', 'recommended_tier']),
                'trigger' => 'milestone',
                'is_active' => true,
            ],
        ];

        foreach ($communications as $comm) {
            DB::table('launch_communications')->insert(array_merge($comm, [
                'delay_hours' => $comm['delay_hours'] ?? 0,
                'times_sent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_checklists');
        Schema::dropIfExists('launch_communications');
        Schema::dropIfExists('corporate_prospects');
        Schema::dropIfExists('phase_transition_logs');
        Schema::dropIfExists('pilot_user_metrics');
        Schema::dropIfExists('pilot_users');
        Schema::dropIfExists('pilot_tiers');
        Schema::dropIfExists('launch_metric_snapshots');
        Schema::dropIfExists('launch_phase_metrics');
        Schema::dropIfExists('launch_phases');
    }
};
