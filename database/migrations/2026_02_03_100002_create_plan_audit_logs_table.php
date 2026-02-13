<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan Audit Logs Table
 * 
 * Mencatat semua perubahan pada paket untuk audit trail.
 * Setiap create/update/toggle pada paket akan dicatat di sini.
 * 
 * Actions:
 * - created: Paket baru dibuat
 * - updated: Paket diupdate (harga, limit, fitur)
 * - activated: Paket diaktifkan
 * - deactivated: Paket dinonaktifkan
 * - marked_popular: Paket ditandai sebagai populer
 * - unmarked_popular: Paket dihapus dari populer
 * 
 * @see SA Document: Modul Paket / Subscription Plan
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plan_audit_logs')) {
            Schema::create('plan_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignId('plan_id')
                  ->constrained('plans')
                  ->onDelete('cascade')
                  ->comment('Paket yang diaudit');
            
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade')
                  ->comment('User (owner) yang melakukan perubahan');
            
            // Action type
            $table->enum('action', [
                'created',
                'updated',
                'activated',
                'deactivated',
                'marked_popular',
                'unmarked_popular'
            ])->comment('Jenis aksi yang dilakukan');
            
            // Change details
            $table->json('old_values')->nullable()
                  ->comment('Nilai sebelum perubahan');
            $table->json('new_values')->nullable()
                  ->comment('Nilai setelah perubahan');
            
            // Request metadata
            $table->string('ip_address', 45)->nullable()
                  ->comment('IP address user');
            $table->string('user_agent', 500)->nullable()
                  ->comment('Browser user agent');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index('plan_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_audit_logs');
    }
};
