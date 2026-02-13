<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WA Risk Events - Individual risk events for audit
     * Tracks each incident that affects health score
     */
    public function up(): void
    {
        if (!Schema::hasTable('wa_risk_events')) {
            Schema::create('wa_risk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_connection_id')->constrained('whatsapp_connections')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained('whatsapp_campaigns')->onDelete('set null');
            
            // Event Type
            $table->enum('event_type', [
                'message_failed',
                'message_blocked',
                'message_reported',
                'template_rejected',
                'burst_violation',
                'cooldown_violation',
                'spam_detected',
                'optin_violation',
                'rate_limit_hit',
                'quality_warning',
                'account_flagged'
            ]);
            
            // Severity
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            
            // Impact on score (negative number)
            $table->tinyInteger('score_impact')->default(0);
            
            // Event details
            $table->string('message_id')->nullable();
            $table->string('template_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->text('description')->nullable();
            $table->json('meta_data')->nullable();
            
            // Source of event
            $table->enum('source', ['webhook', 'cron', 'manual', 'system']);
            
            // Resolution status
            $table->boolean('resolved')->default(false);
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['wa_connection_id', 'event_type']);
            $table->index(['created_at', 'severity']);
            $table->index('campaign_id');
            $table->index('resolved');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_risk_events');
    }
};
