<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ==================== AUDIT LOGS TABLE ====================
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50)->index();
            $table->json('event_data');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            // Indexes for efficient querying
            $table->index(['event_type', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');

            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
}

        // ==================== BALANCE CHANGE AUDIT TABLE ====================
        if (!Schema::hasTable('balance_change_audit')) {
            Schema::create('balance_change_audit', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_id', 50)->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->enum('change_type', ['adjustment', 'payment', 'refund', 'bonus', 'penalty'])->default('adjustment');
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->decimal('net_change', 15, 2); // Positive for credit, negative for debit
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at');
            $table->string('reference_type', 50); // user_adjustment, payment_transaction, etc.
            $table->unsignedBigInteger('reference_id');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'processed_at']);
            $table->index(['change_type', 'processed_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('processed_at');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('processed_by')->references('id')->on('users')->onDelete('set null');
        });
}

        // ==================== SECURITY VIOLATIONS TABLE ====================
        if (!Schema::hasTable('security_violations')) {
            Schema::create('security_violations', function (Blueprint $table) {
            $table->id();
            $table->string('violation_type', 100)->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('adjustment_id', 50)->nullable()->index();
            $table->ipAddress('ip_address')->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->json('violation_data');
            $table->boolean('is_resolved')->default(false)->index();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->boolean('auto_blocked')->default(false);
            $table->timestamps();

            // Indexes for monitoring and reporting
            $table->index(['violation_type', 'created_at']);
            $table->index(['severity', 'is_resolved']);
            $table->index(['user_id', 'created_at']);
            $table->index(['is_resolved', 'severity']);

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
}

        // ==================== ADJUSTMENT NOTIFICATIONS TABLE ====================
        if (!Schema::hasTable('adjustment_notifications')) {
            Schema::create('adjustment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_id', 50)->index();
            $table->enum('notification_type', [
                'created', 
                'approved', 
                'rejected', 
                'processed', 
                'failed', 
                'security_alert'
            ])->index();
            $table->enum('channel', ['email', 'sms', 'slack', 'webhook', 'internal'])->index();
            $table->json('recipients'); // Array of user IDs, emails, or endpoints
            $table->string('subject', 255)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'delivered'])->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();

            // Indexes for notification processing
            $table->index(['status', 'created_at']);
            $table->index(['notification_type', 'channel']);
            $table->index(['adjustment_id', 'notification_type']);

            // Foreign key to adjustments
            $table->foreign('adjustment_id')->references('adjustment_id')->on('user_adjustments')->onDelete('cascade');
        });
}

        // ==================== ADJUSTMENT SETTINGS TABLE ====================
        if (!Schema::hasTable('adjustment_settings')) {
            Schema::create('adjustment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->string('type', 20)->default('string'); // string, integer, float, boolean, json
            $table->text('description')->nullable();
            $table->string('category', 50)->default('general'); // general, security, limits, notifications
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('requires_restart')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['category', 'key']);
            $table->index('key');

            // Foreign key
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjustment_settings');
        Schema::dropIfExists('adjustment_notifications');
        Schema::dropIfExists('security_violations');
        Schema::dropIfExists('balance_change_audit');
        Schema::dropIfExists('audit_logs');
    }
};