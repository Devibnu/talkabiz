<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel tax_reports — 1 row per bulan untuk laporan PPN.
     *
     * ATURAN:
     * - Data HANYA dari invoices (status=PAID, tax_type=PPN)
     * - TIDAK dari wallet_transactions
     * - Bisa di-generate ulang (regeneratable)
     * - Unique constraint (year, month) → 1 laporan per periode
     */
    public function up(): void
    {
        if (!Schema::hasTable('tax_reports')) {
            Schema::create('tax_reports', function (Blueprint $table) {
            $table->id();

            // Periode fiskal
            $table->unsignedSmallInteger('year')->comment('Tahun fiskal');
            $table->unsignedTinyInteger('month')->comment('Bulan fiskal (1-12)');

            // Aggregated totals
            $table->unsignedInteger('total_invoices')->default(0)->comment('Jumlah invoice PAID + PPN');
            $table->decimal('total_dpp', 15, 2)->default(0)->comment('Total DPP (Dasar Pengenaan Pajak)');
            $table->decimal('total_ppn', 15, 2)->default(0)->comment('Total PPN terkumpul');
            $table->decimal('total_amount', 15, 2)->default(0)->comment('Total bruto (DPP + PPN)');
            $table->decimal('tax_rate', 5, 2)->default(11)->comment('Tarif PPN pada saat generate');

            // Status & audit
            $table->enum('status', ['draft', 'final'])->default('draft')->comment('draft=bisa re-generate, final=locked');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete()->comment('User yang generate');
            $table->timestamp('generated_at')->nullable()->comment('Waktu terakhir di-generate');
            $table->timestamp('finalized_at')->nullable()->comment('Waktu di-finalize');

            // Metadata & integrity
            $table->json('metadata')->nullable()->comment('Data tambahan: breakdown per tipe, dll');
            $table->string('report_hash', 64)->nullable()->comment('SHA-256 hash for integrity check');

            $table->timestamps();

            // 1 laporan per bulan
            $table->unique(['year', 'month'], 'tax_reports_period_unique');

            // Index untuk query cepat
            $table->index('status');
            $table->index('generated_at');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_reports');
    }
};
