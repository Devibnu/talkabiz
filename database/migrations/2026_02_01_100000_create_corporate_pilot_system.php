<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate Pilot System Migration
 * 
 * Creates complete infrastructure for invite-only corporate pilot program:
 * - Corporate invites table
 * - Corporate client profiles with custom limits
 * - Corporate contracts (manual billing)
 * - Corporate activity logs
 * 
 * PRINCIPLES:
 * - Invite-only (no public access)
 * - Custom limits per client
 * - SLA-aware
 * - Full audit trail
 * - Failsafe controls
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== CORPORATE INVITES ====================
        if (!Schema::hasTable('corporate_invites')) {
            Schema::create('corporate_invites', function (Blueprint $table) {
            $table->id();
            
            // Invite Details
            $table->string('email')->unique();
            $table->string('company_name');
            $table->string('contact_person');
            $table->string('contact_phone')->nullable();
            $table->string('industry')->nullable();
            $table->text('notes')->nullable();
            
            // Invite Token
            $table->string('invite_token', 64)->unique();
            $table->timestamp('invite_expires_at');
            
            // Status: pending, accepted, expired, revoked
            $table->string('status', 20)->default('pending');
            
            // Tracking
            $table->unsignedBigInteger('invited_by'); // Admin
            $table->unsignedBigInteger('user_id')->nullable(); // When accepted
            $table->timestamp('accepted_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('status', 'idx_corp_invites_status');
            $table->index('invite_token', 'idx_corp_invites_token');
            $table->index('invited_by', 'idx_corp_invites_admin');
        });
}

        // ==================== CORPORATE CLIENTS ====================
        if (!Schema::hasTable('corporate_clients')) {
            Schema::create('corporate_clients', function (Blueprint $table) {
            $table->id();
            
            // Link to User
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Company Info
            $table->string('company_name');
            $table->string('company_legal_name')->nullable();
            $table->string('company_address')->nullable();
            $table->string('company_npwp')->nullable();
            $table->string('industry')->nullable();
            
            // Contact
            $table->string('contact_person');
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            
            // Corporate Status: pending, active, suspended, churned
            $table->string('status', 20)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            
            // ==================== CUSTOM LIMITS (Override) ====================
            $table->unsignedInteger('limit_messages_monthly')->nullable()->comment('Override monthly message limit');
            $table->unsignedInteger('limit_messages_daily')->nullable()->comment('Override daily message limit');
            $table->unsignedInteger('limit_messages_hourly')->nullable()->comment('Override hourly message limit');
            $table->unsignedInteger('limit_wa_numbers')->nullable()->comment('Override WA numbers limit');
            $table->unsignedInteger('limit_active_campaigns')->nullable()->comment('Override active campaigns limit');
            $table->unsignedInteger('limit_recipients_per_campaign')->nullable()->comment('Override recipients per campaign');
            
            // ==================== SLA FLAGS ====================
            $table->boolean('sla_priority_queue')->default(true)->comment('Use priority queue for sending');
            $table->unsignedTinyInteger('sla_max_retries')->default(5)->comment('Max retry attempts');
            $table->unsignedInteger('sla_target_delivery_rate')->default(95)->comment('Target delivery % for SLA');
            $table->unsignedInteger('sla_max_latency_seconds')->default(30)->comment('Max acceptable latency');
            
            // ==================== FAILSAFE FLAGS ====================
            $table->boolean('is_paused')->default(false)->comment('Admin paused this client');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedBigInteger('paused_by')->nullable();
            $table->string('pause_reason')->nullable();
            
            $table->boolean('is_throttled')->default(false)->comment('Throttled due to issues');
            $table->unsignedInteger('throttle_rate_percent')->default(100)->comment('Rate limit percentage (100=normal)');
            
            // Risk & Monitoring
            $table->unsignedTinyInteger('risk_score')->default(0)->comment('Current risk score 0-100');
            $table->timestamp('last_risk_evaluated_at')->nullable();
            
            // Internal Notes
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('status', 'idx_corp_clients_status');
            $table->index('is_paused', 'idx_corp_clients_paused');
        });
}

        // ==================== CORPORATE CONTRACTS (Manual Billing) ====================
        if (!Schema::hasTable('corporate_contracts')) {
            Schema::create('corporate_contracts', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('corporate_client_id');
            $table->foreign('corporate_client_id')->references('id')->on('corporate_clients')->onDelete('cascade');
            
            // Contract Details
            $table->string('contract_number')->unique();
            $table->string('plan_name'); // e.g., "Corporate Silver", "Enterprise Gold"
            $table->text('plan_description')->nullable();
            
            // Billing
            $table->string('billing_cycle', 20)->default('monthly'); // monthly, quarterly, yearly
            $table->decimal('contract_value', 15, 2); // Total contract value
            $table->decimal('monthly_rate', 15, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            
            // Duration
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            
            // Status: draft, active, expired, cancelled
            $table->string('status', 20)->default('draft');
            
            // Payment Tracking (Manual)
            $table->date('last_invoice_date')->nullable();
            $table->date('next_invoice_date')->nullable();
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            
            // Approval
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('status', 'idx_corp_contracts_status');
            $table->index(['corporate_client_id', 'status'], 'idx_corp_contracts_client_status');
        });
}

        // ==================== CORPORATE ACTIVITY LOG ====================
        if (!Schema::hasTable('corporate_activity_logs')) {
            Schema::create('corporate_activity_logs', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('corporate_client_id');
            $table->foreign('corporate_client_id')->references('id')->on('corporate_clients')->onDelete('cascade');
            
            // Activity Type
            $table->string('action', 50); // invite_sent, activated, paused, throttled, limit_changed, etc.
            $table->string('category', 30)->default('general'); // general, billing, sla, risk, limit
            
            // Details
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            // Actor
            $table->unsignedBigInteger('performed_by')->nullable(); // Admin/system
            $table->string('performed_by_type', 20)->default('admin'); // admin, system, user
            
            $table->timestamp('created_at');
            
            // Indexes
            $table->index(['corporate_client_id', 'created_at'], 'idx_corp_logs_client_date');
            $table->index('action', 'idx_corp_logs_action');
        });
}

        // ==================== CORPORATE METRICS SNAPSHOTS ====================
        if (!Schema::hasTable('corporate_metric_snapshots')) {
            Schema::create('corporate_metric_snapshots', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('corporate_client_id');
            $table->foreign('corporate_client_id')->references('id')->on('corporate_clients')->onDelete('cascade');
            
            $table->date('snapshot_date');
            
            // Delivery Metrics
            $table->unsignedInteger('messages_sent')->default(0);
            $table->unsignedInteger('messages_delivered')->default(0);
            $table->unsignedInteger('messages_failed')->default(0);
            $table->unsignedInteger('messages_pending')->default(0);
            $table->decimal('delivery_rate', 5, 2)->default(0);
            $table->decimal('failure_rate', 5, 2)->default(0);
            
            // Latency
            $table->unsignedInteger('avg_latency_seconds')->default(0);
            $table->unsignedInteger('p95_latency_seconds')->default(0);
            
            // SLA
            $table->boolean('sla_met')->default(true);
            $table->text('sla_breach_reason')->nullable();
            
            // Risk
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('top_errors')->nullable();
            
            $table->timestamps();
            
            // Unique constraint
            $table->unique(['corporate_client_id', 'snapshot_date'], 'idx_corp_metrics_unique');
        });
}

        // ==================== ADD CORPORATE ROLE TO USERS ====================
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'corporate_status')) {
        Schema::table('users', function (Blueprint $table) {
            // Corporate status: null (not corporate), pending, active, suspended
            $table->string('corporate_status', 20)->nullable()->after('corporate_pilot_notes')
                ->comment('Corporate status: pending, active, suspended');
            
            $table->index('corporate_status', 'idx_users_corporate_status');
        });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_corporate_status');
            $table->dropColumn('corporate_status');
        });
        
        Schema::dropIfExists('corporate_metric_snapshots');
        Schema::dropIfExists('corporate_activity_logs');
        Schema::dropIfExists('corporate_contracts');
        Schema::dropIfExists('corporate_clients');
        Schema::dropIfExists('corporate_invites');
    }
};
