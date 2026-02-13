<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SOFT-LAUNCH 30-DAY EXECUTION SYSTEM
 * 
 * Sistem untuk eksekusi 30 hari pertama soft-launch:
 * - Day 1-3: Stabilitas Awal (Observe)
 * - Day 4-7: Validasi Perilaku UMKM
 * - Day 8-14: Kontrol & Optimasi
 * - Day 15-21: Scale Terkontrol
 * - Day 22-30: Readiness Gate
 * 
 * Features:
 * - Execution periods dengan checklist
 * - Daily monitoring thresholds
 * - GO/NO-GO gates
 * - Larangan enforcement
 * - Daily ritual tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // 1. EXECUTION PERIODS
        // Periode eksekusi 30 hari
        // =====================================================
        Schema::create('execution_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_code', 50)->unique();
            $table->string('period_name');
            $table->text('description');
            $table->text('target'); // Target periode ini
            
            // Timeline
            $table->integer('day_start');
            $table->integer('day_end');
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            
            // Status
            $table->enum('status', ['upcoming', 'active', 'completed', 'failed'])->default('upcoming');
            
            // Monitoring Thresholds
            $table->decimal('min_delivery_rate', 5, 2)->default(90);
            $table->decimal('max_failure_rate', 5, 2)->default(3);
            $table->decimal('max_abuse_rate', 5, 2)->default(3);
            $table->decimal('max_risk_score', 5, 2)->default(40);
            $table->integer('max_incidents')->default(1);
            $table->decimal('min_error_budget', 5, 2)->default(60);
            $table->integer('max_queue_latency_p95')->default(30); // seconds
            
            // Campaign Limits
            $table->integer('max_campaign_recipients')->default(1000);
            $table->boolean('throttling_active')->default(true);
            $table->boolean('template_manual_approval')->default(true);
            $table->boolean('auto_pause_enabled')->default(false);
            $table->boolean('auto_suspend_enabled')->default(false);
            $table->boolean('self_service_enabled')->default(false);
            
            // Flags
            $table->boolean('corporate_flag_off')->default(true);
            $table->boolean('promo_blocked')->default(true);
            $table->boolean('phase_locked')->default(true);
            
            // Results
            $table->enum('gate_result', ['pending', 'go', 'no_go', 'conditional'])->default('pending');
            $table->text('gate_notes')->nullable();
            $table->timestamp('gate_decided_at')->nullable();
            
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        // =====================================================
        // 2. EXECUTION CHECKLISTS
        // Checklist per periode
        // =====================================================
        Schema::create('execution_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_period_id')->constrained()->onDelete('cascade');
            
            $table->string('item_code', 100);
            $table->string('item_title');
            $table->text('item_description')->nullable();
            
            $table->enum('category', [
                'setup',        // Persiapan awal
                'monitoring',   // Item monitoring
                'review',       // Review manual
                'action',       // Aksi yang harus dilakukan
                'gate'          // Gate decision
            ]);
            
            $table->boolean('is_required')->default(true);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by')->nullable();
            $table->text('notes')->nullable();
            
            $table->integer('display_order')->default(0);
            $table->timestamps();
            
            $table->unique(['execution_period_id', 'item_code']);
        });

        // =====================================================
        // 3. DAILY RITUALS
        // Tracking daily check oleh Owner/SA
        // =====================================================
        Schema::create('daily_rituals', function (Blueprint $table) {
            $table->id();
            $table->date('ritual_date')->unique();
            $table->foreignId('execution_period_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('day_number'); // Day 1-30
            
            // Ritual Status
            $table->boolean('dashboard_opened')->default(false);
            $table->timestamp('dashboard_opened_at')->nullable();
            $table->boolean('recommendation_read')->default(false);
            $table->timestamp('recommendation_read_at')->nullable();
            $table->boolean('decision_made')->default(false);
            $table->timestamp('decision_made_at')->nullable();
            
            // Decision
            $table->enum('decision', ['scale', 'hold', 'rollback', 'investigate', 'none'])->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('decided_by')->nullable();
            
            // Metrics Snapshot (at ritual time)
            $table->decimal('delivery_rate', 5, 2)->nullable();
            $table->decimal('failure_rate', 5, 2)->nullable();
            $table->decimal('abuse_rate', 5, 2)->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->decimal('error_budget', 5, 2)->nullable();
            $table->integer('incidents_count')->default(0);
            $table->integer('queue_latency_p95')->nullable();
            
            // Threshold Check Results
            $table->boolean('all_thresholds_met')->default(false);
            $table->json('threshold_results')->nullable(); // detailed pass/fail per metric
            
            // Action Recommendation
            $table->text('action_recommendation')->nullable();
            $table->enum('urgency', ['low', 'medium', 'high', 'critical'])->default('low');
            
            $table->timestamps();
        });

        // =====================================================
        // 4. EXECUTION VIOLATIONS
        // Track pelanggaran larangan
        // =====================================================
        Schema::create('execution_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_period_id')->nullable()->constrained()->nullOnDelete();
            
            $table->enum('violation_type', [
                'promo_attempt',          // Mencoba promo besar
                'corporate_access',       // Membuka akses corporate
                'template_override',      // Override template approval
                'auto_suspend_override',  // Override auto-suspend
                'limit_override',         // Override campaign limit
                'other'
            ]);
            
            $table->string('violation_title');
            $table->text('violation_description');
            $table->string('triggered_by')->nullable();
            $table->boolean('was_blocked')->default(true);
            $table->text('resolution')->nullable();
            
            $table->timestamps();
        });

        // =====================================================
        // 5. GO/NO-GO DECISIONS
        // Keputusan gate di akhir periode
        // =====================================================
        Schema::create('gate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_period_id')->constrained()->onDelete('cascade');
            
            $table->enum('decision', ['go', 'no_go', 'conditional']);
            $table->text('decision_reason');
            
            // Metrics at decision time
            $table->decimal('delivery_rate', 5, 2);
            $table->decimal('abuse_rate', 5, 2);
            $table->decimal('error_budget', 5, 2);
            $table->integer('incidents_total');
            
            // Checklist completion
            $table->integer('checklists_completed');
            $table->integer('checklists_total');
            $table->decimal('completion_rate', 5, 2);
            
            // Criteria evaluation
            $table->json('criteria_results'); // detailed pass/fail
            
            $table->string('decided_by');
            $table->timestamp('decided_at');
            
            // Follow-up
            $table->text('next_actions')->nullable();
            $table->text('conditions')->nullable(); // if conditional
            
            $table->timestamps();
        });

        // Seed default data
        $this->seedDefaultData();
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_decisions');
        Schema::dropIfExists('execution_violations');
        Schema::dropIfExists('daily_rituals');
        Schema::dropIfExists('execution_checklists');
        Schema::dropIfExists('execution_periods');
    }

    private function seedDefaultData(): void
    {
        $now = now();

        // =====================================================
        // EXECUTION PERIODS (5 periods)
        // =====================================================
        $periods = [
            [
                'period_code' => 'day_1_3',
                'period_name' => 'Stabilitas Awal (Observe)',
                'description' => 'Observasi sistem di traffic nyata. Fokus pada stabilitas dasar tanpa intervensi besar.',
                'target' => 'Sistem hidup, tidak ada lonjakan risiko',
                'day_start' => 1,
                'day_end' => 3,
                'min_delivery_rate' => 90,
                'max_failure_rate' => 3,
                'max_abuse_rate' => 3,
                'max_risk_score' => 40,
                'max_incidents' => 0,
                'min_error_budget' => 70,
                'max_queue_latency_p95' => 30,
                'max_campaign_recipients' => 1000,
                'throttling_active' => true,
                'template_manual_approval' => true,
                'auto_pause_enabled' => false,
                'auto_suspend_enabled' => false,
                'self_service_enabled' => false,
                'display_order' => 1,
            ],
            [
                'period_code' => 'day_4_7',
                'period_name' => 'Validasi Perilaku UMKM',
                'description' => 'Tangkap pola abuse & failure. Aktifkan auto-protection.',
                'target' => 'Tangkap pola abuse & failure',
                'day_start' => 4,
                'day_end' => 7,
                'min_delivery_rate' => 90,
                'max_failure_rate' => 3,
                'max_abuse_rate' => 2,
                'max_risk_score' => 40,
                'max_incidents' => 1,
                'min_error_budget' => 70,
                'max_queue_latency_p95' => 30,
                'max_campaign_recipients' => 1000,
                'throttling_active' => true,
                'template_manual_approval' => true,
                'auto_pause_enabled' => true,
                'auto_suspend_enabled' => true,
                'self_service_enabled' => false,
                'display_order' => 2,
            ],
            [
                'period_code' => 'day_8_14',
                'period_name' => 'Kontrol & Optimasi',
                'description' => 'Fine-tune sistem untuk stabilitas dengan margin aman.',
                'target' => 'Stabil + margin aman',
                'day_start' => 8,
                'day_end' => 14,
                'min_delivery_rate' => 92,
                'max_failure_rate' => 3,
                'max_abuse_rate' => 2,
                'max_risk_score' => 35,
                'max_incidents' => 1,
                'min_error_budget' => 65,
                'max_queue_latency_p95' => 25,
                'max_campaign_recipients' => 1500,
                'throttling_active' => true,
                'template_manual_approval' => true, // bisa dikurangi jika aman
                'auto_pause_enabled' => true,
                'auto_suspend_enabled' => true,
                'self_service_enabled' => false,
                'display_order' => 3,
            ],
            [
                'period_code' => 'day_15_21',
                'period_name' => 'Scale Terkontrol',
                'description' => 'Naikkan volume TANPA naikkan risiko. Self-service terbatas.',
                'target' => 'Naikkan volume TANPA naikkan risiko',
                'day_start' => 15,
                'day_end' => 21,
                'min_delivery_rate' => 92,
                'max_failure_rate' => 3,
                'max_abuse_rate' => 2,
                'max_risk_score' => 35,
                'max_incidents' => 2,
                'min_error_budget' => 60,
                'max_queue_latency_p95' => 25,
                'max_campaign_recipients' => 2000,
                'throttling_active' => true,
                'template_manual_approval' => false, // mulai auto
                'auto_pause_enabled' => true,
                'auto_suspend_enabled' => true,
                'self_service_enabled' => true,
                'display_order' => 4,
            ],
            [
                'period_code' => 'day_22_30',
                'period_name' => 'Readiness Gate',
                'description' => 'Evaluasi final 30 hari. Siapkan transisi ke UMKM Scale / Corporate.',
                'target' => 'Siap UMKM Scale / Corporate Invite',
                'day_start' => 22,
                'day_end' => 30,
                'min_delivery_rate' => 93,
                'max_failure_rate' => 2,
                'max_abuse_rate' => 2,
                'max_risk_score' => 30,
                'max_incidents' => 2,
                'min_error_budget' => 50,
                'max_queue_latency_p95' => 20,
                'max_campaign_recipients' => 2500,
                'throttling_active' => true,
                'template_manual_approval' => false,
                'auto_pause_enabled' => true,
                'auto_suspend_enabled' => true,
                'self_service_enabled' => true,
                'display_order' => 5,
            ],
        ];

        foreach ($periods as $period) {
            DB::table('execution_periods')->insert(array_merge($period, [
                'status' => 'upcoming',
                'corporate_flag_off' => true,
                'promo_blocked' => true,
                'phase_locked' => true,
                'gate_result' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        // =====================================================
        // EXECUTION CHECKLISTS
        // =====================================================
        $checklists = [
            // Day 1-3 Checklists
            ['period' => 'day_1_3', 'code' => 'phase_locked', 'title' => 'Phase terkunci: UMKM_PILOT', 'category' => 'setup', 'order' => 1],
            ['period' => 'day_1_3', 'code' => 'corporate_off', 'title' => 'Corporate flag: OFF', 'category' => 'setup', 'order' => 2],
            ['period' => 'day_1_3', 'code' => 'campaign_limit', 'title' => 'Campaign limit aktif (≤1.000 recipients)', 'category' => 'setup', 'order' => 3],
            ['period' => 'day_1_3', 'code' => 'throttling_active', 'title' => 'Throttling & delay aktif', 'category' => 'setup', 'order' => 4],
            ['period' => 'day_1_3', 'code' => 'template_manual', 'title' => 'Template approval manual', 'category' => 'setup', 'order' => 5],
            ['period' => 'day_1_3', 'code' => 'snapshot_daily', 'title' => 'Snapshot harian aktif', 'category' => 'setup', 'order' => 6],
            ['period' => 'day_1_3', 'code' => 'monitor_delivery', 'title' => 'Monitor: Delivery rate ≥ 90%', 'category' => 'monitoring', 'order' => 7],
            ['period' => 'day_1_3', 'code' => 'monitor_failure', 'title' => 'Monitor: Failure/reject ≤ 3%', 'category' => 'monitoring', 'order' => 8],
            ['period' => 'day_1_3', 'code' => 'monitor_risk', 'title' => 'Monitor: Risk score rata-rata < 40', 'category' => 'monitoring', 'order' => 9],
            ['period' => 'day_1_3', 'code' => 'monitor_queue', 'title' => 'Monitor: Queue latency P95 ≤ 30 detik', 'category' => 'monitoring', 'order' => 10],
            
            // Day 4-7 Checklists
            ['period' => 'day_4_7', 'code' => 'auto_pause_active', 'title' => 'Aktifkan auto-pause', 'category' => 'setup', 'order' => 1],
            ['period' => 'day_4_7', 'code' => 'auto_suspend_active', 'title' => 'Aktifkan auto-suspend', 'category' => 'setup', 'order' => 2],
            ['period' => 'day_4_7', 'code' => 'review_campaigns', 'title' => 'Review 5–10 campaign manual', 'category' => 'review', 'order' => 3],
            ['period' => 'day_4_7', 'code' => 'audit_template', 'title' => 'Audit template & link', 'category' => 'review', 'order' => 4],
            ['period' => 'day_4_7', 'code' => 'review_retry', 'title' => 'Review retry & idempotency', 'category' => 'review', 'order' => 5],
            ['period' => 'day_4_7', 'code' => 'monitor_abuse', 'title' => 'Monitor: Abuse rate ≤ 2%', 'category' => 'monitoring', 'order' => 6],
            ['period' => 'day_4_7', 'code' => 'monitor_incident', 'title' => 'Monitor: Incident ≤ 1', 'category' => 'monitoring', 'order' => 7],
            ['period' => 'day_4_7', 'code' => 'monitor_budget', 'title' => 'Monitor: Error budget ≥ 70%', 'category' => 'monitoring', 'order' => 8],
            ['period' => 'day_4_7', 'code' => 'gate_decision', 'title' => 'GO/NO-GO Decision', 'category' => 'gate', 'order' => 9],
            
            // Day 8-14 Checklists
            ['period' => 'day_8_14', 'code' => 'optimize_rate', 'title' => 'Optimasi rate per jam', 'category' => 'action', 'order' => 1],
            ['period' => 'day_8_14', 'code' => 'finetune_throttle', 'title' => 'Fine-tune throttle', 'category' => 'action', 'order' => 2],
            ['period' => 'day_8_14', 'code' => 'review_pricing', 'title' => 'Review pricing UMKM', 'category' => 'review', 'order' => 3],
            ['period' => 'day_8_14', 'code' => 'reduce_approval', 'title' => 'Kurangi manual approval jika aman', 'category' => 'action', 'order' => 4],
            ['period' => 'day_8_14', 'code' => 'monitor_delivery_92', 'title' => 'Monitor: Delivery rate ≥ 92%', 'category' => 'monitoring', 'order' => 5],
            ['period' => 'day_8_14', 'code' => 'monitor_failure_3', 'title' => 'Monitor: Failure ≤ 3%', 'category' => 'monitoring', 'order' => 6],
            ['period' => 'day_8_14', 'code' => 'monitor_risk_stable', 'title' => 'Monitor: Risk score stabil/turun', 'category' => 'monitoring', 'order' => 7],
            ['period' => 'day_8_14', 'code' => 'monitor_support', 'title' => 'Monitor: Support ticket manageable', 'category' => 'monitoring', 'order' => 8],
            ['period' => 'day_8_14', 'code' => 'output_config', 'title' => 'Output: Konfigurasi limit final', 'category' => 'action', 'order' => 9],
            ['period' => 'day_8_14', 'code' => 'output_baseline', 'title' => 'Output: Baseline KPI', 'category' => 'action', 'order' => 10],
            
            // Day 15-21 Checklists
            ['period' => 'day_15_21', 'code' => 'scale_users', 'title' => 'Naikkan user UMKM bertahap (+10–20%)', 'category' => 'action', 'order' => 1],
            ['period' => 'day_15_21', 'code' => 'selfservice_limited', 'title' => 'Aktifkan self-service terbatas', 'category' => 'action', 'order' => 2],
            ['period' => 'day_15_21', 'code' => 'monitor_burnrate', 'title' => 'Pantau error budget burn rate', 'category' => 'monitoring', 'order' => 3],
            ['period' => 'day_15_21', 'code' => 'snapshot_exec', 'title' => 'Snapshot harian & executive review', 'category' => 'monitoring', 'order' => 4],
            ['period' => 'day_15_21', 'code' => 'monitor_budget_60', 'title' => 'Monitor: Error budget ≥ 60%', 'category' => 'monitoring', 'order' => 5],
            ['period' => 'day_15_21', 'code' => 'monitor_incident_2', 'title' => 'Monitor: Incident ≤ 2/minggu', 'category' => 'monitoring', 'order' => 6],
            ['period' => 'day_15_21', 'code' => 'monitor_noban', 'title' => 'Monitor: No BAN / quality downgrade', 'category' => 'monitoring', 'order' => 7],
            
            // Day 22-30 Checklists
            ['period' => 'day_22_30', 'code' => 'review_30day', 'title' => 'Review 30-day metrics', 'category' => 'review', 'order' => 1],
            ['period' => 'day_22_30', 'code' => 'lock_umkm_rules', 'title' => 'Lock final UMKM rules', 'category' => 'action', 'order' => 2],
            ['period' => 'day_22_30', 'code' => 'prepare_casestudy', 'title' => 'Siapkan case study', 'category' => 'action', 'order' => 3],
            ['period' => 'day_22_30', 'code' => 'prepare_sla', 'title' => 'Siapkan SLA draft', 'category' => 'action', 'order' => 4],
            ['period' => 'day_22_30', 'code' => 'corporate_off_verify', 'title' => 'Corporate flag tetap OFF', 'category' => 'setup', 'order' => 5],
            ['period' => 'day_22_30', 'code' => 'final_gate', 'title' => 'GO/NO-GO ke UMKM Scale', 'category' => 'gate', 'order' => 6],
        ];

        foreach ($checklists as $item) {
            $periodId = DB::table('execution_periods')->where('period_code', $item['period'])->value('id');
            if ($periodId) {
                DB::table('execution_checklists')->insert([
                    'execution_period_id' => $periodId,
                    'item_code' => $item['code'],
                    'item_title' => $item['title'],
                    'item_description' => null,
                    'category' => $item['category'],
                    'is_required' => true,
                    'is_completed' => false,
                    'display_order' => $item['order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
