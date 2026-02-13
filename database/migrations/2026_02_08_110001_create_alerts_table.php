<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * KONSEP:
     * 1. Alert adalah early warning preventif
     * 2. Deduplicated dengan cooldown mechanism
     * 3. Support multiple channels (in-app, email)
     * 4. Comprehensive logging untuk audit
     */
    public function up(): void
    {
        if (!Schema::hasTable('alerts')) {
            Schema::create('alerts', function (Blueprint $table) {
            // Primary identifier
            $table->id();
            
            // User context (nullable untuk owner/system alerts)
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnDelete()
                  ->comment('Target user, null untuk owner/system alerts');
            
            // Alert classification
            $table->string('alert_type', 50)
                  ->comment('balance_low, balance_zero, cost_spike, failure_rate_high, etc.');
                  
            $table->enum('severity', ['info', 'warning', 'critical'])
                  ->default('warning')
                  ->comment('Alert severity level');
                  
            $table->enum('audience', ['user', 'owner', 'system'])
                  ->default('user')
                  ->comment('Target audience untuk alert');
            
            // Threshold & measurement data
            $table->decimal('threshold_value', 15, 2)
                  ->nullable()
                  ->comment('Threshold yang memicu alert (balance, percentage, etc.)');
                  
            $table->decimal('actual_value', 15, 2)
                  ->nullable()
                  ->comment('Nilai aktual saat alert dipicu');
                  
            $table->string('measurement_unit', 20)
                  ->nullable()
                  ->comment('Unit pengukuran (IDR, percentage, count)');
            
            // Alert content
            $table->string('title', 255)
                  ->comment('Alert title untuk display');
                  
            $table->text('message')
                  ->comment('Alert message content');
                  
            $table->json('action_buttons')
                  ->nullable()
                  ->comment('CTA buttons: topup, view report, etc.');
            
            // Rich metadata
            $table->json('metadata')
                  ->nullable()
                  ->comment('Additional context data (campaign_id, period, etc.)');
                  
            $table->json('context')
                  ->nullable()
                  ->comment('System context saat alert triggered');
            
            // Delivery channels
            $table->json('channels')
                  ->comment('Delivery channels: [in_app, email, etc.]');
                  
            $table->json('delivery_status')
                  ->nullable()
                  ->comment('Status delivery per channel');
            
            // Alert lifecycle & status
            $table->enum('status', ['triggered', 'delivered', 'acknowledged', 'resolved', 'expired'])
                  ->default('triggered')
                  ->comment('Alert lifecycle status');
            
            $table->timestamp('triggered_at')
                  ->comment('Waktu alert pertama kali dipicu');
                  
            $table->timestamp('cooldown_until')
                  ->nullable()
                  ->comment('Prevent duplicate alert sampai waktu ini');
                  
            $table->timestamp('acknowledged_at')
                  ->nullable()
                  ->comment('Waktu user/admin acknowledge alert');
                  
            $table->foreignId('acknowledged_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('User yang acknowledge alert');
                  
            $table->timestamp('resolved_at')
                  ->nullable()
                  ->comment('Waktu kondisi alert sudah teratasi');
                  
            $table->timestamp('expires_at')
                  ->nullable()
                  ->comment('Alert expiry untuk auto-cleanup');
            
            // Audit tracking
            $table->string('triggered_by', 100)
                  ->nullable()
                  ->comment('System/job/event yang trigger alert');
                  
            $table->ipAddress('triggered_ip')
                  ->nullable()
                  ->comment('IP address saat alert dipicu');
            
            // Standard timestamps
            $table->timestamps();
            
            // ==================== INDEXES ====================
            
            // Primary lookup indexes
            $table->index(['user_id', 'status'], 'idx_alerts_user_status');
            $table->index(['alert_type', 'triggered_at'], 'idx_alerts_type_time');
            $table->index(['audience', 'severity'], 'idx_alerts_audience_severity');
            
            // Cooldown & duplicate prevention
            $table->unique(['user_id', 'alert_type', 'triggered_at'], 'unq_alerts_user_type_time');
            $table->index(['cooldown_until'], 'idx_alerts_cooldown');
            
            // Performance indexes untuk dashboard
            $table->index(['status', 'triggered_at'], 'idx_alerts_status_time');
            $table->index(['severity', 'status'], 'idx_alerts_severity_status');
            
            // Cleanup indexes
            $table->index(['expires_at'], 'idx_alerts_expires');
            $table->index(['created_at'], 'idx_alerts_created');
        });
}
        
        // Create alert_summaries table untuk quick dashboard access
        if (!Schema::hasTable('alert_summaries')) {
            Schema::create('alert_summaries', function (Blueprint $table) {
            $table->id();
            
            // Summary period
            $table->date('summary_date')
                  ->comment('Tanggal summary (daily aggregation)');
                  
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnDelete()
                  ->comment('User context, null untuk system summary');
            
            // Alert counts by type
            $table->unsignedInteger('balance_low_count')->default(0);
            $table->unsignedInteger('balance_zero_count')->default(0);
            $table->unsignedInteger('cost_spike_count')->default(0);
            $table->unsignedInteger('failure_rate_high_count')->default(0);
            $table->unsignedInteger('other_alerts_count')->default(0);
            
            // Totals
            $table->unsignedInteger('total_alerts')->default(0);
            $table->unsignedInteger('critical_alerts')->default(0);
            $table->unsignedInteger('acknowledged_alerts')->default(0);
            $table->unsignedInteger('resolved_alerts')->default(0);
            
            // Performance metrics
            $table->decimal('avg_acknowledgment_time_minutes', 8, 2)
                  ->nullable()
                  ->comment('Average time to acknowledge alerts');
                  
            $table->decimal('avg_resolution_time_minutes', 8, 2)
                  ->nullable()
                  ->comment('Average time to resolve alerts');
            
            $table->timestamps();
            
            // Indexes untuk summary table
            $table->unique(['summary_date', 'user_id'], 'unq_alert_summary_date_user');
            $table->index(['summary_date'], 'idx_alert_summary_date');
            $table->index(['user_id', 'summary_date'], 'idx_alert_summary_user_date');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_summaries');
        Schema::dropIfExists('alerts');
    }
};