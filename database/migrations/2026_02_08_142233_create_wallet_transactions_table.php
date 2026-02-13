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
        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Denormalized for easy queries
            $table->string('type'); // topup, usage, adjustment, refund, bonus
            $table->decimal('amount', 15, 2); // Transaction amount (positive for credits, negative for debits)
            $table->decimal('balance_before', 15, 2); // Balance before transaction
            $table->decimal('balance_after', 15, 2); // Balance after transaction
            $table->string('currency', 3)->default('IDR');
            $table->string('description')->nullable(); // Human-readable description
            $table->string('reference_type')->nullable(); // Model class name (e.g., "App\Models\Payment")
            $table->string('reference_id')->nullable(); // Related model ID (payment_id, message_id, etc.)
            $table->json('metadata')->nullable(); // Additional data (payment gateway info, message details, etc.)
            $table->string('status')->default('completed'); // pending, completed, failed, cancelled
            $table->string('created_by_type')->nullable(); // User, System, Admin
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('processed_at')->nullable(); // When transaction was actually processed
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['wallet_id', 'type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
