<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL: Support Ticket Lifecycle Management
 * 
 * This table manages complete support ticket lifecycle with:
 * - Automatic SLA assignment based on user package
 * - Priority calculation (NO manual override)
 * - Complete audit trail with timestamps
 * - SLA breach detection and escalation
 * 
 * BUSINESS RULES:
 * - ❌ NO support without ticket creation
 * - ❌ NO bypassing SLA commitments
 * - ✅ ALL interactions must be logged
 * - ✅ Package-based channel restrictions
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique(); // TKT-2026-000001
            
            // Requester Information
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('requester_name', 255);
            $table->string('requester_email', 255);
            $table->string('requester_phone', 20)->nullable();
            $table->json('requester_metadata')->nullable(); // Additional requester info
            
            // Package & SLA Assignment (AUTO-DETECTED)
            $table->string('user_package', 50); // Auto-populated from subscription
            $table->foreignId('sla_definition_id')->constrained('sla_definitions');
            $table->string('assigned_channel', 50); // email, chat, phone, priority_support
            
            // Ticket Classification
            $table->string('subject', 500);
            $table->text('description');
            $table->string('category', 100); // billing, technical, general, feature_request
            $table->string('subcategory', 100)->nullable(); // More specific categorization
            $table->enum('priority', ['low', 'medium', 'high', 'critical']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            
            // Status Lifecycle
            $table->enum('status', [
                'open', 'assigned', 'in_progress', 'waiting_customer', 
                'waiting_internal', 'escalated', 'resolved', 'closed'
            ])->default('open');
            $table->string('resolution_status', 50)->nullable(); // solved, workaround, no_solution
            $table->text('resolution_summary')->nullable();
            
            // Assignment & Ownership
            $table->unsignedBigInteger('assigned_to')->nullable(); // Support agent ID
            $table->string('assigned_team', 100)->nullable(); // L1, L2, L3, management
            $table->unsignedBigInteger('escalated_to')->nullable(); // Escalated agent ID
            $table->unsignedTinyInteger('escalation_level')->default(0); // 0-3
            
            // SLA Tracking (CRITICAL - NO BYPASS)
            $table->timestamp('sla_response_due_at'); // Must respond by this time
            $table->timestamp('sla_resolution_due_at'); // Must resolve by this time
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            // SLA Compliance Status
            $table->boolean('response_sla_breached')->default(false);
            $table->boolean('resolution_sla_breached')->default(false);
            $table->timestamp('response_breach_at')->nullable();
            $table->timestamp('resolution_breach_at')->nullable();
            $table->json('sla_breach_reasons')->nullable(); // Why SLA was breached
            
            // Business Hours Calculation
            $table->unsignedInteger('business_hours_to_response')->nullable();
            $table->unsignedInteger('business_hours_to_resolution')->nullable();
            $table->json('time_tracking')->nullable(); // Detailed time calculations
            
            // Customer Satisfaction
            $table->decimal('satisfaction_rating', 3, 2)->nullable(); // 0.00-5.00
            $table->text('satisfaction_feedback')->nullable();
            $table->boolean('satisfaction_survey_sent')->default(false);
            
            // Source & Channel Tracking
            $table->string('source_channel', 50); // web, email, api, phone
            $table->string('source_ip', 45)->nullable();
            $table->string('source_user_agent', 500)->nullable();
            $table->json('source_metadata')->nullable();
            
            // Internal Notes & Flags
            $table->json('internal_notes')->nullable();
            $table->boolean('is_vip_customer')->default(false);
            $table->boolean('requires_manager_approval')->default(false);
            $table->boolean('is_public_facing')->default(false); // Can customer see all responses?
            $table->json('tags')->nullable(); // Flexible tagging system
            
            // Related Entities
            $table->json('related_objects')->nullable(); // invoices, campaigns, etc.
            $table->string('parent_ticket_id', 20)->nullable(); // For ticket linking
            $table->json('child_ticket_ids')->nullable(); // Split tickets
            
            // Metadata & Audit
            $table->unsignedBigInteger('created_by')->nullable(); // Who created (if not customer)
            $table->unsignedBigInteger('last_updated_by')->nullable();
            $table->json('status_history')->nullable(); // Status change log
            $table->json('metadata')->nullable(); // Extensible metadata
            
            $table->timestamps();
            
            // Performance Indexes
            $table->index(['user_id', 'status']);
            $table->index(['status', 'priority']);
            $table->index(['sla_response_due_at']);
            $table->index(['sla_resolution_due_at']);
            $table->index(['user_package', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['response_sla_breached', 'resolution_sla_breached'], 'tickets_sla_breach_idx');
            $table->index(['created_at']);
            
            // Foreign key constraints
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('escalated_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('last_updated_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};