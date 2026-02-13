<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table: bank_statements — Mutasi bank (import/manual/API)
     *
     * Digunakan untuk rekonsiliasi:
     * Invoice (PAID) → Payment Gateway → Bank Statement
     *
     * ATURAN:
     * - Setiap record = 1 baris mutasi bank
     * - reference nullable (tidak semua mutasi punya reference)
     * - matched_payment_id = link ke payments table jika sudah match
     * - match_status = tracking proses matching
     */
    public function up(): void
    {
        if (!Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();

            // Bank info
            $table->string('bank_name', 100);          // BCA, BNI, Mandiri, dll
            $table->string('bank_account', 50)->nullable(); // No rekening

            // Transaction detail
            $table->date('trx_date');                   // Tanggal mutasi
            $table->decimal('amount', 15, 2);           // Jumlah (positif = masuk, negatif = keluar)
            $table->enum('trx_type', ['credit', 'debit'])->default('credit'); // credit=masuk, debit=keluar
            $table->text('description')->nullable();    // Keterangan bank
            $table->string('reference', 255)->nullable(); // Reference/Berita transfer

            // Matching
            $table->foreignId('matched_payment_id')->nullable()
                  ->constrained('payments')->nullOnDelete();
            $table->enum('match_status', [
                'unmatched',
                'matched',
                'partial',
                'disputed',
            ])->default('unmatched');
            $table->text('match_notes')->nullable();

            // Import metadata
            $table->enum('import_source', ['manual', 'csv', 'api'])->default('manual');
            $table->string('import_batch_id', 100)->nullable(); // Group import
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            // Integrity
            $table->string('statement_hash', 64)->nullable(); // SHA-256

            $table->timestamps();

            // Indexes
            $table->index(['bank_name', 'trx_date']);
            $table->index(['trx_date', 'amount']);
            $table->index('match_status');
            $table->index('reference');
            $table->index('import_batch_id');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
