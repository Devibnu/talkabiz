<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue Guard Logs — Audit trail untuk semua guard events.
 * 
 * Layer 1: subscription_blocked (no_subscription, subscription_expired)
 * Layer 2: plan_limit_exceeded (campaign_limit, recipient_limit, wa_number_limit)
 * Layer 3: insufficient_balance (saldo tidak cukup untuk estimated cost)
 * Layer 4: deduction_failed (atomic deduction rollback), deduction_success
 * Anti-Double: duplicate_blocked (idempotency_key already exists)
 * 
 * @see RevenueGuardLog model
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_guard_logs', function (Blueprint $table) {
            $table->id();
            
            // WHO
            $table->unsignedBigInteger('user_id')->index();
            
            // WHAT
            $table->string('guard_layer', 30)->index(); // subscription|plan_limit|saldo|deduction|anti_double
            $table->string('event_type', 50)->index();  // subscription_blocked|plan_limit_exceeded|insufficient_balance|deduction_failed|deduction_success|duplicate_blocked
            $table->string('action', 50)->nullable();   // send_message|create_campaign|send_template
            
            // CONTEXT
            $table->string('reference_type', 50)->nullable(); // campaign|broadcast|single_message|inbox
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->index(); // Anti double-charge tracking
            
            // DECISION
            $table->boolean('blocked')->default(false);    // true = request was blocked
            $table->string('reason', 100)->nullable();     // Human-readable reason
            
            // FINANCIAL
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            
            // DETAILS
            $table->json('metadata')->nullable(); // Extra context (plan name, limit details, pricing info)
            
            // IP & Request
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            $table->timestamps();
            
            // Composite indexes for reporting
            $table->index(['user_id', 'guard_layer', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['blocked', 'created_at']);
            
            // FK
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_guard_logs');
    }
};
