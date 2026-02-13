<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * EXECUTIVE RISK DASHBOARD
     * 
     * Dashboard eksekutif untuk owner/C-level yang NON-TEKNIS
     * Fokus: Keputusan bisnis, bukan detail teknis
     * 
     * Tables:
     * 1. executive_health_snapshots - Skor kesehatan harian
     * 2. business_risk_alerts - Risiko bisnis aktif
     * 3. platform_status_summaries - Status platform (simple)
     * 4. revenue_risk_metrics - Risiko revenue & customer
     * 5. executive_recommendations - Rekomendasi otomatis
     * 6. executive_dashboard_access_logs - Audit akses
     * 7. health_score_components - Komponen skor kesehatan
     * 8. risk_thresholds - Threshold konfigurasi
     */
    public function up(): void
    {
        // =====================================================
        // 1. EXECUTIVE HEALTH SNAPSHOTS
        // Skor kesehatan bisnis 0-100, snapshot per jam/hari
        // =====================================================
        Schema::create('executive_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('snapshot_id')->unique();
            
            // Overall Score
            $table->decimal('health_score', 5, 2); // 0.00 - 100.00
            $table->enum('health_status', ['healthy', 'watch', 'risk', 'critical']);
            $table->string('health_emoji', 10)->default('🟢'); // Visual indicator
            
            // Component Scores (0-100 each)
            $table->decimal('deliverability_score', 5, 2)->default(100);
            $table->decimal('error_budget_score', 5, 2)->default(100);
            $table->decimal('risk_abuse_score', 5, 2)->default(100);
            $table->decimal('incident_score', 5, 2)->default(100);
            $table->decimal('payment_score', 5, 2)->default(100);
            
            // Weights (configurable)
            $table->json('score_weights')->nullable(); // {"deliverability": 25, "error_budget": 20, ...}
            
            // Trend
            $table->decimal('score_change_24h', 5, 2)->default(0); // +5.2 or -3.1
            $table->enum('trend_direction', ['up', 'down', 'stable'])->default('stable');
            
            // Context
            $table->text('executive_summary')->nullable(); // 1-2 kalimat ringkas
            $table->json('key_factors')->nullable(); // Faktor utama yang mempengaruhi
            
            // Timing
            $table->enum('snapshot_type', ['hourly', 'daily', 'weekly', 'manual']);
            $table->date('snapshot_date');
            $table->time('snapshot_time')->nullable();
            
            $table->timestamps();
            
            $table->index(['snapshot_date', 'snapshot_type']);
            $table->index('health_status');
        });

        // =====================================================
        // 2. BUSINESS RISK ALERTS
        // Risiko bisnis aktif, bukan teknis
        // =====================================================
        Schema::create('business_risk_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('alert_id')->unique();
            
            // Risk Identity
            $table->string('risk_code', 50); // BAN_RISK, DELIVERY_DROP, REVENUE_DECLINE
            $table->string('risk_title'); // "Risiko BAN Meningkat"
            $table->text('risk_description'); // Penjelasan non-teknis
            
            // Business Impact
            $table->enum('business_impact', ['low', 'medium', 'high', 'critical']);
            $table->string('impact_emoji', 10)->default('⚠️');
            $table->text('potential_loss')->nullable(); // "Potensi kehilangan Rp 50jt/hari"
            
            // Affected Area
            $table->enum('affected_area', [
                'revenue',
                'customers', 
                'reputation',
                'operations',
                'compliance',
                'all'
            ]);
            $table->integer('affected_customers_count')->default(0);
            $table->decimal('affected_revenue_percent', 5, 2)->default(0);
            
            // Trend
            $table->enum('trend', ['improving', 'stable', 'worsening']);
            $table->string('trend_emoji', 10)->default('→');
            $table->decimal('change_percent', 5, 2)->default(0); // +20% or -15%
            
            // Recommendation
            $table->text('recommended_action'); // "Kurangi volume campaign 50%"
            $table->enum('action_urgency', ['immediate', 'today', 'this_week', 'monitor']);
            $table->string('action_owner')->nullable(); // "Marketing" / "Operations"
            
            // Status
            $table->enum('alert_status', ['active', 'acknowledged', 'mitigated', 'resolved', 'expired']);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('mitigation_notes')->nullable();
            
            // Source & Confidence
            $table->string('data_source')->nullable(); // System yang mendeteksi
            $table->decimal('confidence_score', 3, 2)->default(1.00); // 0.00 - 1.00
            
            // Timing
            $table->timestamp('detected_at');
            $table->timestamp('expires_at')->nullable();
            $table->integer('priority_order')->default(99);
            
            $table->timestamps();
            
            $table->index(['alert_status', 'priority_order']);
            $table->index(['business_impact', 'alert_status']);
            $table->index('detected_at');
        });

        // =====================================================
        // 3. PLATFORM STATUS SUMMARIES
        // Status platform SIMPLE (🟢🟡🔴), bukan metrik teknis
        // =====================================================
        Schema::create('platform_status_summaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('summary_id')->unique();
            
            // Component
            $table->string('component_name'); // Messaging, Billing, WhatsApp API
            $table->string('component_label'); // Label untuk display
            $table->string('component_icon', 20)->nullable(); // 📨 💳 📱
            
            // Simple Status
            $table->enum('status', ['operational', 'degraded', 'partial_outage', 'major_outage']);
            $table->string('status_emoji', 10)->default('🟢');
            $table->string('status_label', 50); // "Beroperasi Normal", "Gangguan Ringan"
            
            // Impact Description (non-technical)
            $table->text('impact_description')->nullable(); // "Pengiriman pesan sedikit lebih lambat"
            $table->text('customer_message')->nullable(); // Pesan untuk customer jika ditanya
            
            // Metrics (simplified, percentage based)
            $table->decimal('uptime_today', 5, 2)->default(100); // 99.5%
            $table->decimal('success_rate', 5, 2)->default(100); // 98.2%
            $table->integer('avg_response_seconds')->nullable(); // Human readable
            
            // Last Incident
            $table->timestamp('last_incident_at')->nullable();
            $table->string('last_incident_summary')->nullable();
            
            // Update Info
            $table->timestamp('last_checked_at');
            $table->integer('check_interval_minutes')->default(5);
            
            $table->timestamps();
            
            $table->index('component_name');
            $table->index('status');
        });

        // =====================================================
        // 4. REVENUE RISK METRICS
        // Metrik risiko revenue & customer
        // =====================================================
        Schema::create('revenue_risk_metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('metric_id')->unique();
            
            // Period
            $table->date('metric_date');
            $table->enum('period_type', ['hourly', 'daily', 'weekly', 'monthly']);
            
            // Active Users
            $table->integer('total_active_users')->default(0);
            $table->integer('paying_users')->default(0);
            $table->integer('new_users_today')->default(0);
            $table->integer('churned_users_today')->default(0);
            
            // Revenue
            $table->decimal('revenue_today', 15, 2)->default(0);
            $table->decimal('revenue_mtd', 15, 2)->default(0);
            $table->decimal('revenue_target_mtd', 15, 2)->default(0);
            $table->decimal('revenue_achievement_percent', 5, 2)->default(0);
            
            // Revenue Trend
            $table->decimal('revenue_change_percent', 5, 2)->default(0);
            $table->enum('revenue_trend', ['growing', 'stable', 'declining']);
            $table->string('revenue_trend_emoji', 10)->default('→');
            
            // At Risk
            $table->integer('users_impacted_by_issues')->default(0);
            $table->integer('corporate_accounts_at_risk')->default(0);
            $table->decimal('revenue_at_risk', 15, 2)->default(0);
            $table->text('at_risk_reasons')->nullable();
            
            // Disputes & Refunds
            $table->integer('refund_requests_today')->default(0);
            $table->decimal('refund_amount_today', 15, 2)->default(0);
            $table->integer('disputes_today')->default(0);
            $table->integer('complaints_today')->default(0);
            
            // Payment Health
            $table->decimal('payment_success_rate', 5, 2)->default(100);
            $table->integer('failed_payments_today')->default(0);
            $table->decimal('failed_payment_amount', 15, 2)->default(0);
            
            // Customer Satisfaction Signal
            $table->decimal('support_ticket_volume_change', 5, 2)->default(0); // +50% lebih banyak dari biasa
            $table->enum('customer_sentiment', ['positive', 'neutral', 'negative', 'unknown'])->default('neutral');
            
            $table->timestamps();
            
            $table->unique(['metric_date', 'period_type']);
            $table->index('metric_date');
        });

        // =====================================================
        // 5. EXECUTIVE RECOMMENDATIONS
        // Rekomendasi otomatis berbasis data
        // =====================================================
        Schema::create('executive_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid('recommendation_id')->unique();
            
            // Recommendation
            $table->string('title'); // "Aman Scale Campaign"
            $table->text('description'); // Penjelasan lengkap
            $table->string('emoji', 10)->default('💡');
            
            // Category
            $table->enum('category', [
                'scaling',      // Scale up/down
                'campaign',     // Campaign decisions
                'pricing',      // Pricing & promo
                'risk',         // Risk mitigation
                'customer',     // Customer communication
                'operational',  // Operational changes
                'strategic'     // Strategic decisions
            ]);
            
            // Type
            $table->enum('recommendation_type', [
                'go',           // Aman untuk lakukan
                'caution',      // Hati-hati, pertimbangkan
                'hold',         // Tahan dulu
                'stop',         // Jangan lakukan
                'action'        // Perlu aksi segera
            ]);
            
            // Confidence & Basis
            $table->decimal('confidence_score', 3, 2)->default(0.80);
            $table->json('based_on')->nullable(); // Data yang mendasari
            $table->text('reasoning'); // Mengapa rekomendasi ini
            
            // Urgency
            $table->enum('urgency', ['fyi', 'consider', 'important', 'critical']);
            $table->timestamp('valid_until')->nullable();
            
            // Action
            $table->text('suggested_action')->nullable(); // Langkah konkret
            $table->string('action_owner')->nullable(); // Siapa yang harus action
            
            // Status
            $table->enum('status', ['active', 'acknowledged', 'acted', 'expired', 'dismissed']);
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->text('action_notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'urgency']);
            $table->index(['category', 'status']);
        });

        // =====================================================
        // 6. EXECUTIVE DASHBOARD ACCESS LOGS
        // Audit siapa akses dashboard
        // =====================================================
        Schema::create('executive_dashboard_access_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('access_id')->unique();
            
            // User
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('user_name');
            $table->string('user_role');
            
            // Access Details
            $table->enum('access_type', ['view', 'export', 'share', 'acknowledge', 'action']);
            $table->string('accessed_section')->nullable(); // health_score, risks, recommendations
            $table->json('accessed_data')->nullable(); // What data was viewed/exported
            
            // Context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_type', 50)->nullable(); // mobile, desktop, tablet
            
            // Session
            $table->string('session_id')->nullable();
            $table->integer('session_duration_seconds')->nullable();
            
            $table->timestamp('accessed_at');
            $table->timestamps();
            
            $table->index(['user_id', 'accessed_at']);
            $table->index('access_type');
        });

        // =====================================================
        // 7. HEALTH SCORE COMPONENTS
        // Konfigurasi komponen skor
        // =====================================================
        Schema::create('health_score_components', function (Blueprint $table) {
            $table->id();
            $table->string('component_key', 50)->unique();
            $table->string('component_name');
            $table->text('description');
            
            // Weight & Calculation
            $table->integer('weight')->default(20); // Percentage weight
            $table->enum('calculation_method', ['percentage', 'threshold', 'inverse', 'custom']);
            
            // Thresholds
            $table->decimal('healthy_threshold', 5, 2)->default(80);
            $table->decimal('watch_threshold', 5, 2)->default(60);
            $table->decimal('risk_threshold', 5, 2)->default(40);
            
            // Data Source
            $table->string('data_source'); // Table/API to pull from
            $table->string('data_field'); // Field to use
            $table->json('data_filters')->nullable();
            
            // Display
            $table->string('display_label');
            $table->string('display_emoji', 10)->nullable();
            $table->boolean('show_in_dashboard')->default(true);
            $table->integer('display_order')->default(0);
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // =====================================================
        // 8. RISK THRESHOLDS CONFIGURATION
        // Threshold untuk trigger alert
        // =====================================================
        Schema::create('risk_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('threshold_key', 50)->unique();
            $table->string('threshold_name');
            $table->text('description');
            
            // Metric
            $table->string('metric_source'); // Dari mana data
            $table->string('metric_field');
            $table->enum('comparison', ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'change_gt', 'change_lt']);
            
            // Values
            $table->decimal('warning_value', 15, 4);
            $table->decimal('critical_value', 15, 4);
            
            // Alert Config
            $table->string('alert_risk_code', 50);
            $table->string('alert_title_template'); // "Delivery Rate turun ke {value}%"
            $table->text('alert_description_template');
            $table->string('recommended_action_template');
            
            // Notification
            $table->boolean('notify_on_warning')->default(false);
            $table->boolean('notify_on_critical')->default(true);
            $table->json('notification_channels')->nullable(); // ["email", "slack", "sms"]
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // =====================================================
        // SEED DEFAULT DATA
        // =====================================================
        $this->seedDefaultData();
    }

    private function seedDefaultData(): void
    {
        // Health Score Components
        DB::table('health_score_components')->insert([
            [
                'component_key' => 'deliverability',
                'component_name' => 'Deliverability',
                'description' => 'Tingkat keberhasilan pengiriman pesan WhatsApp',
                'weight' => 25,
                'calculation_method' => 'percentage',
                'healthy_threshold' => 95,
                'watch_threshold' => 85,
                'risk_threshold' => 70,
                'data_source' => 'delivery_analytics',
                'data_field' => 'delivery_success_rate',
                'display_label' => 'Pengiriman Pesan',
                'display_emoji' => '📨',
                'display_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'component_key' => 'error_budget',
                'component_name' => 'Error Budget',
                'description' => 'Sisa budget error yang tersedia (reliability)',
                'weight' => 20,
                'calculation_method' => 'percentage',
                'healthy_threshold' => 50,
                'watch_threshold' => 25,
                'risk_threshold' => 10,
                'data_source' => 'error_budget_snapshots',
                'data_field' => 'remaining_budget_percent',
                'display_label' => 'Stabilitas Sistem',
                'display_emoji' => '⚡',
                'display_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'component_key' => 'risk_abuse',
                'component_name' => 'Risk & Abuse',
                'description' => 'Tingkat risiko ban dan abuse di platform',
                'weight' => 25,
                'calculation_method' => 'inverse',
                'healthy_threshold' => 20,
                'watch_threshold' => 40,
                'risk_threshold' => 60,
                'data_source' => 'tenant_risk_scores',
                'data_field' => 'avg_risk_score',
                'display_label' => 'Keamanan Platform',
                'display_emoji' => '🛡️',
                'display_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'component_key' => 'incident',
                'component_name' => 'Incident Status',
                'description' => 'Status incident dan outage',
                'weight' => 15,
                'calculation_method' => 'threshold',
                'healthy_threshold' => 0,
                'watch_threshold' => 1,
                'risk_threshold' => 2,
                'data_source' => 'incidents',
                'data_field' => 'active_critical_count',
                'display_label' => 'Status Operasional',
                'display_emoji' => '🚨',
                'display_order' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'component_key' => 'payment',
                'component_name' => 'Payment Health',
                'description' => 'Kesehatan sistem pembayaran',
                'weight' => 15,
                'calculation_method' => 'percentage',
                'healthy_threshold' => 98,
                'watch_threshold' => 95,
                'risk_threshold' => 90,
                'data_source' => 'payment_analytics',
                'data_field' => 'success_rate',
                'display_label' => 'Pembayaran',
                'display_emoji' => '💳',
                'display_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Platform Status Summaries (Initial)
        $now = now();
        DB::table('platform_status_summaries')->insert([
            [
                'summary_id' => (string) \Illuminate\Support\Str::uuid(),
                'component_name' => 'messaging',
                'component_label' => 'Pengiriman Pesan',
                'component_icon' => '📨',
                'status' => 'operational',
                'status_emoji' => '🟢',
                'status_label' => 'Beroperasi Normal',
                'impact_description' => 'Semua pesan terkirim dengan baik',
                'customer_message' => 'Layanan pengiriman pesan berjalan normal.',
                'uptime_today' => 100,
                'success_rate' => 99.5,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'summary_id' => (string) \Illuminate\Support\Str::uuid(),
                'component_name' => 'billing',
                'component_label' => 'Pembayaran & Billing',
                'component_icon' => '💳',
                'status' => 'operational',
                'status_emoji' => '🟢',
                'status_label' => 'Beroperasi Normal',
                'impact_description' => 'Semua transaksi diproses normal',
                'customer_message' => 'Sistem pembayaran berjalan normal.',
                'uptime_today' => 100,
                'success_rate' => 99.8,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'summary_id' => (string) \Illuminate\Support\Str::uuid(),
                'component_name' => 'whatsapp_api',
                'component_label' => 'WhatsApp API',
                'component_icon' => '📱',
                'status' => 'operational',
                'status_emoji' => '🟢',
                'status_label' => 'Beroperasi Normal',
                'impact_description' => 'Koneksi ke WhatsApp stabil',
                'customer_message' => 'Koneksi WhatsApp berjalan normal.',
                'uptime_today' => 100,
                'success_rate' => 99.9,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'summary_id' => (string) \Illuminate\Support\Str::uuid(),
                'component_name' => 'dashboard',
                'component_label' => 'Dashboard & Portal',
                'component_icon' => '🖥️',
                'status' => 'operational',
                'status_emoji' => '🟢',
                'status_label' => 'Beroperasi Normal',
                'impact_description' => 'Dashboard dapat diakses dengan baik',
                'customer_message' => 'Dashboard tersedia dan responsif.',
                'uptime_today' => 100,
                'success_rate' => 100,
                'last_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Risk Thresholds
        DB::table('risk_thresholds')->insert([
            [
                'threshold_key' => 'delivery_rate_drop',
                'threshold_name' => 'Delivery Rate Drop',
                'description' => 'Alert ketika delivery rate turun signifikan',
                'metric_source' => 'delivery_analytics',
                'metric_field' => 'delivery_success_rate',
                'comparison' => 'lt',
                'warning_value' => 90,
                'critical_value' => 80,
                'alert_risk_code' => 'DELIVERY_DROP',
                'alert_title_template' => 'Delivery Rate Menurun ke {value}%',
                'alert_description_template' => 'Tingkat keberhasilan pengiriman pesan turun ke {value}%, di bawah target normal 95%. Ini dapat mempengaruhi kepuasan pelanggan.',
                'recommended_action_template' => 'Monitor campaign aktif dan pertimbangkan menurunkan volume sementara.',
                'notify_on_warning' => true,
                'notify_on_critical' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'threshold_key' => 'ban_risk_increase',
                'threshold_name' => 'Ban Risk Increase',
                'description' => 'Alert ketika risiko ban meningkat',
                'metric_source' => 'tenant_risk_scores',
                'metric_field' => 'high_risk_count',
                'comparison' => 'gt',
                'warning_value' => 5,
                'critical_value' => 10,
                'alert_risk_code' => 'BAN_RISK',
                'alert_title_template' => 'Risiko BAN Meningkat ({value} tenant high-risk)',
                'alert_description_template' => 'Ada {value} tenant dengan risiko tinggi yang dapat mempengaruhi reputasi platform dan menyebabkan pembatasan dari WhatsApp.',
                'recommended_action_template' => 'Review tenant high-risk dan pertimbangkan pembatasan campaign.',
                'notify_on_warning' => true,
                'notify_on_critical' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'threshold_key' => 'payment_failure_spike',
                'threshold_name' => 'Payment Failure Spike',
                'description' => 'Alert ketika kegagalan pembayaran meningkat',
                'metric_source' => 'payment_analytics',
                'metric_field' => 'failure_rate',
                'comparison' => 'gt',
                'warning_value' => 5,
                'critical_value' => 10,
                'alert_risk_code' => 'PAYMENT_FAIL',
                'alert_title_template' => 'Kegagalan Pembayaran Meningkat ({value}%)',
                'alert_description_template' => 'Tingkat kegagalan pembayaran naik ke {value}%, yang dapat mempengaruhi revenue dan pengalaman pelanggan.',
                'recommended_action_template' => 'Cek status payment gateway dan hubungi provider jika perlu.',
                'notify_on_warning' => false,
                'notify_on_critical' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'threshold_key' => 'queue_backlog',
                'threshold_name' => 'Queue Backlog',
                'description' => 'Alert ketika antrian pesan menumpuk',
                'metric_source' => 'queue_metrics',
                'metric_field' => 'pending_messages',
                'comparison' => 'gt',
                'warning_value' => 10000,
                'critical_value' => 50000,
                'alert_risk_code' => 'QUEUE_BACKLOG',
                'alert_title_template' => 'Antrian Pesan Menumpuk ({value} pesan)',
                'alert_description_template' => 'Ada {value} pesan dalam antrian yang menunggu dikirim. Ini dapat menyebabkan keterlambatan pengiriman.',
                'recommended_action_template' => 'Pertimbangkan menunda campaign baru sampai antrian normal.',
                'notify_on_warning' => true,
                'notify_on_critical' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'threshold_key' => 'error_budget_low',
                'threshold_name' => 'Error Budget Low',
                'description' => 'Alert ketika error budget hampir habis',
                'metric_source' => 'error_budget_snapshots',
                'metric_field' => 'remaining_budget_percent',
                'comparison' => 'lt',
                'warning_value' => 25,
                'critical_value' => 10,
                'alert_risk_code' => 'ERROR_BUDGET_LOW',
                'alert_title_template' => 'Error Budget Tinggal {value}%',
                'alert_description_template' => 'Budget error tinggal {value}%. Jika habis, mungkin perlu freeze perubahan sistem untuk menjaga stabilitas.',
                'recommended_action_template' => 'Review deployment dan pertimbangkan freeze fitur baru.',
                'notify_on_warning' => true,
                'notify_on_critical' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Initial Health Snapshot
        DB::table('executive_health_snapshots')->insert([
            'snapshot_id' => (string) \Illuminate\Support\Str::uuid(),
            'health_score' => 92.5,
            'health_status' => 'healthy',
            'health_emoji' => '🟢',
            'deliverability_score' => 95,
            'error_budget_score' => 85,
            'risk_abuse_score' => 90,
            'incident_score' => 100,
            'payment_score' => 98,
            'score_weights' => json_encode([
                'deliverability' => 25,
                'error_budget' => 20,
                'risk_abuse' => 25,
                'incident' => 15,
                'payment' => 15,
            ]),
            'score_change_24h' => 0,
            'trend_direction' => 'stable',
            'executive_summary' => 'Platform dalam kondisi sehat. Semua sistem beroperasi normal tanpa risiko signifikan.',
            'key_factors' => json_encode([
                'Delivery rate stabil di 95%+',
                'Tidak ada incident aktif',
                'Payment success rate tinggi',
            ]),
            'snapshot_type' => 'daily',
            'snapshot_date' => now()->toDateString(),
            'snapshot_time' => now()->toTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sample Recommendation
        DB::table('executive_recommendations')->insert([
            'recommendation_id' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Aman Scale Campaign',
            'description' => 'Berdasarkan analisis 24 jam terakhir, platform dalam kondisi optimal untuk menjalankan campaign besar.',
            'emoji' => '✅',
            'category' => 'scaling',
            'recommendation_type' => 'go',
            'confidence_score' => 0.92,
            'based_on' => json_encode([
                'Health score: 92.5 (HEALTHY)',
                'Delivery rate: 95%+',
                'No active incidents',
                'Error budget: 85% remaining',
            ]),
            'reasoning' => 'Semua indikator menunjukkan platform siap menerima beban tinggi. Delivery stabil, tidak ada incident, dan error budget masih aman.',
            'urgency' => 'fyi',
            'suggested_action' => 'Lanjutkan campaign yang direncanakan. Pertimbangkan scale up jika ada opportunity.',
            'action_owner' => 'Marketing',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_thresholds');
        Schema::dropIfExists('health_score_components');
        Schema::dropIfExists('executive_dashboard_access_logs');
        Schema::dropIfExists('executive_recommendations');
        Schema::dropIfExists('revenue_risk_metrics');
        Schema::dropIfExists('platform_status_summaries');
        Schema::dropIfExists('business_risk_alerts');
        Schema::dropIfExists('executive_health_snapshots');
    }
};
