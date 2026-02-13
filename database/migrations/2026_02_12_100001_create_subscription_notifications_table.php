<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->enum('type', ['t7', 't3', 't1', 'expired'])->comment('T-7, T-3, T-1, or expired');
            $table->enum('channel', ['email', 'whatsapp']);
            $table->date('sent_date')->comment('Date component of sent_at for unique constraint');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Prevent duplicate notifications per user/type/channel/day
            $table->unique(
                ['user_id', 'type', 'channel', 'sent_date'],
                'sub_notif_unique_per_day'
            );

            // Performance indexes
            $table->index(['user_id', 'type']);
            $table->index(['sent_at']);
            $table->index(['subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notifications');
    }
};
