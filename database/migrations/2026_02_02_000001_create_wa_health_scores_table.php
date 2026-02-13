<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WA Health Scores - Main table for tracking WhatsApp number health
     * Part of STEP 8: Deliverability Health Score System
     */
    public function up(): void
    {
        if (!Schema::hasTable('wa_health_scores')) {
            Schema::create('wa_health_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_connection_id')->constrained('whatsapp_connections')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Health Score (0-100)
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->enum('health_grade', ['A', 'B', 'C', 'D'])->default('A');
            
            // Individual Parameter Scores (0-100 each)
            $table->unsignedTinyInteger('delivery_rate_score')->default(100);
            $table->unsignedTinyInteger('block_report_score')->default(100);
            $table->unsignedTinyInteger('template_rejection_score')->default(100);
            $table->unsignedTinyInteger('burst_sending_score')->default(100);
            $table->unsignedTinyInteger('optin_compliance_score')->default(100);
            $table->unsignedTinyInteger('failed_message_score')->default(100);
            $table->unsignedTinyInteger('spam_keyword_score')->default(100);
            $table->unsignedTinyInteger('cooldown_violation_score')->default(100);
            
            // Raw Metrics (for calculation)
            $table->unsignedInteger('total_sent_7d')->default(0);
            $table->unsignedInteger('total_delivered_7d')->default(0);
            $table->unsignedInteger('total_failed_7d')->default(0);
            $table->unsignedInteger('total_blocked_7d')->default(0);
            $table->unsignedInteger('total_reported_7d')->default(0);
            $table->unsignedInteger('templates_rejected_7d')->default(0);
            $table->unsignedInteger('burst_violations_7d')->default(0);
            $table->unsignedInteger('cooldown_violations_7d')->default(0);
            $table->unsignedInteger('spam_flags_7d')->default(0);
            
            // 30-day metrics for trend analysis
            $table->unsignedInteger('total_sent_30d')->default(0);
            $table->unsignedInteger('total_delivered_30d')->default(0);
            $table->unsignedInteger('total_failed_30d')->default(0);
            $table->unsignedInteger('total_blocked_30d')->default(0);
            
            // Status & Restrictions
            $table->enum('status', ['active', 'restricted', 'cooldown', 'suspended'])->default('active');
            $table->unsignedSmallInteger('max_messages_per_minute')->default(60);
            $table->boolean('blast_enabled')->default(true);
            $table->boolean('campaign_enabled')->default(true);
            
            // Top Risk Factors (JSON array of top 2-3 reasons)
            $table->json('risk_factors')->nullable();
            
            // Cooldown tracking
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('last_calculated_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['health_grade', 'status']);
            $table->index('health_score');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_health_scores');
    }
};
