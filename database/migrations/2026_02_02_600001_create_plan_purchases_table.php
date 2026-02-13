<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: plan_purchases - Untuk track pembelian plan/subscription
     */
    public function up(): void
    {
        // Skip jika tabel sudah ada
        if (Schema::hasTable('plan_purchases')) {
            return;
        }

        Schema::create('plan_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->onDelete('set null');
            $table->string('plan_name')->nullable(); // Backup nama plan
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('IDR');
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'refunded'])->default('pending');
            $table->string('payment_gateway')->nullable(); // midtrans, xendit, manual
            $table->string('payment_reference')->nullable(); // External reference ID
            $table->string('invoice_number')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->json('metadata')->nullable(); // Additional data
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes untuk query cepat
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_purchases');
    }
};
