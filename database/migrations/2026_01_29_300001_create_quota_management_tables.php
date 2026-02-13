<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration untuk tabel pendukung QuotaService
 * 
 * Tabel yang dibuat:
 * 1. quota_reservations - Untuk reservation pattern
 * 2. quota_consumed_keys - Untuk idempotency tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== QUOTA RESERVATIONS ====================
        // Tabel untuk menyimpan reservasi kuota sebelum operasi
        Schema::create('quota_reservations', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys (tanpa constraint karena kliens mungkin belum ada)
            $table->unsignedBigInteger('klien_id');
            $table->foreignId('user_plan_id')->constrained('user_plans')->onDelete('cascade');
            
            // Reservation identifier
            $table->uuid('reservation_key')->unique();
            
            // Amount reserved
            $table->unsignedInteger('amount')->default(1);
            
            // Status: pending, confirmed, cancelled, expired
            $table->string('status', 20)->default('pending');
            
            // Reference to what this reservation is for
            $table->string('reference_type', 50)->nullable(); // campaign, message, bulk
            $table->unsignedBigInteger('reference_id')->nullable();
            
            // Timestamps
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['user_plan_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // ==================== QUOTA CONSUMED KEYS ====================
        // Tabel untuk tracking idempotency key yang sudah digunakan
        // Mencegah double consume pada retry
        Schema::create('quota_consumed_keys', function (Blueprint $table) {
            $table->id();
            
            // Idempotency key (unique per consume operation)
            $table->string('idempotency_key', 100)->unique();
            
            // Foreign keys (tanpa constraint)
            $table->unsignedBigInteger('klien_id');
            $table->foreignId('user_plan_id')->constrained('user_plans')->onDelete('cascade');
            
            // Amount consumed
            $table->unsignedInteger('amount')->default(1);
            
            // Status: consumed, rolled_back
            $table->string('status', 20)->default('consumed');
            
            // Metadata (JSON) - untuk debugging & audit
            $table->json('metadata')->nullable();
            
            // Timestamps
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // ==================== QUOTA USAGE LOGS ====================
        // Tabel untuk detailed logging setiap operasi kuota
        // Berguna untuk audit trail dan debugging
        Schema::create('quota_usage_logs', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys (tanpa constraint)
            $table->unsignedBigInteger('klien_id');
            $table->unsignedBigInteger('user_plan_id')->nullable();
            
            // Operation details
            $table->string('operation', 30); // consume, rollback, reserve, confirm, cancel
            $table->integer('amount'); // Can be negative for rollback display
            
            // Balances (untuk audit trail)
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            
            // Reference
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            
            // Context
            $table->string('source', 50)->nullable(); // api, queue, admin, system
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['klien_id', 'created_at']);
            $table->index(['user_plan_id', 'created_at']);
            $table->index(['operation', 'created_at']);
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_usage_logs');
        Schema::dropIfExists('quota_consumed_keys');
        Schema::dropIfExists('quota_reservations');
    }
};
