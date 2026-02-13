<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table: payment_transactions - Log semua transaksi payment gateway
     */
    public function up(): void
    {
        // Skip jika tabel sudah ada
        if (Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('gateway'); // midtrans, xendit
            $table->string('reference_id')->unique(); // Order ID / External ID
            $table->string('gateway_transaction_id')->nullable(); // ID dari payment gateway
            $table->enum('type', ['topup', 'plan_purchase', 'refund', 'other'])->default('topup');
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0); // Payment gateway fee
            $table->decimal('net_amount', 15, 2)->default(0); // Amount - Fee
            $table->string('currency', 10)->default('IDR');
            $table->enum('status', [
                'pending',
                'processing',
                'success',
                'failed',
                'expired',
                'cancelled',
                'refunded'
            ])->default('pending');
            $table->string('payment_method')->nullable(); // bank_transfer, qris, gopay, etc
            $table->string('payment_channel')->nullable(); // bca, bni, mandiri, etc
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->json('gateway_response')->nullable(); // Full response from gateway
            $table->json('metadata')->nullable(); // Additional data
            $table->text('failure_reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['gateway', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('gateway_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
