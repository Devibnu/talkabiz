<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support & SLA Tables Migration
 * 
 * FLOW:
 * =====
 * 1. Client creates ticket → SLA snapshot diambil dari subscription
 * 2. System calculate response_due_at & resolution_due_at
 * 3. Agent acknowledge → first_response_at recorded
 * 4. Agent resolves → resolved_at recorded
 * 5. Client closes → closed_at recorded
 * 
 * SLA CALCULATION:
 * ================
 * - Based on business hours from plan snapshot
 * - Priority affects response/resolution time
 * - Breach detected when due_at < now() without response/resolution
 * 
 * LIFECYCLE:
 * ==========
 * new → acknowledged → in_progress → resolved → closed
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== SLA CONFIGS (Per Plan) ====================
        if (!Schema::hasTable('sla_configs')) {
            Schema::create('sla_configs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('plan_id')
                  ->constrained('plans')
                  ->onDelete('cascade');
            
            // Priority level
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])
                  ->default('medium');
            
            // SLA Times (in minutes for precision)
            $table->unsignedInteger('response_time_minutes')
                  ->comment('Target first response time');
            $table->unsignedInteger('resolution_time_minutes')
                  ->comment('Target resolution time');
            
            // Business Hours
            $table->time('business_hours_start')->default('09:00:00');
            $table->time('business_hours_end')->default('18:00:00');
            $table->json('business_days')->nullable()
                  ->comment('1=Mon, 7=Sun, e.g. [1,2,3,4,5] = weekdays');
            $table->string('timezone', 50)->default('Asia/Jakarta');
            
            // 24/7 Support flag
            $table->boolean('is_24x7')->default(false)
                  ->comment('If true, ignore business hours');
            
            // Active flag
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->unique(['plan_id', 'priority']);
        });
}

        // ==================== SUPPORT TICKETS ====================
        if (!Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            
            // Ticket identifier
            $table->string('ticket_number', 20)->unique()
                  ->comment('Format: TKT-YYYYMM-XXXXX');
            
            // References
            $table->foreignId('klien_id')
                  ->constrained('klien')
                  ->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()
                  ->constrained('subscriptions')
                  ->nullOnDelete();
            
            // Reporter
            $table->unsignedBigInteger('created_by')->nullable()
                  ->comment('User/Pengguna who created');
            $table->string('reporter_name', 100)->nullable();
            $table->string('reporter_email', 100)->nullable();
            
            // Ticket content
            $table->string('subject', 255);
            $table->text('description');
            $table->string('category', 50)->default('general')
                  ->comment('general, technical, billing, feature_request, bug');
            
            // Priority & Status
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])
                  ->default('medium');
            $table->enum('status', ['new', 'acknowledged', 'in_progress', 'pending_client', 'resolved', 'closed'])
                  ->default('new');
            
            // Assignment
            $table->unsignedBigInteger('assigned_to')->nullable()
                  ->comment('Agent user_id');
            $table->string('assigned_to_name', 100)->nullable();
            
            // ==================== SLA SNAPSHOT ====================
            $table->json('sla_snapshot')->nullable()
                  ->comment('Snapshot of SLA config at ticket creation');
            $table->json('plan_snapshot')->nullable()
                  ->comment('Snapshot of plan at ticket creation');
            
            // ==================== TIMESTAMPS ====================
            $table->timestamp('acknowledged_at')->nullable()
                  ->comment('First agent acknowledgment');
            $table->timestamp('first_response_at')->nullable()
                  ->comment('First substantive response');
            $table->timestamp('resolved_at')->nullable()
                  ->comment('Marked as resolved');
            $table->timestamp('closed_at')->nullable()
                  ->comment('Closed by client or auto-close');
            
            // ==================== SLA TARGETS & METRICS ====================
            $table->timestamp('response_due_at')->nullable()
                  ->comment('Deadline for first response');
            $table->timestamp('resolution_due_at')->nullable()
                  ->comment('Deadline for resolution');
            
            $table->boolean('response_sla_met')->nullable()
                  ->comment('null=pending, true=met, false=breached');
            $table->boolean('resolution_sla_met')->nullable();
            
            $table->integer('response_time_minutes')->nullable()
                  ->comment('Actual response time in minutes');
            $table->integer('resolution_time_minutes')->nullable()
                  ->comment('Actual resolution time in minutes');
            
            // ==================== BREACH FLAGS ====================
            $table->boolean('response_breached')->default(false);
            $table->timestamp('response_breached_at')->nullable();
            $table->boolean('resolution_breached')->default(false);
            $table->timestamp('resolution_breached_at')->nullable();
            $table->boolean('breach_notified')->default(false);
            
            // ==================== METADATA ====================
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->text('internal_notes')->nullable();
            
            // Auto-close
            $table->unsignedInteger('auto_close_hours')->nullable()
                  ->comment('Auto-close after resolved if no response');
            $table->timestamp('auto_close_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['response_due_at']);
            $table->index(['resolution_due_at']);
            $table->index(['response_breached', 'resolution_breached']);
        });
}

        // ==================== TICKET EVENTS (Append-Only) ====================
        if (!Schema::hasTable('ticket_events')) {
            Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('ticket_id')
                  ->constrained('support_tickets')
                  ->onDelete('cascade');
            
            // Event type
            $table->string('event_type', 50)
                  ->comment('created, acknowledged, assigned, status_changed, commented, resolved, closed, escalated, response_breached, resolution_breached');
            
            // Change tracking
            $table->string('old_value', 255)->nullable();
            $table->string('new_value', 255)->nullable();
            
            // Actor info
            $table->enum('actor_type', ['client', 'agent', 'system'])->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            
            // Content (for comments)
            $table->text('content')->nullable();
            $table->boolean('is_internal')->default(false)
                  ->comment('Internal note, not visible to client');
            
            // Attachments
            $table->json('attachments')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['ticket_id', 'event_type']);
            $table->index(['ticket_id', 'created_at']);
        });
}

        // ==================== SLA BREACH LOGS ====================
        if (!Schema::hasTable('sla_breach_logs')) {
            Schema::create('sla_breach_logs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('ticket_id')
                  ->constrained('support_tickets')
                  ->onDelete('cascade');
            $table->foreignId('klien_id')
                  ->constrained('klien')
                  ->onDelete('cascade');
            
            // Breach type
            $table->enum('breach_type', ['response', 'resolution']);
            
            // Timing
            $table->timestamp('due_at');
            $table->timestamp('breached_at');
            $table->integer('overdue_minutes')
                  ->comment('How many minutes overdue');
            
            // SLA info at breach time
            $table->integer('target_minutes')
                  ->comment('What was the SLA target');
            $table->string('priority', 20);
            
            // Notification
            $table->boolean('owner_notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_channel', 50)->nullable()
                  ->comment('email, slack, webhook');
            
            // Resolution
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->integer('total_breach_minutes')->nullable()
                  ->comment('Total minutes in breach before resolution');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['klien_id', 'breach_type']);
            $table->index(['breached_at']);
            $table->index(['is_resolved']);
        });
}

        // ==================== TICKET ATTACHMENTS ====================
        if (!Schema::hasTable('ticket_attachments')) {
            Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('ticket_id')
                  ->constrained('support_tickets')
                  ->onDelete('cascade');
            $table->unsignedBigInteger('event_id')->nullable()
                  ->comment('Reference to ticket_events if attached with comment');
            
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path', 500);
            $table->string('storage_disk', 50)->default('local');
            
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->enum('uploaded_by_type', ['client', 'agent'])->default('client');
            
            $table->timestamps();
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('sla_breach_logs');
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('sla_configs');
    }
};
