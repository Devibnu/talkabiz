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
        if (!Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('balance', 15, 2)->default(0); // Current balance in IDR
            $table->decimal('total_topup', 15, 2)->default(0); // Total topup amount (lifetime)
            $table->decimal('total_spent', 15, 2)->default(0); // Total spent amount (lifetime)
            $table->string('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_topup_at')->nullable();
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'is_active']);
            $table->unique('user_id'); // One wallet per user
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
