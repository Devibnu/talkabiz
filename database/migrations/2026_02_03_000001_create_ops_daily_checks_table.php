<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for Daily Operations Checks Table
 * 
 * Stores daily operations check results for H+1 to H+7 monitoring
 */
return new class extends Migration
{
    public function up(): void
    {
        // Daily ops check history
        if (!Schema::hasTable('ops_daily_checks')) {
            Schema::create('ops_daily_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('check_day'); // 1-7
            $table->date('check_date');
            $table->json('results')->nullable();
            $table->unsignedInteger('alerts_count')->default(0);
            $table->json('alerts')->nullable();
            $table->string('status')->default('completed'); // completed, partial, failed
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['check_date', 'check_day']);
        });
}

        // Weekly summary for decision tracking
        if (!Schema::hasTable('ops_weekly_summaries')) {
            Schema::create('ops_weekly_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->unsignedTinyInteger('week_number'); // Week 1, 2, etc.
            
            // Business Metrics
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('gross_profit', 15, 2)->default(0);
            $table->decimal('avg_margin_percent', 5, 2)->default(0);
            
            // Activity Metrics
            $table->unsignedInteger('total_messages')->default(0);
            $table->unsignedInteger('active_clients')->default(0);
            $table->unsignedInteger('new_clients')->default(0);
            
            // Health Metrics
            $table->decimal('avg_health_score', 5, 2)->default(0);
            $table->unsignedInteger('numbers_grade_a')->default(0);
            $table->unsignedInteger('numbers_grade_b')->default(0);
            $table->unsignedInteger('numbers_grade_c')->default(0);
            $table->unsignedInteger('numbers_grade_d')->default(0);
            
            // Stability Metrics
            $table->unsignedInteger('total_errors')->default(0);
            $table->unsignedInteger('fatal_errors')->default(0);
            $table->decimal('delivery_rate', 5, 2)->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);
            
            // Security Metrics
            $table->unsignedInteger('abuse_incidents')->default(0);
            $table->unsignedInteger('blocked_clients')->default(0);
            
            // Decision
            $table->unsignedTinyInteger('decision_score')->default(0);
            $table->enum('recommendation', ['SCALE', 'HOLD', 'REVIEW'])->default('REVIEW');
            $table->enum('owner_decision', ['SCALE', 'HOLD', 'PENDING'])->default('PENDING');
            $table->text('owner_notes')->nullable();
            $table->timestamp('decision_at')->nullable();
            
            $table->json('daily_checks')->nullable(); // Reference to daily checks
            $table->json('blockers')->nullable();
            $table->json('warnings')->nullable();
            $table->json('achievements')->nullable();
            
            $table->timestamps();

            $table->unique('week_start_date');
        });
}

        // Action items from daily checks
        if (!Schema::hasTable('ops_action_items')) {
            Schema::create('ops_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_check_id')->nullable()->constrained('ops_daily_checks')->onDelete('cascade');
            $table->foreignId('weekly_summary_id')->nullable()->constrained('ops_weekly_summaries')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->enum('category', ['stability', 'deliverability', 'billing', 'ux', 'security', 'other'])->default('other');
            $table->enum('status', ['open', 'in_progress', 'completed', 'wont_fix', 'deferred'])->default('open');
            
            $table->string('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('due_date');
        });
}

        // Risk events log
        if (!Schema::hasTable('ops_risk_events')) {
            Schema::create('ops_risk_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type'); // health_drop, spam_detected, margin_low, etc.
            $table->string('severity'); // critical, high, medium, low
            $table->unsignedBigInteger('related_client_id')->nullable();
            $table->unsignedBigInteger('related_connection_id')->nullable();
            
            $table->text('description');
            $table->json('data')->nullable();
            
            $table->enum('status', ['open', 'acknowledged', 'mitigated', 'resolved'])->default('open');
            $table->string('mitigated_by')->nullable();
            $table->timestamp('mitigated_at')->nullable();
            $table->text('mitigation_notes')->nullable();
            
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index(['severity', 'status']);
            $table->index('created_at');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_risk_events');
        Schema::dropIfExists('ops_action_items');
        Schema::dropIfExists('ops_weekly_summaries');
        Schema::dropIfExists('ops_daily_checks');
    }
};
