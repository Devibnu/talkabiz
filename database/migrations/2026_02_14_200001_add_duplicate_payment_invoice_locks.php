<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PHASE 3 — Duplicate Payment & Invoice Lock (Production Safe)
 * 
 * DATABASE HARD LOCK:
 * 1. subscription_invoices: tambah transaction_code (unique), composite index (user_id, plan_id, status)
 * 2. plan_transactions: idempotency_key sudah unique ✅ (no change)
 * 3. subscriptions: tambah composite index (klien_id, status)
 * 4. Cleanup duplicate pending invoices (keep terbaru, expire sisanya)
 * 
 * BACKWARD SAFE:
 * - Tidak menghapus data paid
 * - Tidak mengubah kolom existing
 * - Hanya menambah index + cleanup data duplikat
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // 1. subscription_invoices — tambah transaction_code + indexes
        // ================================================================
        Schema::table('subscription_invoices', function (Blueprint $table) {
            // Tambah transaction_code untuk lookup langsung (nullable karena existing rows)
            if (!Schema::hasColumn('subscription_invoices', 'transaction_code')) {
                $table->string('transaction_code', 50)
                      ->nullable()
                      ->unique()
                      ->after('invoice_number')
                      ->comment('Transaction code dari PlanTransaction untuk lookup');
            }

            // Composite index: cari pending invoice per user + plan (STEP 2 query)
            $table->index(
                ['user_id', 'plan_id', 'status'],
                'si_user_plan_status_idx'
            );
        });

        // ================================================================
        // 2. plan_transactions — idempotency_key sudah UNIQUE ✅
        //    transaction_code sudah UNIQUE ✅
        //    Tidak ada perubahan.
        // ================================================================

        // ================================================================
        // 3. subscriptions — tambah composite index (klien_id, status)
        //    Note: subscriptions pakai klien_id (bukan user_id)
        // ================================================================
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(
                ['klien_id', 'status'],
                'sub_klien_status_idx'
            );
        });

        // ================================================================
        // 4. Cleanup duplicate pending invoices
        //    Jika ada >1 pending invoice untuk user + plan:
        //    - Keep yang terbaru (MAX id)
        //    - Sisanya update status = 'expired'
        //    - JANGAN sentuh invoice yang sudah 'paid'
        // ================================================================
        $this->cleanupDuplicatePendingInvoices();

        // ================================================================
        // 5. Backfill transaction_code dari PlanTransaction
        // ================================================================
        $this->backfillTransactionCodes();
    }

    /**
     * Cleanup duplicate pending invoices.
     * Per user + plan, keep only the newest pending invoice.
     */
    protected function cleanupDuplicatePendingInvoices(): void
    {
        $duplicates = DB::table('subscription_invoices')
            ->select('user_id', 'plan_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->groupBy('user_id', 'plan_id')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        $expiredCount = 0;

        foreach ($duplicates as $dup) {
            $affected = DB::table('subscription_invoices')
                ->where('user_id', $dup->user_id)
                ->where('plan_id', $dup->plan_id)
                ->where('status', 'pending')
                ->where('id', '!=', $dup->keep_id)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'expired',
                    'notes' => 'Auto-expired: duplicate pending invoice cleanup (Phase 3 migration)',
                    'updated_at' => now(),
                ]);

            $expiredCount += $affected;
        }

        if ($expiredCount > 0) {
            Log::info("[Phase 3 Migration] Cleaned up {$expiredCount} duplicate pending invoices across " . $duplicates->count() . " user+plan combinations.");
        }
    }

    /**
     * Backfill transaction_code from PlanTransaction to SubscriptionInvoice.
     */
    protected function backfillTransactionCodes(): void
    {
        $updated = DB::statement("
            UPDATE subscription_invoices si
            INNER JOIN plan_transactions pt ON pt.id = si.plan_transaction_id
            SET si.transaction_code = pt.transaction_code
            WHERE si.transaction_code IS NULL
              AND si.plan_transaction_id IS NOT NULL
        ");

        $count = DB::table('subscription_invoices')
            ->whereNotNull('transaction_code')
            ->count();

        if ($count > 0) {
            Log::info("[Phase 3 Migration] Backfilled transaction_code for {$count} invoices.");
        }
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropIndex('si_user_plan_status_idx');

            if (Schema::hasColumn('subscription_invoices', 'transaction_code')) {
                $table->dropUnique(['transaction_code']);
                $table->dropColumn('transaction_code');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('sub_klien_status_idx');
        });

        // Note: cleaned up invoices NOT reverted (backward safe — expired → expired)
    }
};
