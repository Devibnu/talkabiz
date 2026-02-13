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
        if (!Schema::hasTable('saldo_ledger')) {
            Schema::create('saldo_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('ledger_id', 50)->unique()->index(); // Unique ledger identifier
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('klien_id')->nullable()->index();
            
            // Transaction Details
            $table->enum('type', [
                'topup',           // Credit dari invoice payment
                'debit_message',   // Debit untuk kirim pesan
                'refund',          // Refund message yang gagal
                'adjustment',      // Manual adjustment (admin only)
                'bonus',           // Bonus credit
                'penalty'          // Penalty debit
            ])->index();
            
            $table->enum('direction', ['credit', 'debit'])->index();
            $table->decimal('amount', 12, 2); // Positive value, direction determines +/-
            
            // Balance Tracking (CRITICAL untuk audit)
            $table->decimal('balance_before', 12, 2); // Saldo sebelum transaksi
            $table->decimal('balance_after', 12, 2); // Saldo setelah transaksi
            
            // References & Sources
            $table->string('reference_type')->nullable()->index(); // invoice, message_dispatch, etc
            $table->string('reference_id')->nullable()->index(); // ID dari reference
            $table->unsignedBigInteger('invoice_id')->nullable()->index(); // Link to invoice
            $table->string('transaction_code')->nullable()->index(); // Link to message transaction
            
            // Metadata & Description
            $table->text('description');
            $table->json('metadata')->nullable(); // Additional context
            
            // Audit & Security
            $table->string('created_by_ip', 45)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index(); // Who created this entry
            $table->timestamp('processed_at'); // When this was processed
            $table->timestamp('created_at');
            
            // Immutable - NO updated_at (tidak boleh diubah)
            
            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Critical indexes for balance calculation
            $table->index(['user_id', 'processed_at', 'id']); // For balance calculation
            $table->index(['klien_id', 'processed_at', 'id']);
            $table->index(['type', 'direction', 'processed_at']);
            $table->index(['reference_type', 'reference_id']);
            // transaction_code index already added inline above
            
            // Composite index for fast balance queries
            $table->index(['user_id', 'type', 'processed_at']);
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_ledger');
    }
};