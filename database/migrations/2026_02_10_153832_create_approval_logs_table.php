<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create approval_logs table
 * 
 * PURPOSE:
 * - Audit trail for all approval actions
 * - Track who approved/rejected/suspended
 * - Store reasons and metadata
 * - Compliance and security
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('approval_logs')) {
            Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            
            // Target klien
            $table->unsignedBigInteger('klien_id')
                ->comment('Business profile being approved/rejected');
            
            // Action details
            $table->enum('action', ['approve', 'reject', 'suspend', 'reactivate', 'request_review'])
                ->comment('Type of approval action');
            
            $table->string('status_from', 20)
                ->nullable()
                ->comment('Previous approval status');
            
            $table->string('status_to', 20)
                ->comment('New approval status');
            
            // Actor
            $table->unsignedBigInteger('actor_id')
                ->comment('Admin/Owner user ID who performed action');
            
            $table->string('actor_type', 50)
                ->default('admin')
                ->comment('Type of actor: admin, owner, system');
            
            // Reason & Context
            $table->text('reason')
                ->nullable()
                ->comment('Reason for approval/rejection');
            
            $table->json('metadata')
                ->nullable()
                ->comment('Additional context: IP, risk_score, flags, etc');
            
            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('klien_id', 'idx_klien_logs');
            $table->index(['klien_id', 'created_at'], 'idx_klien_timeline');
            $table->index('actor_id', 'idx_actor');
            $table->index('action', 'idx_action');
            
            // Foreign key (soft constraint)
            $table->foreign('klien_id')
                ->references('id')
                ->on('klien')
                ->onDelete('cascade');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
    }
};
