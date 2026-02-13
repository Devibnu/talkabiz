<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL: Support Channel Access Control
 * 
 * This table defines which support channels are available per package:
 * - Channel restrictions based on subscription
 * - Operating hours per channel
 * - Capacity management per channel
 * - Agent assignment rules
 * 
 * BUSINESS RULES:
 * - ✅ Channel access STRICTLY by package level
 * - ❌ NO bypassing channel restrictions
 * - ✅ Clear channel expectations per package
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_channels')) {
            Schema::create('support_channels', function (Blueprint $table) {
            $table->id();
            
            // Channel Definition
            $table->string('channel_name', 100); // email, chat, phone, priority_support
            $table->string('channel_code', 20)->unique(); // EMAIL, CHAT, PHONE, PRIORITY
            $table->text('channel_description');
            $table->string('channel_type', 50); // async, sync, hybrid
            
            // Package Access Control
            $table->json('available_for_packages'); // ['starter', 'professional', 'enterprise']
            $table->json('restricted_packages')->nullable(); // Packages that can't use this channel
            $table->boolean('requires_package_verification')->default(true);
            
            // Channel Configuration
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available_24_7')->default(false);
            $table->json('operating_hours')->nullable(); // Business hours per timezone
            $table->json('operating_days')->nullable(); // Days of week
            $table->string('timezone', 50)->default('Asia/Jakarta');
            
            // Capacity & Load Management
            $table->unsignedInteger('max_concurrent_sessions')->nullable(); // For chat/phone
            $table->unsignedInteger('max_daily_tickets')->nullable(); // Daily capacity limit
            $table->unsignedInteger('current_load')->default(0); // Current active sessions
            $table->decimal('load_percentage', 5, 2)->default(0.00); // Current load %
            
            // SLA & Response Expectations
            $table->unsignedInteger('expected_response_time_minutes'); // Channel-specific SLA
            $table->unsignedInteger('max_response_time_minutes'); // Hard limit
            $table->boolean('supports_instant_response')->default(false); // Chat, phone
            $table->boolean('supports_file_attachments')->default(true);
            $table->unsignedInteger('max_attachment_size_mb')->default(10);
            
            // Agent Assignment Rules
            $table->json('assigned_agent_teams'); // ['L1', 'L2', 'specialist']
            $table->json('escalation_rules'); // When to escalate within channel
            $table->boolean('requires_specialized_agents')->default(false);
            $table->unsignedInteger('min_agent_experience_months')->default(0);
            
            // Quality & Performance Standards
            $table->decimal('target_customer_satisfaction', 3, 2)->default(4.50); // Out of 5.00
            $table->decimal('target_first_contact_resolution', 5, 2)->default(80.00); // Percentage
            $table->unsignedInteger('max_handoff_count')->default(2); // Max transfers
            
            // Channel-Specific Settings
            $table->json('channel_settings')->nullable(); // Channel-specific configuration
            $table->string('integration_type', 100)->nullable(); // api, webhook, email, etc.
            $table->json('integration_config')->nullable(); // Integration configuration
            $table->string('external_system_id', 100)->nullable(); // External system reference
            
            // Cost & Resource Management
            $table->decimal('cost_per_interaction', 8, 4)->default(0.0000); // Cost tracking
            $table->decimal('agent_cost_per_hour', 8, 2)->default(0.00);
            $table->json('resource_requirements')->nullable(); // Tools, systems needed
            
            // Automation & AI
            $table->boolean('supports_chatbot')->default(false);
            $table->boolean('supports_auto_routing')->default(true);
            $table->boolean('supports_sentiment_analysis')->default(false);
            $table->json('automation_rules')->nullable();
            
            // Reporting & Analytics
            $table->boolean('includes_in_sla_reports')->default(true);
            $table->json('tracked_metrics')->nullable(); // Which metrics to track
            $table->boolean('requires_call_recording')->default(false); // For phone
            $table->boolean('requires_chat_logging')->default(true);
            
            // Customer Experience
            $table->text('customer_instructions')->nullable(); // How to use this channel
            $table->string('customer_portal_url', 500)->nullable(); // Where to access
            $table->json('customer_expectations')->nullable(); // What to expect
            $table->boolean('requires_customer_authentication')->default(true);
            
            // Business Rules & Compliance
            $table->json('access_conditions')->nullable(); // Conditions for access
            $table->boolean('requires_manager_approval')->default(false);
            $table->json('compliance_requirements')->nullable(); // Legal/regulatory requirements
            $table->text('terms_of_use')->nullable();
            
            // Emergency & Contingency
            $table->boolean('available_during_emergencies')->default(true);
            $table->json('emergency_escalation_rules')->nullable();
            $table->string('backup_channel_code', 20)->nullable(); // Fallback channel
            $table->json('contingency_plan')->nullable();
            
            // Metadata & Tracking
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('last_updated_by')->nullable();
            $table->json('change_history')->nullable(); // Configuration change history
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Performance Indexes
            $table->index(['channel_code', 'is_active']);
            $table->index(['is_active', 'is_available_24_7']);
            $table->index(['current_load', 'max_concurrent_sessions']);
            $table->index(['created_at']);
            
            // Foreign key constraints
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('last_updated_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('support_channels');
    }
};