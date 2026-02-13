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
        if (!Schema::hasTable('reconciliation_logs')) {
            Schema::create('reconciliation_logs', function (Blueprint $table) {
            $table->id();
            
            // ==================== PERIOD INFO ====================
            $table->integer('period_year');
            $table->integer('period_month');
            $table->string('period_key', 20); // YYYY-MM
            
            // ==================== SOURCE ====================
            $table->enum('source', ['gateway', 'bank'])->comment('Gateway = invoice vs payment | Bank = payment vs bank_statement');
            
            // ==================== RECONCILIATION AMOUNTS ====================
            $table->decimal('total_expected', 15, 2)->default(0)->comment('Expected amount (from invoices or payments)');
            $table->decimal('total_actual', 15, 2)->default(0)->comment('Actual amount (from payments or bank)');
            $table->decimal('difference', 15, 2)->default(0)->comment('Difference (expected - actual)');
            
            // ==================== RECONCILIATION COUNTS ====================
            $table->integer('total_expected_count')->default(0)->comment('Count of expected records');
            $table->integer('total_actual_count')->default(0)->comment('Count of actual records');
            $table->integer('unmatched_invoice_count')->default(0)->comment('Invoices without payment');
            $table->integer('unmatched_payment_count')->default(0)->comment('Payments without invoice/bank');
            $table->integer('double_payment_count')->default(0)->comment('Invoices with multiple payments');
            
            // ==================== STATUS ====================
            $table->enum('status', ['MATCH', 'PARTIAL_MATCH', 'MISMATCH'])->default('MATCH');
            
            // ==================== DISCREPANCY DETAILS (JSON) ====================
            $table->json('unmatched_invoices')->nullable()->comment('Array of invoice IDs without payment');
            $table->json('unmatched_payments')->nullable()->comment('Array of payment IDs without match');
            $table->json('amount_mismatches')->nullable()->comment('Array of records with amount difference');
            $table->json('double_payments')->nullable()->comment('Array of invoices with >1 payment');
            $table->json('summary_snapshot')->nullable()->comment('Full reconciliation data snapshot');
            
            // ==================== NOTES ====================
            $table->text('notes')->nullable()->comment('General notes');
            $table->text('discrepancy_notes')->nullable()->comment('Specific discrepancy notes');
            
            // ==================== OWNERSHIP & LOCKING ====================
            $table->boolean('is_locked')->default(false)->comment('Locked after Monthly Closing');
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('recon_hash')->nullable()->comment('SHA-256 hash for integrity check');
            
            $table->timestamps();
            
            // ==================== INDEXES & CONSTRAINTS ====================
            $table->unique(['period_year', 'period_month', 'source'], 'unique_period_source');
            $table->index(['period_year', 'period_month']);
            $table->index(['source', 'status']);
            $table->index(['period_key']);
            $table->foreign('reconciled_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_logs');
    }
};
