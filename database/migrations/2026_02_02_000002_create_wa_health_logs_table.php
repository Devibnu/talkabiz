<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WA Health Logs - Historical daily snapshots of health scores
     * For trend analysis and audit trail
     */
    public function up(): void
    {
        if (!Schema::hasTable('wa_health_logs')) {
            Schema::create('wa_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_connection_id')->constrained('whatsapp_connections')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Snapshot date
            $table->date('log_date');
            
            // Health Score Snapshot
            $table->unsignedTinyInteger('health_score');
            $table->enum('health_grade', ['A', 'B', 'C', 'D']);
            
            // Parameter Scores Snapshot
            $table->unsignedTinyInteger('delivery_rate_score');
            $table->unsignedTinyInteger('block_report_score');
            $table->unsignedTinyInteger('template_rejection_score');
            $table->unsignedTinyInteger('burst_sending_score');
            $table->unsignedTinyInteger('optin_compliance_score');
            $table->unsignedTinyInteger('failed_message_score');
            $table->unsignedTinyInteger('spam_keyword_score');
            $table->unsignedTinyInteger('cooldown_violation_score');
            
            // Daily Metrics
            $table->unsignedInteger('messages_sent')->default(0);
            $table->unsignedInteger('messages_delivered')->default(0);
            $table->unsignedInteger('messages_failed')->default(0);
            $table->unsignedInteger('messages_blocked')->default(0);
            $table->unsignedInteger('messages_reported')->default(0);
            
            // Status at time of log
            $table->enum('status', ['active', 'restricted', 'cooldown', 'suspended']);
            
            // Risk factors at time of log
            $table->json('risk_factors')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['wa_connection_id', 'log_date']);
            $table->index(['log_date', 'health_grade']);
            $table->index('user_id');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_health_logs');
    }
};
