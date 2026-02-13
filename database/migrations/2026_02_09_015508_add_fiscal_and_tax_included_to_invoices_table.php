<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan kolom fiskal & tax_included untuk sistem pajak resmi.
     *
     * Kolom yang sudah ada (dari migrasi sebelumnya):
     *   tax_rate, tax_type, tax_amount, subtotal, tax_calculation
     *
     * Yang ditambahkan di sini:
     *   tax_included  → boolean flag (pajak termasuk / tidak)
     *   fiscal_year   → tahun fiskal invoice
     *   fiscal_month  → bulan fiskal invoice
     */
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'tax_included')) {
                $table->boolean('tax_included')->default(false)
                      ->after('tax_type')
                      ->comment('false = exclusive (DPP + PPN), true = inclusive');
            }

            if (!Schema::hasColumn('invoices', 'fiscal_year')) {
                $table->unsignedSmallInteger('fiscal_year')->nullable()
                      ->after('tax_included')
                      ->comment('Tahun fiskal, e.g. 2026');
            }

            if (!Schema::hasColumn('invoices', 'fiscal_month')) {
                $table->unsignedTinyInteger('fiscal_month')->nullable()
                      ->after('fiscal_year')
                      ->comment('Bulan fiskal 1-12');
            }
        });

        // Add indexes safely
        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['fiscal_year', 'fiscal_month'], 'idx_invoices_fiscal_period');
                $table->index(['fiscal_year', 'fiscal_month', 'type'], 'idx_invoices_fiscal_type');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_fiscal_period');
            $table->dropIndex('idx_invoices_fiscal_type');
            $table->dropColumn(['tax_included', 'fiscal_year', 'fiscal_month']);
        });
    }
};
