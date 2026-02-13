<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename plan_transactions.status enum value 'paid' → 'success'
 *
 * Alasan: Konsistensi dengan flow pembayaran Midtrans.
 * settlement/capture → status = 'success' (bukan 'paid')
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter enum: replace 'paid' with 'success'
        DB::statement("ALTER TABLE plan_transactions MODIFY COLUMN status ENUM('pending','waiting_payment','success','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending'");

        // 2. Migrate existing data
        DB::table('plan_transactions')
            ->where('status', 'paid')
            ->update(['status' => 'success']);
    }

    public function down(): void
    {
        // Revert data first
        DB::table('plan_transactions')
            ->where('status', 'success')
            ->update(['status' => 'paid']);

        // Revert enum
        DB::statement("ALTER TABLE plan_transactions MODIFY COLUMN status ENUM('pending','waiting_payment','paid','failed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending'");
    }
};
