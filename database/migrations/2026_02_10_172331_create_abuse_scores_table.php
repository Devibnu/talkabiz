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
        Schema::create('abuse_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klien_id')->unique();
            $table->decimal('current_score', 10, 2)->default(0.00);
            $table->enum('abuse_level', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->enum('policy_action', ['none', 'throttle', 'require_approval', 'suspend'])->default('none');
            $table->boolean('is_suspended')->default(false);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_decay_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign key
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');

            // Indexes
            $table->index('abuse_level');
            $table->index('policy_action');
            $table->index('is_suspended');
            $table->index(['abuse_level', 'is_suspended']);
            $table->index('last_event_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abuse_scores');
    }
};
