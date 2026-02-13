<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table: reconciliation_logs — Log hasil rekonsiliasi bulanan
     *
     * Setiap record = 1 proses rekonsiliasi untuk 1 periode + 1 source.
     * Source: 'gateway' atau 'bank'
     *
     * ATURAN:
     * - UNIQUE per (period_year, period_month, source)
     * - Setelah Monthly Closing CLOSED → tidak bisa diubah
     * - Semua selisih dicatat, TIDAK dihapus
     */
    public function up(): void
    {
        if (!Schema::hasTable('reconciliation_logs')) {
            Schema::create('reconciliation_logs', function (Blueprint $table) {
            $table->id();

            // Period
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period_key', 7); // "2026-02"

            // Source
            $table->enum('source', ['gateway', 'bank']);

            // Amounts
            $table->decimal('total_expected', 15, 2)->default(0);  // Invoice PAID total
            $table->decimal('total_actual', 15, 2)->default(0);    // Gateway/Bank actual
            $table->decimal('difference', 15, 2)->default(0);      // expected - actual
            $table->unsignedInteger('total_expected_count')->default(0);
            $table->unsignedInteger('total_actual_count')->default(0);

            // Unmatched details
            $table->unsignedInteger('unmatched_invoice_count')->default(0);
            $table->unsignedInteger('unmatched_payment_count')->default(0);
            $table->unsignedInteger('double_payment_count')->default(0);

            // Status
            $table->enum('status', ['MATCH', 'PARTIAL_MATCH', 'MISMATCH'])->default('MISMATCH');

            // Details (JSON)
            $table->json('unmatched_invoices')->nullable();   // Invoice PAID tanpa payment
            $table->json('unmatched_payments')->nullable();   // Payment tanpa invoice
            $table->json('amount_mismatches')->nullable();    // Invoice vs payment amount mismatch
            $table->json('double_payments')->nullable();      // Invoice punya >1 payment success
            $table->json('summary_snapshot')->nullable();     // Full snapshot saat reconcile

            // Notes & metadata
            $table->text('notes')->nullable();
            $table->text('discrepancy_notes')->nullable();

            // Lock
            $table->boolean('is_locked')->default(false);
            $table->foreignId('reconciled_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();

            // Integrity
            $table->string('recon_hash', 64)->nullable(); // SHA-256

            $table->timestamps();

            // UNIQUE constraint
            $table->unique(['period_year', 'period_month', 'source'], 'recon_period_source_unique');

            // Indexes
            $table->index(['period_year', 'period_month']);
            $table->index('status');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_logs');
    }
};
