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
        if (!Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete()
                  ->comment('Target user untuk notification');
            
            $table->string('type', 50)
                  ->default('alert')
                  ->comment('Type notification: alert, system, promo, etc.');
                  
            $table->string('title', 255)
                  ->comment('Notification title');
                  
            $table->text('message')
                  ->comment('Notification message');
                  
            $table->json('data')
                  ->nullable()
                  ->comment('Additional notification data');
                  
            $table->boolean('is_read')
                  ->default(false)
                  ->comment('Apakah sudah dibaca user');
                  
            $table->timestamp('read_at')
                  ->nullable()
                  ->comment('Waktu notification dibaca');
                  
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'is_read'], 'idx_user_notifications_user_read');
            $table->index(['user_id', 'created_at'], 'idx_user_notifications_user_time');
            $table->index(['type', 'created_at'], 'idx_user_notifications_type_time');
            $table->index(['is_read', 'created_at'], 'idx_user_notifications_read_time');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};