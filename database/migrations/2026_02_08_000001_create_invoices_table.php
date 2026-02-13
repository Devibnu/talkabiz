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
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('klien_id')->nullable()->index();
            
            // Invoice Details
            $table->enum('type', ['topup', 'subscription', 'addon'])->default('topup')->index();
            $table->decimal('amount', 12, 2); // Nominal dalam rupiah
            $table->decimal('admin_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2); // amount + admin_fee
            $table->string('currency', 3)->default('IDR');
            
            // Status & Payment
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'refunded'])->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            
            // Payment Gateway Integration
            $table->string('payment_method')->nullable(); // midtrans, xendit, manual, etc
            $table->string('payment_gateway_id')->nullable()->index(); // External payment ID
            $table->json('gateway_response')->nullable(); // Raw response dari gateway
            
            // Metadata & References
            $table->json('metadata')->nullable(); // Additional data
            $table->string('description')->nullable();
            $table->string('reference_id')->nullable()->index(); // External reference
            
            // Audit & Security
            $table->string('created_by_ip', 45)->nullable();
            $table->string('paid_by_ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            
            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['klien_id', 'type', 'status']);
            $table->index(['status', 'expired_at']);
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};