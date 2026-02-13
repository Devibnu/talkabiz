<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Owner Alert System
 * 
 * Table untuk menyimpan semua alert yang dikirim ke owner.
 * 
 * Alert Types:
 * - PROFIT_ALERT: Margin rendah atau cost tinggi
 * - WA_STATUS_ALERT: WhatsApp connection failed/banned
 * - QUOTA_ALERT: Quota klien hampir habis
 * - SECURITY_ALERT: Webhook signature invalid, IP mismatch
 * 
 * Alert Levels:
 * - info: Informational
 * - warning: Needs attention
 * - critical: Immediate action required
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('alert_logs')) {
            Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            
            // Alert identification
            $table->string('type', 50)->index(); // PROFIT_ALERT, WA_STATUS_ALERT, etc.
            $table->string('level', 20)->default('warning'); // info, warning, critical
            $table->string('code', 100)->nullable()->index(); // Unique alert code for deduplication
            
            // Alert content
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable(); // Additional context data
            
            // Related entities (optional)
            $table->unsignedBigInteger('klien_id')->nullable()->index();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            
            // Notification status
            $table->boolean('telegram_sent')->default(false);
            $table->timestamp('telegram_sent_at')->nullable();
            $table->text('telegram_error')->nullable();
            
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->text('email_error')->nullable();
            
            // Read status (for UI)
            $table->boolean('is_read')->default(false);
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamp('read_at')->nullable();
            
            // Acknowledgement (for critical alerts)
            $table->boolean('is_acknowledged')->default(false);
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();
            
            // Deduplication & throttling
            $table->string('fingerprint', 64)->nullable()->index(); // Hash for deduplication
            $table->timestamp('last_occurrence_at')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['type', 'level']);
            $table->index(['created_at', 'is_read']);
            $table->index(['level', 'is_acknowledged']);
        });
}

        // Alert notification settings for owner
        if (!Schema::hasTable('alert_settings')) {
            Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            
            // Owner user
            $table->unsignedBigInteger('user_id')->unique();
            
            // Telegram settings
            $table->boolean('telegram_enabled')->default(true);
            $table->string('telegram_chat_id')->nullable();
            $table->string('telegram_bot_token')->nullable(); // Encrypted
            
            // Email settings
            $table->boolean('email_enabled')->default(true);
            $table->string('email_address')->nullable();
            $table->boolean('email_digest_enabled')->default(true);
            $table->string('email_digest_frequency')->default('daily'); // hourly, daily, weekly
            
            // Alert type preferences (JSON)
            $table->json('enabled_types')->nullable(); // ['PROFIT_ALERT', 'WA_STATUS_ALERT', ...]
            $table->json('level_preferences')->nullable(); // { 'critical': ['telegram', 'email'], ... }
            
            // Throttling settings
            $table->unsignedInteger('throttle_minutes')->default(15); // Min interval between same alerts
            $table->boolean('batch_notifications')->default(false);
            
            // Quiet hours
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
}

        // Alert thresholds configuration
        if (!Schema::hasTable('alert_thresholds')) {
            Schema::create('alert_thresholds', function (Blueprint $table) {
            $table->id();
            
            $table->string('alert_type', 50);
            $table->string('metric', 100);
            $table->string('operator', 10); // <, <=, >, >=, ==, !=
            $table->decimal('threshold_value', 20, 4);
            $table->string('level', 20)->default('warning'); // Level to trigger
            
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            
            $table->timestamps();
            
            $table->unique(['alert_type', 'metric', 'level']);
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_thresholds');
        Schema::dropIfExists('alert_settings');
        Schema::dropIfExists('alert_logs');
    }
};
