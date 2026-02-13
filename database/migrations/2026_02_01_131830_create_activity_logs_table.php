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
        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('causer_id')->nullable()->comment('Who performed the action');
            $table->string('causer_type')->nullable()->default('App\\Models\\User');
            $table->string('action', 100)->index(); // login, logout, password_reset, etc.
            $table->string('subject_type')->nullable(); // Model class
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->json('properties')->nullable(); // Extra data
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
