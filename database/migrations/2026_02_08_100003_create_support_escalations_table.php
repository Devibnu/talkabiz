<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL: SLA Breach Escalation Management
 * 
 * This table manages escalations when SLA commitments are breached:
 * - Automatic escalation triggers
 * - Management notification system
 * - Escalation chain tracking
 * - Root cause analysis for breaches
 * 
 * BUSINESS RULES:
 * - ✅ AUTOMATIC escalation when SLA breached
 * - ✅ NO manual override of escalation rules
 * - ✅ Complete audit trail of escalation decisions
 * - ✅ Management visibility into all breaches
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_escalations')) {
            Schema::create('support_escalations', function (Blueprint $table) {
            $table->id();
            
            // Ticket Association
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->string('ticket_number', 20); // Denormalized for reporting
            
            // Escalation Trigger
            $table->enum('escalation_trigger', [
                'response_sla_breach', 'resolution_sla_breach', 'customer_escalation', 
                'critical_priority', 'vip_customer', 'management_request', 'quality_issue'
            ]);
            $table->timestamp('trigger_timestamp');
            $table->text('trigger_details'); // Why escalation was triggered
            
            // Escalation Level & Chain
            $table->unsignedTinyInteger('escalation_level'); // 1, 2, 3
            $table->string('escalation_from_team', 100); // L1, L2, L3
            $table->string('escalation_to_team', 100); // L2, L3, management
            $table->unsignedBigInteger('escalated_from_agent')->nullable();
            $table->unsignedBigInteger('escalated_to_agent')->nullable();
            
            // SLA Breach Analysis
            $table->enum('sla_breach_type', ['response_time', 'resolution_time', 'both'])->nullable();
            $table->timestamp('sla_due_at')->nullable(); // When SLA was due
            $table->timestamp('actual_timestamp')->nullable(); // When action actually happened
            $table->unsignedInteger('breach_duration_minutes')->nullable(); // How long overdue
            $table->decimal('breach_percentage', 5, 2)->nullable(); // How much over SLA (150% = 50% over)
            
            // Business Hours Impact
            $table->unsignedInteger('business_hours_breach_duration')->nullable();
            $table->json('breach_calculation_details')->nullable();
            
            // Package Impact Analysis
            $table->string('customer_package', 50);
            $table->decimal('package_sla_target_response_hours', 5, 2);
            $table->decimal('package_sla_target_resolution_hours', 5, 2);
            $table->json('package_contract_terms')->nullable(); // Contract implications
            
            // Root Cause Analysis
            $table->enum('root_cause_category', [
                'high_volume', 'agent_unavailable', 'technical_complexity', 
                'customer_unresponsive', 'system_issue', 'process_gap', 'training_needed'
            ])->nullable();
            $table->text('root_cause_description')->nullable();
            $table->json('contributing_factors')->nullable();
            
            // Resolution & Action Plan
            $table->enum('escalation_status', [
                'active', 'acknowledged', 'in_progress', 'resolved', 
                'customer_resolved', 'management_resolved'
            ])->default('active');
            $table->text('action_plan')->nullable();
            $table->timestamp('target_resolution_time')->nullable();
            $table->timestamp('escalation_resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            // Customer Impact & Communication
            $table->enum('customer_impact_level', ['low', 'medium', 'high', 'critical']);
            $table->boolean('customer_notified')->default(false);
            $table->timestamp('customer_notification_sent_at')->nullable();
            $table->text('customer_apology_message')->nullable();
            $table->json('compensation_offered')->nullable(); // Credits, discounts, etc.
            
            // Management Oversight
            $table->unsignedBigInteger('manager_assigned')->nullable();
            $table->timestamp('manager_notified_at')->nullable();
            $table->boolean('requires_executive_attention')->default(false);
            $table->unsignedBigInteger('executive_notified')->nullable();
            $table->timestamp('executive_notification_sent_at')->nullable();
            
            // Follow-up & Prevention
            $table->json('prevention_measures')->nullable(); // Steps to prevent recurrence
            $table->boolean('requires_process_improvement')->default(false);
            $table->text('process_improvement_recommendations')->nullable();
            $table->boolean('requires_training')->default(false);
            $table->json('training_recommendations')->nullable();
            
            // Performance Impact
            $table->json('kpi_impact')->nullable(); // How this affects team KPIs
            $table->boolean('affects_sla_reporting')->default(true);
            $table->decimal('cost_impact', 10, 2)->nullable(); // Financial impact of breach
            
            // Quality Assurance
            $table->decimal('escalation_quality_score', 3, 2)->nullable();
            $table->text('quality_review_notes')->nullable();
            $table->boolean('requires_quality_review')->default(true);
            $table->unsignedBigInteger('quality_reviewed_by')->nullable();
            $table->timestamp('quality_review_completed_at')->nullable();
            
            // External References
            $table->string('incident_reference', 100)->nullable(); // If related to system incident
            $table->json('related_escalations')->nullable(); // Related escalation IDs
            $table->string('external_case_id', 100)->nullable(); // External system reference
            
            // Audit & Metadata
            $table->unsignedBigInteger('created_by')->nullable(); // System or user who created
            $table->json('escalation_history')->nullable(); // Status change history
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Performance Indexes
            $table->index(['support_ticket_id', 'escalation_level']);
            $table->index(['escalation_trigger', 'created_at']);
            $table->index(['escalation_status', 'escalation_level']);
            $table->index(['sla_breach_type', 'created_at']);
            $table->index(['customer_package', 'escalation_trigger']);
            $table->index(['manager_assigned', 'escalation_status']);
            $table->index(['root_cause_category', 'created_at']);
            
            // Foreign key constraints
            $table->foreign('escalated_from_agent')->references('id')->on('users')->onDelete('set null');
            $table->foreign('escalated_to_agent')->references('id')->on('users')->onDelete('set null');
            $table->foreign('manager_assigned')->references('id')->on('users')->onDelete('set null');
            $table->foreign('executive_notified')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('quality_reviewed_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('support_escalations');
    }
};