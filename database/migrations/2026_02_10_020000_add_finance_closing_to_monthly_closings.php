<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Invoice-SSOT finance closing columns to monthly_closings.
     *
     * ALASAN:
     * - Tabel monthly_closings sudah ada (wallet-based)
     * - Sekarang tambah kolom revenue dari Invoice (SSOT)
     * - Wallet tetap untuk cross-check rekonsiliasi
     * - Invoice = sumber pendapatan, wallet = alat konsumsi
     */
    public function up(): void
    {
        if (!Schema::hasTable('monthly_closings')) {
            return;
        }

        Schema::table('monthly_closings', function (Blueprint $table) {
            // ==================== INVOICE REVENUE (SSOT) ====================
            if (!Schema::hasColumn('monthly_closings', 'invoice_count')) {
                $table->unsignedInteger('invoice_count')->default(0)->after('is_locked')
                      ->comment('Jumlah invoice PAID dalam periode');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_subscription_revenue')) {
                $table->decimal('invoice_subscription_revenue', 15, 2)->default(0)->after('invoice_count')
                      ->comment('Revenue subscription (dari invoice PAID)');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_topup_revenue')) {
                $table->decimal('invoice_topup_revenue', 15, 2)->default(0)->after('invoice_subscription_revenue')
                      ->comment('Revenue topup saldo (dari invoice PAID)');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_other_revenue')) {
                $table->decimal('invoice_other_revenue', 15, 2)->default(0)->after('invoice_topup_revenue')
                      ->comment('Revenue addon/upgrade/lainnya');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_total_ppn')) {
                $table->decimal('invoice_total_ppn', 15, 2)->default(0)->after('invoice_other_revenue')
                      ->comment('Total PPN dari semua invoice PAID');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_gross_revenue')) {
                $table->decimal('invoice_gross_revenue', 15, 2)->default(0)->after('invoice_total_ppn')
                      ->comment('Total gross revenue (semua DPP)');
            }
            if (!Schema::hasColumn('monthly_closings', 'invoice_net_revenue')) {
                $table->decimal('invoice_net_revenue', 15, 2)->default(0)->after('invoice_gross_revenue')
                      ->comment('Net revenue = gross - PPN');
            }

            // ==================== REKONSILIASI CROSS-CHECK ====================
            if (!Schema::hasColumn('monthly_closings', 'recon_wallet_topup')) {
                $table->decimal('recon_wallet_topup', 15, 2)->default(0)->after('invoice_net_revenue')
                      ->comment('Total topup completed di wallet_transactions (cross-check)');
            }
            if (!Schema::hasColumn('monthly_closings', 'recon_topup_discrepancy')) {
                $table->decimal('recon_topup_discrepancy', 15, 2)->default(0)->after('recon_wallet_topup')
                      ->comment('Selisih invoice topup vs wallet topup');
            }
            if (!Schema::hasColumn('monthly_closings', 'recon_wallet_usage')) {
                $table->decimal('recon_wallet_usage', 15, 2)->default(0)->after('recon_topup_discrepancy')
                      ->comment('Total usage di wallet_transactions');
            }
            if (!Schema::hasColumn('monthly_closings', 'recon_has_negative_balance')) {
                $table->boolean('recon_has_negative_balance')->default(false)->after('recon_wallet_usage')
                      ->comment('Ada wallet dengan saldo negatif?');
            }
            if (!Schema::hasColumn('monthly_closings', 'recon_status')) {
                $table->enum('recon_status', ['MATCH', 'MISMATCH', 'UNCHECKED'])
                      ->default('UNCHECKED')->after('recon_has_negative_balance')
                      ->comment('Status rekonsiliasi invoice vs wallet');
            }

            // ==================== FINANCE CLOSING SNAPSHOT ====================
            if (!Schema::hasColumn('monthly_closings', 'finance_revenue_snapshot')) {
                $table->json('finance_revenue_snapshot')->nullable()->after('recon_status')
                      ->comment('Breakdown revenue per tipe invoice');
            }
            if (!Schema::hasColumn('monthly_closings', 'finance_recon_details')) {
                $table->json('finance_recon_details')->nullable()->after('finance_revenue_snapshot')
                      ->comment('Detail cross-check rekonsiliasi');
            }
            if (!Schema::hasColumn('monthly_closings', 'finance_discrepancy_notes')) {
                $table->text('finance_discrepancy_notes')->nullable()->after('finance_recon_details')
                      ->comment('Catatan selisih / masalah rekonsiliasi');
            }

            // ==================== FINANCE CLOSING LOCK ====================
            if (!Schema::hasColumn('monthly_closings', 'finance_status')) {
                $table->enum('finance_status', ['DRAFT', 'CLOSED', 'FAILED'])
                      ->default('DRAFT')->after('finance_discrepancy_notes')
                      ->comment('Status closing keuangan: DRAFT|CLOSED|FAILED');
            }
            if (!Schema::hasColumn('monthly_closings', 'finance_closed_by')) {
                $table->foreignId('finance_closed_by')->nullable()->after('finance_status')
                      ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('monthly_closings', 'finance_closed_at')) {
                $table->timestamp('finance_closed_at')->nullable()->after('finance_closed_by');
            }
            if (!Schema::hasColumn('monthly_closings', 'finance_closing_hash')) {
                $table->string('finance_closing_hash', 64)->nullable()->after('finance_closed_at')
                      ->comment('SHA-256 hash snapshot saat closing keuangan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('monthly_closings', function (Blueprint $table) {
            $table->dropForeign(['finance_closed_by']);
            $table->dropColumn([
                'invoice_count',
                'invoice_subscription_revenue',
                'invoice_topup_revenue',
                'invoice_other_revenue',
                'invoice_total_ppn',
                'invoice_gross_revenue',
                'invoice_net_revenue',
                'recon_wallet_topup',
                'recon_topup_discrepancy',
                'recon_wallet_usage',
                'recon_has_negative_balance',
                'recon_status',
                'finance_revenue_snapshot',
                'finance_recon_details',
                'finance_discrepancy_notes',
                'finance_status',
                'finance_closed_by',
                'finance_closed_at',
                'finance_closing_hash',
            ]);
        });
    }
};
