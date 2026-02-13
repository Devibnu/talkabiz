<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Anti-Ban Risk Scoring System
 * 
 * Sistem ini melindungi:
 * 1. Nomor WA sender dari ban
 * 2. Akun WABA dari quality downgrade
 * 3. Reputasi platform
 * 
 * SCORING MODEL:
 * ==============
 * Risk Score 0-100:
 * - 0-30:   SAFE (hijau)
 * - 31-60:  WARNING (kuning)
 * - 61-80:  HIGH_RISK (oranye)
 * - 81-100: CRITICAL (merah, ban imminent)
 * 
 * ENTITY TYPES:
 * =============
 * - user: Risk per klien/pengguna
 * - sender: Risk per nomor WA pengirim
 * - campaign: Risk per campaign
 * 
 * DECAY MECHANISM:
 * ================
 * Risk score decay over time jika tidak ada incident baru.
 * Decay rate: 5% per hari untuk SAFE, 2% per hari untuk WARNING+
 * 
 * @author Trust & Safety Engineer
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== RISK FACTORS (CONFIGURABLE) ====================
        Schema::create('risk_factors', function (Blueprint $table) {
            $table->id();
            
            $table->string('code', 50)->unique()
                  ->comment('Unique factor code');
            $table->string('name', 100)
                  ->comment('Human-readable name');
            $table->text('description')->nullable();
            
            // Weighting
            $table->decimal('weight', 5, 2)->default(1.0)
                  ->comment('Factor weight (1.0 = normal, 2.0 = double impact)');
            $table->decimal('max_contribution', 5, 2)->default(20.0)
                  ->comment('Max points this factor can contribute');
            
            // Thresholds
            $table->json('thresholds')->nullable()
                  ->comment('Threshold values for scoring');
            
            // Applicability
            $table->json('applies_to')->nullable()
                  ->comment('Entity types: ["user", "sender", "campaign"]');
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ==================== RISK SCORES (MAIN TABLE) ====================
        Schema::create('risk_scores', function (Blueprint $table) {
            $table->id();
            
            // Entity identification
            $table->string('entity_type', 20)
                  ->comment('user, sender, campaign');
            $table->unsignedBigInteger('entity_id')
                  ->comment('ID of the entity');
            $table->unsignedBigInteger('klien_id')->index()
                  ->comment('Owner klien for quick lookup');
            
            // Current score
            $table->decimal('score', 5, 2)->default(0)
                  ->comment('Current risk score 0-100');
            $table->string('risk_level', 20)->default('safe')
                  ->comment('safe, warning, high_risk, critical');
            
            // Score breakdown
            $table->json('factor_scores')->nullable()
                  ->comment('Individual factor contributions');
            
            // Trend tracking
            $table->decimal('score_24h_ago', 5, 2)->nullable();
            $table->decimal('score_7d_ago', 5, 2)->nullable();
            $table->string('trend', 10)->default('stable')
                  ->comment('improving, stable, worsening');
            
            // Incident counters
            $table->integer('total_incidents')->default(0);
            $table->integer('incidents_24h')->default(0);
            $table->integer('incidents_7d')->default(0);
            
            // Action status
            $table->string('current_action', 30)->nullable()
                  ->comment('Current enforced action: throttle, pause, suspend');
            $table->timestamp('action_applied_at')->nullable();
            $table->timestamp('action_expires_at')->nullable();
            
            // Decay tracking
            $table->timestamp('last_incident_at')->nullable();
            $table->timestamp('last_decay_at')->nullable();
            $table->integer('safe_days')->default(0)
                  ->comment('Consecutive days without major incident');
            
            // Admin override
            $table->boolean('is_whitelisted')->default(false);
            $table->boolean('is_blacklisted')->default(false);
            $table->string('admin_note', 500)->nullable();
            
            $table->timestamps();
            
            // Composite unique key
            $table->unique(['entity_type', 'entity_id']);
            
            // Indexes
            $table->index(['risk_level', 'entity_type']);
            $table->index(['score', 'entity_type']);
            $table->index('current_action');
        });

        // ==================== RISK EVENTS (INCIDENT LOG) ====================
        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            
            // Entity reference
            $table->unsignedBigInteger('risk_score_id')->index();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('klien_id')->index();
            
            // Event details
            $table->string('event_type', 50)
                  ->comment('failure, reject, block, spike, template_abuse, etc');
            $table->string('event_source', 50)->default('system')
                  ->comment('webhook, cron, manual, api');
            
            // Scoring impact
            $table->string('factor_code', 50)->nullable();
            $table->decimal('score_before', 5, 2);
            $table->decimal('score_after', 5, 2);
            $table->decimal('score_delta', 5, 2);
            
            // Context
            $table->unsignedBigInteger('related_id')->nullable()
                  ->comment('Related message_log_id, campaign_id, etc');
            $table->string('related_type', 50)->nullable();
            $table->json('event_data')->nullable();
            
            // Severity
            $table->string('severity', 20)->default('low')
                  ->comment('low, medium, high, critical');
            
            $table->timestamp('occurred_at');
            $table->timestamps();
            
            // Indexes
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index('severity');
        });

        // ==================== RISK ACTIONS (ACTION LOG) ====================
        Schema::create('risk_actions', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('risk_score_id')->index();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('klien_id')->index();
            
            // Action details
            $table->string('action_type', 30)
                  ->comment('throttle, pause, suspend, notify, whitelist, manual_review');
            $table->string('trigger_reason', 100)
                  ->comment('Why this action was taken');
            $table->decimal('score_at_action', 5, 2);
            $table->string('risk_level_at_action', 20);
            
            // Action parameters
            $table->json('action_params')->nullable()
                  ->comment('e.g., throttle_rate: 0.5, suspend_duration_hours: 24');
            
            // Status
            $table->string('status', 20)->default('active')
                  ->comment('active, expired, revoked, escalated');
            $table->timestamp('applied_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            
            // Who/what
            $table->string('applied_by', 50)->default('system');
            $table->string('revoked_by', 50)->nullable();
            $table->string('revoke_reason', 200)->nullable();
            
            // Outcome
            $table->boolean('was_effective')->nullable()
                  ->comment('Did score improve after action?');
            $table->decimal('score_after_action', 5, 2)->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['status', 'expires_at']);
            $table->index(['action_type', 'applied_at']);
        });

        // ==================== SEED DEFAULT RISK FACTORS ====================
        $this->seedDefaultFactors();
    }

    /**
     * Seed default risk factors with WA-realistic weights
     */
    protected function seedDefaultFactors(): void
    {
        $factors = [
            // ===== DELIVERY FACTORS =====
            [
                'code' => 'failure_ratio',
                'name' => 'Failure Rate',
                'description' => 'Rasio pesan gagal dalam 24 jam terakhir',
                'weight' => 2.0,
                'max_contribution' => 25.0,
                'thresholds' => json_encode([
                    'low' => 0.05,      // 5% = warning
                    'medium' => 0.10,   // 10% = high risk
                    'high' => 0.20,     // 20% = critical
                ]),
                'applies_to' => json_encode(['sender', 'user', 'campaign']),
            ],
            [
                'code' => 'reject_ratio',
                'name' => 'Rejection Rate',
                'description' => 'Rasio pesan ditolak (invalid number, blocked)',
                'weight' => 2.5,
                'max_contribution' => 30.0,
                'thresholds' => json_encode([
                    'low' => 0.03,      // 3% = warning
                    'medium' => 0.08,   // 8% = high risk
                    'high' => 0.15,     // 15% = critical
                ]),
                'applies_to' => json_encode(['sender', 'user', 'campaign']),
            ],
            [
                'code' => 'bounce_ratio',
                'name' => 'Bounce Rate',
                'description' => 'Rasio nomor tidak terjangkau/tidak valid',
                'weight' => 1.5,
                'max_contribution' => 20.0,
                'thresholds' => json_encode([
                    'low' => 0.10,
                    'medium' => 0.20,
                    'high' => 0.35,
                ]),
                'applies_to' => json_encode(['campaign', 'user']),
            ],

            // ===== BEHAVIOR FACTORS =====
            [
                'code' => 'volume_spike',
                'name' => 'Volume Spike',
                'description' => 'Lonjakan volume dibanding rata-rata 7 hari',
                'weight' => 1.8,
                'max_contribution' => 20.0,
                'thresholds' => json_encode([
                    'low' => 2.0,       // 2x normal = warning
                    'medium' => 5.0,    // 5x normal = high risk
                    'high' => 10.0,     // 10x normal = critical
                ]),
                'applies_to' => json_encode(['sender', 'user']),
            ],
            [
                'code' => 'offhours_ratio',
                'name' => 'Off-Hours Sending',
                'description' => 'Persentase kirim di luar jam wajar (22:00-06:00)',
                'weight' => 1.0,
                'max_contribution' => 10.0,
                'thresholds' => json_encode([
                    'low' => 0.20,      // 20% off-hours
                    'medium' => 0.40,
                    'high' => 0.60,
                ]),
                'applies_to' => json_encode(['user', 'campaign']),
            ],
            [
                'code' => 'template_abuse',
                'name' => 'Template Abuse',
                'description' => 'Menggunakan template tanpa personalisasi (spam pattern)',
                'weight' => 1.5,
                'max_contribution' => 15.0,
                'thresholds' => json_encode([
                    'low' => 0.70,      // 70% same content
                    'medium' => 0.85,
                    'high' => 0.95,
                ]),
                'applies_to' => json_encode(['campaign']),
            ],
            [
                'code' => 'campaign_size_spike',
                'name' => 'Campaign Size Spike',
                'description' => 'Ukuran campaign vs rata-rata user',
                'weight' => 1.2,
                'max_contribution' => 15.0,
                'thresholds' => json_encode([
                    'low' => 3.0,       // 3x average
                    'medium' => 7.0,
                    'high' => 15.0,
                ]),
                'applies_to' => json_encode(['campaign']),
            ],

            // ===== SENDER FACTORS =====
            [
                'code' => 'sender_age',
                'name' => 'Sender Age',
                'description' => 'Umur nomor pengirim (baru = lebih berisiko)',
                'weight' => 1.0,
                'max_contribution' => 15.0,
                'thresholds' => json_encode([
                    'new_days' => 7,        // < 7 hari = high risk
                    'warming_days' => 30,   // < 30 hari = medium
                    'mature_days' => 90,    // > 90 hari = low risk
                ]),
                'applies_to' => json_encode(['sender']),
            ],

            // ===== HISTORY FACTORS =====
            [
                'code' => 'suspension_history',
                'name' => 'Suspension History',
                'description' => 'Riwayat suspend/pause dalam 30 hari',
                'weight' => 2.0,
                'max_contribution' => 25.0,
                'thresholds' => json_encode([
                    'low' => 1,         // 1 suspension
                    'medium' => 2,
                    'high' => 3,
                ]),
                'applies_to' => json_encode(['sender', 'user']),
            ],
            [
                'code' => 'recovery_trend',
                'name' => 'Recovery Trend',
                'description' => 'Apakah risk score membaik atau memburuk',
                'weight' => 0.8,
                'max_contribution' => 10.0,
                'thresholds' => json_encode([
                    'improving_days' => 7,  // 7 hari improving = reduce
                    'stable_days' => 3,
                ]),
                'applies_to' => json_encode(['sender', 'user']),
            ],

            // ===== PLATFORM FACTORS =====
            [
                'code' => 'block_report',
                'name' => 'Block/Report Rate',
                'description' => 'Dilaporkan atau diblock oleh penerima',
                'weight' => 3.0,
                'max_contribution' => 40.0,
                'thresholds' => json_encode([
                    'low' => 0.01,      // 1%
                    'medium' => 0.03,   // 3%
                    'high' => 0.05,     // 5% = very bad
                ]),
                'applies_to' => json_encode(['sender', 'user']),
            ],
        ];

        foreach ($factors as $factor) {
            $factor['is_active'] = true;
            $factor['created_at'] = now();
            $factor['updated_at'] = now();
            DB::table('risk_factors')->insert($factor);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_actions');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('risk_scores');
        Schema::dropIfExists('risk_factors');
    }
};
