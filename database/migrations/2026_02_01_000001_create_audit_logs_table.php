<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table audit_logs untuk mencatat semua aksi OWNER.
     */
    public function up(): void
    {
        // Skip if table already exists
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Actor (siapa yang melakukan aksi)
            $table->foreignId('actor_id')->constrained('users')->onDelete('cascade');
            $table->string('actor_role', 50)->default('owner');
            
            // Target (object yang terkena aksi)
            $table->string('target_type', 50); // user, whatsapp, campaign, dll
            $table->unsignedBigInteger('target_id');
            
            // Action details
            $table->string('action', 100); // force_disconnect, ban_user, ban_whatsapp, dll
            $table->string('reason')->nullable(); // Alasan aksi
            $table->json('old_values')->nullable(); // Nilai sebelum perubahan
            $table->json('new_values')->nullable(); // Nilai setelah perubahan
            $table->json('metadata')->nullable(); // Data tambahan
            
            // Security info
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            // Status
            $table->enum('status', ['success', 'failed', 'pending'])->default('success');
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
