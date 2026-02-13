<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL: Support Communication Tracking
 * 
 * This table logs ALL support interactions with complete audit trail:
 * - Customer communications
 * - Agent responses with timestamps
 * - Internal notes and status changes
 * - SLA compliance tracking per response
 * 
 * BUSINESS RULES:
 * - ✅ ALL communication must be logged
 * - ✅ Response time tracking per interaction
 * - ✅ Complete audit trail for compliance
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_responses')) {
            Schema::create('support_responses', function (Blueprint $table) {
            $table->id();
            
            // Ticket Association
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->string('ticket_number', 20); // Denormalized for quick lookup
            
            // Response Classification
            $table->enum('response_type', [
                'customer_message', 'agent_response', 'internal_note', 
                'status_change', 'escalation', 'auto_response', 'system_update'
            ]);
            $table->enum('communication_channel', [
                'email', 'chat', 'phone', 'web_form', 'api', 'internal'
            ]);
            
            // Author Information
            $table->unsignedBigInteger('author_id')->nullable(); // User ID (customer or agent)
            $table->string('author_type', 20); // customer, agent, system
            $table->string('author_name', 255);
            $table->string('author_email', 255)->nullable();
            
            // Response Content
            $table->text('message');
            $table->json('attachments')->nullable(); // File attachments
            $table->json('formatted_content')->nullable(); // Rich text, HTML, etc.
            $table->string('message_format', 20)->default('text'); // text, html, markdown, rich
            
            // Visibility & Access Control
            $table->boolean('is_public')->default(true); // Can customer see this?
            $table->boolean('is_internal_note')->default(false);
            $table->enum('visibility_level', ['public', 'internal', 'management'])->default('public');
            
            // Response Timing & SLA
            $table->boolean('is_first_response')->default(false);
            $table->timestamp('response_sent_at'); // When response was sent
            $table->unsignedInteger('response_time_minutes')->nullable(); // Time from ticket/last customer message
            $table->boolean('within_sla_response_time')->default(true);
            $table->json('sla_calculation_details')->nullable(); // How response time was calculated
            
            // Business Hours Calculation
            $table->unsignedInteger('business_hours_response_time')->nullable();
            $table->json('business_hours_calculation')->nullable();
            
            // Status & Priority Changes
            $table->string('previous_status', 50)->nullable(); // If this response changed status
            $table->string('new_status', 50)->nullable();
            $table->string('previous_priority', 20)->nullable();
            $table->string('new_priority', 20)->nullable();
            $table->text('status_change_reason')->nullable();
            
            // Agent Performance Tracking
            $table->unsignedBigInteger('responding_agent_id')->nullable();
            $table->string('agent_team', 100)->nullable(); // L1, L2, L3, management
            $table->decimal('agent_response_quality_score', 3, 2)->nullable(); // Quality metrics
            $table->boolean('requires_manager_review')->default(false);
            
            // Customer Interaction
            $table->boolean('requires_customer_action')->default(false);
            $table->timestamp('customer_action_due_by')->nullable();
            $table->text('customer_action_required')->nullable();
            
            // Source & Technical Details
            $table->string('source_ip', 45)->nullable();
            $table->string('source_user_agent', 500)->nullable();
            $table->json('source_metadata')->nullable(); // Request headers, etc.
            
            // References & Relationships
            $table->unsignedBigInteger('in_reply_to')->nullable(); // Previous response ID
            $table->json('referenced_responses')->nullable(); // Array of related response IDs
            $table->string('external_reference_id', 100)->nullable(); // Email message ID, etc.
            
            // Content Analysis & AI
            $table->json('sentiment_analysis')->nullable(); // AI sentiment scoring
            $table->json('content_classification')->nullable(); // Auto-categorization
            $table->decimal('urgency_score', 3, 2)->nullable(); // AI urgency detection
            $table->json('suggested_actions')->nullable(); // AI suggestions
            
            // Follow-up & Tracking
            $table->boolean('requires_followup')->default(false);
            $table->timestamp('followup_due_at')->nullable();
            $table->string('followup_type', 50)->nullable(); // reminder, escalation, survey
            $table->boolean('followup_completed')->default(false);
            
            // Quality & Compliance
            $table->boolean('is_approved')->default(true); // For responses requiring approval
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Metadata & Extensions
            $table->json('metadata')->nullable(); // Extensible metadata
            $table->json('integration_data')->nullable(); // Data from external systems
            
            $table->timestamps();
            
            // Performance Indexes
            $table->index(['support_ticket_id', 'created_at']);
            $table->index(['response_type', 'created_at']);
            $table->index(['author_id', 'author_type']);
            $table->index(['is_first_response']);
            $table->index(['within_sla_response_time']);
            $table->index(['responding_agent_id', 'created_at']);
            $table->index(['requires_followup', 'followup_due_at']);
            
            // Foreign key constraints
            $table->foreign('author_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('responding_agent_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('in_reply_to')->references('id')->on('support_responses')->onDelete('set null');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('support_responses');
    }
};