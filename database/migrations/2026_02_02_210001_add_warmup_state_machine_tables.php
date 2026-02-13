<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 9: Auto Warm-up State Machine Enhancement
     * 
     * Adds state machine fields and Health Score integration to warmup system.
     * 
     * WARMUP STATES:
     * ==============
     * - NEW      : Hari 1-3, max 30 msg/day, utility only
     * - WARMING  : Hari 4-7, max 80 msg/day, marketing 20%
     * - STABLE   : Health A, full limits, all templates
     * - COOLDOWN : Health C, blocked 24-72h, inbox only
     * - SUSPENDED: Health D, blast disabled, owner alert
     * 
     * RULES:
     * ======
     * - Transisi otomatis berdasarkan Health Score & umur
     * - Client TIDAK bisa override limit
     * - Semua perubahan di-audit
     */
    public function up(): void
    {
        // ========== ENHANCE WHATSAPP_WARMUPS TABLE ==========
        if (Schema::hasTable('whatsapp_warmups') && !Schema::hasColumn('whatsapp_warmups', 'warmup_state')) {
        Schema::table('whatsapp_warmups', function (Blueprint $table) {
            // State Machine
            $table->enum('warmup_state', ['NEW', 'WARMING', 'STABLE', 'COOLDOWN', 'SUSPENDED'])
                ->default('NEW')
                ->after('status')
                ->comment('Current warmup state machine position');
            
            $table->string('previous_state')->nullable()
                ->after('warmup_state')
                ->comment('Previous state for audit trail');
            
            $table->timestamp('state_changed_at')->nullable()
                ->after('previous_state')
                ->comment('When state last changed');
            
            // Connection Age Tracking
            $table->date('number_activated_at')->nullable()
                ->after('state_changed_at')
                ->comment('Date when number was first connected to Meta');
            
            $table->unsignedInteger('number_age_days')->default(0)
                ->after('number_activated_at')
                ->comment('How many days since activation');
            
            // Current Limits (Dynamic)
            $table->unsignedInteger('current_daily_limit')->default(20)
                ->after('number_age_days')
                ->comment('Current active daily limit');
            
            $table->unsignedInteger('current_hourly_limit')->default(5)
                ->after('current_daily_limit')
                ->comment('Current active hourly limit');
            
            $table->unsignedInteger('current_burst_limit')->default(3)
                ->after('current_hourly_limit')
                ->comment('Max messages in quick succession');
            
            // Hourly tracking
            $table->unsignedInteger('sent_this_hour')->default(0)
                ->after('current_burst_limit');
            
            $table->timestamp('hour_started_at')->nullable()
                ->after('sent_this_hour');
            
            // Template Restrictions
            $table->json('allowed_template_categories')->nullable()
                ->after('hour_started_at')
                ->comment('Array of allowed categories: utility, notification, marketing');
            
            $table->unsignedTinyInteger('max_marketing_percent')->default(0)
                ->after('allowed_template_categories')
                ->comment('Max % of marketing messages allowed');
            
            $table->unsignedInteger('marketing_sent_today')->default(0)
                ->after('max_marketing_percent');
            
            // Interval Controls
            $table->unsignedInteger('min_interval_seconds')->default(180)
                ->after('marketing_sent_today')
                ->comment('Minimum seconds between sends');
            
            $table->unsignedInteger('max_interval_seconds')->default(420)
                ->after('min_interval_seconds')
                ->comment('Maximum seconds between sends');
            
            $table->timestamp('last_sent_at')->nullable()
                ->after('max_interval_seconds');
            
            // Cooldown Management
            $table->timestamp('cooldown_until')->nullable()
                ->after('last_sent_at')
                ->comment('When cooldown expires');
            
            $table->unsignedInteger('cooldown_hours_remaining')->default(0)
                ->after('cooldown_until');
            
            $table->string('cooldown_reason')->nullable()
                ->after('cooldown_hours_remaining');
            
            // Health Score Integration
            $table->foreignId('health_score_id')->nullable()
                ->after('cooldown_reason')
                ->constrained('wa_health_scores')
                ->nullOnDelete();
            
            $table->char('last_health_grade', 1)->nullable()
                ->after('health_score_id')
                ->comment('Last synced health grade A/B/C/D');
            
            $table->unsignedTinyInteger('last_health_score')->nullable()
                ->after('last_health_grade');
            
            // Auto-action flags
            $table->boolean('blast_enabled')->default(true)
                ->after('last_health_score');
            
            $table->boolean('campaign_enabled')->default(true)
                ->after('blast_enabled');
            
            $table->boolean('inbox_only')->default(false)
                ->after('campaign_enabled')
                ->comment('If true, only manual inbox replies allowed');
            
            // Owner override
            $table->boolean('force_cooldown')->default(false)
                ->after('inbox_only')
                ->comment('Owner forced cooldown');
            
            $table->foreignId('force_cooldown_by')->nullable()
                ->after('force_cooldown')
                ->constrained('users')
                ->nullOnDelete();
            
            $table->timestamp('force_cooldown_at')->nullable()
                ->after('force_cooldown_by');
            
            // Client display
            $table->string('client_status_label')->nullable()
                ->after('force_cooldown_at')
                ->comment('User-friendly status for client');
            
            $table->text('client_status_message')->nullable()
                ->after('client_status_label')
                ->comment('Educational message for client');
            
            // Indexes
            $table->index('warmup_state');
            $table->index('cooldown_until');
            $table->index(['warmup_state', 'blast_enabled']);
        });
        } // end hasColumn check

        // ========== WARMUP STATE EVENTS TABLE ==========
        // Audit trail for all state transitions
        if (!Schema::hasTable('warmup_state_events')) {
            Schema::create('warmup_state_events', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('warmup_id')
                ->constrained('whatsapp_warmups')
                ->onDelete('cascade');
            
            $table->foreignId('connection_id')
                ->constrained('whatsapp_connections')
                ->onDelete('cascade');
            
            $table->foreignId('user_id')->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('Client owner of the connection');
            
            // State Transition
            $table->string('from_state')->nullable();
            $table->string('to_state');
            
            // Trigger
            $table->enum('trigger_type', [
                'auto_age',           // Age-based automatic transition
                'auto_health',        // Health score triggered
                'auto_recovery',      // Auto recovery from cooldown
                'webhook_block',      // Blocked by Meta via webhook
                'webhook_fail',       // High failure via webhook
                'owner_force',        // Owner forced action
                'owner_resume',       // Owner resumed
                'daily_cron',         // Daily scheduler
                'manual_override',    // Manual API call
            ])->default('auto_age');
            
            $table->string('trigger_description')->nullable();
            
            // Context at time of event
            $table->unsignedTinyInteger('health_score_at_event')->nullable();
            $table->char('health_grade_at_event', 1)->nullable();
            $table->unsignedInteger('number_age_days_at_event')->nullable();
            $table->unsignedInteger('sent_today_at_event')->nullable();
            $table->unsignedInteger('daily_limit_at_event')->nullable();
            
            // Limit changes
            $table->unsignedInteger('old_daily_limit')->nullable();
            $table->unsignedInteger('new_daily_limit')->nullable();
            $table->unsignedInteger('old_hourly_limit')->nullable();
            $table->unsignedInteger('new_hourly_limit')->nullable();
            
            // Actor
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Who triggered (for manual actions)');
            
            $table->string('actor_role')->nullable()
                ->comment('owner or system');
            
            // Additional data
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['warmup_id', 'created_at']);
            $table->index(['connection_id', 'created_at']);
            $table->index(['to_state', 'created_at']);
            $table->index('trigger_type');
        });
}

        // ========== WARMUP LIMIT CHANGES TABLE ==========
        // Track all limit changes for audit
        if (!Schema::hasTable('warmup_limit_changes')) {
            Schema::create('warmup_limit_changes', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('warmup_id')
                ->constrained('whatsapp_warmups')
                ->onDelete('cascade');
            
            $table->foreignId('connection_id')
                ->constrained('whatsapp_connections')
                ->onDelete('cascade');
            
            // What changed
            $table->enum('limit_type', ['daily', 'hourly', 'burst', 'interval', 'template'])
                ->default('daily');
            
            $table->string('old_value');
            $table->string('new_value');
            
            // Why
            $table->enum('reason', [
                'state_transition',   // Changed due to state change
                'health_drop',        // Health score dropped
                'health_recovery',    // Health score improved
                'age_progression',    // Number got older
                'owner_override',     // Owner changed
                'auto_adjustment',    // System auto-adjusted
                'cooldown_start',     // Entering cooldown
                'cooldown_end',       // Exiting cooldown
            ])->default('state_transition');
            
            $table->string('reason_detail')->nullable();
            
            // Context
            $table->string('warmup_state_at_change')->nullable();
            $table->unsignedTinyInteger('health_score_at_change')->nullable();
            
            // Actor
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['warmup_id', 'created_at']);
            $table->index(['connection_id', 'limit_type']);
        });
}

        // ========== AUTO BLOCK ACTIONS TABLE ==========
        // Log when system auto-blocks/restricts
        if (!Schema::hasTable('warmup_auto_blocks')) {
            Schema::create('warmup_auto_blocks', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('warmup_id')
                ->constrained('whatsapp_warmups')
                ->onDelete('cascade');
            
            $table->foreignId('connection_id')
                ->constrained('whatsapp_connections')
                ->onDelete('cascade');
            
            // Block details
            $table->enum('block_type', [
                'blast_disabled',      // WA Blast disabled
                'campaign_disabled',   // Campaign disabled
                'cooldown_enforced',   // Full cooldown
                'marketing_blocked',   // Marketing templates blocked
                'rate_limited',        // Temporarily rate limited
                'suspended',           // Full suspension
            ]);
            
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])
                ->default('medium');
            
            // Trigger
            $table->string('trigger_event')->nullable()
                ->comment('What triggered: health_drop, block_webhook, etc');
            
            $table->foreignId('campaign_id')->nullable()
                ->comment('Campaign that triggered if applicable');
            
            // Duration
            $table->timestamp('blocked_at')->useCurrent();
            $table->timestamp('blocked_until')->nullable();
            $table->unsignedInteger('block_duration_hours')->nullable();
            
            // Resolution
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by_type')->nullable()
                ->comment('auto or owner');
            $table->foreignId('resolved_by_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('resolution_note')->nullable();
            
            // Impact
            $table->unsignedInteger('messages_blocked')->default(0)
                ->comment('How many messages were blocked during this period');
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['warmup_id', 'is_resolved']);
            $table->index(['connection_id', 'blocked_at']);
            $table->index(['block_type', 'is_resolved']);
        });
}

        // ========== ADD WARMUP STATE TO CONNECTIONS ==========
        if (Schema::hasTable('whatsapp_connections') && !Schema::hasColumn('whatsapp_connections', 'warmup_state')) {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->enum('warmup_state', ['NEW', 'WARMING', 'STABLE', 'COOLDOWN', 'SUSPENDED'])
                ->nullable()
                ->after('warmup_current_date')
                ->comment('Denormalized warmup state for quick lookup');
            
            $table->boolean('warmup_blast_enabled')->default(true)
                ->after('warmup_state');
            
            $table->boolean('warmup_inbox_only')->default(false)
                ->after('warmup_blast_enabled');
            
            $table->index('warmup_state');
        });
        } // end hasColumn check
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropIndex(['warmup_state']);
            $table->dropColumn([
                'warmup_state',
                'warmup_blast_enabled',
                'warmup_inbox_only',
            ]);
        });

        Schema::dropIfExists('warmup_auto_blocks');
        Schema::dropIfExists('warmup_limit_changes');
        Schema::dropIfExists('warmup_state_events');

        Schema::table('whatsapp_warmups', function (Blueprint $table) {
            $table->dropIndex(['warmup_state']);
            $table->dropIndex(['cooldown_until']);
            $table->dropIndex(['warmup_state', 'blast_enabled']);
            
            $table->dropColumn([
                'warmup_state',
                'previous_state',
                'state_changed_at',
                'number_activated_at',
                'number_age_days',
                'current_daily_limit',
                'current_hourly_limit',
                'current_burst_limit',
                'sent_this_hour',
                'hour_started_at',
                'allowed_template_categories',
                'max_marketing_percent',
                'marketing_sent_today',
                'min_interval_seconds',
                'max_interval_seconds',
                'last_sent_at',
                'cooldown_until',
                'cooldown_hours_remaining',
                'cooldown_reason',
                'health_score_id',
                'last_health_grade',
                'last_health_score',
                'blast_enabled',
                'campaign_enabled',
                'inbox_only',
                'force_cooldown',
                'force_cooldown_by',
                'force_cooldown_at',
                'client_status_label',
                'client_status_message',
            ]);
        });
    }
};
