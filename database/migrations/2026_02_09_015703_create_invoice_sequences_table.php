<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table for atomic invoice number sequence.
     *
     * Sequence resets setiap bulan. Satu row per (prefix, year, month).
     * Update menggunakan DB::transaction + lockForUpdate → no gaps.
     *
     * Format: TBZ/INV/2026/02/000123
     */
    public function up(): void
    {
        if (!Schema::hasTable('invoice_sequences')) {
            Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 30)->default('TBZ/INV')
                  ->comment('Invoice prefix, e.g. TBZ/INV');
            $table->unsignedSmallInteger('year')
                  ->comment('Fiscal year, e.g. 2026');
            $table->unsignedTinyInteger('month')
                  ->comment('Fiscal month 1-12');
            $table->unsignedInteger('last_sequence')->default(0)
                  ->comment('Last used sequence number');
            $table->timestamps();

            // Satu row per (prefix, year, month) — UNIQUE
            $table->unique(['prefix', 'year', 'month'], 'uq_invoice_seq_prefix_period');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
