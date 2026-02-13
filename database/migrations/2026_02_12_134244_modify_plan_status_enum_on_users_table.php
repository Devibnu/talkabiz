<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Modify plan_status ENUM pada tabel users.
     *
     * Old ENUM : ['active', 'expired', 'cancelled', 'pending']
     * New ENUM : ['trial_selected', 'active', 'expired']
     *
     * - Konversi 'pending'   → 'trial_selected'
     * - Konversi 'cancelled' → 'expired'
     * - Default = 'trial_selected'
     * - Tidak drop kolom, tidak hapus data
     */
    public function up(): void
    {
        // 1. Migrasi data lama ke value baru sebelum alter ENUM
        //    Harus dilakukan SEBELUM alter karena alter akan reject value lama.
        DB::table('users')
            ->where('plan_status', 'pending')
            ->update(['plan_status' => 'active']); // sementara ke 'active' (valid di old & new)

        DB::table('users')
            ->where('plan_status', 'cancelled')
            ->update(['plan_status' => 'expired']);

        // 2. Alter ENUM ke value baru
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN plan_status ENUM('trial_selected','active','expired')
            NOT NULL DEFAULT 'trial_selected'
        ");

        // 3. Sekarang ubah 'pending' (yang sudah jadi 'active' di step 1) ke 'trial_selected'
        //    Hanya yang BELUM pernah bayar — plan_expires_at IS NULL
        DB::table('users')
            ->where('plan_status', 'active')
            ->whereNull('plan_expires_at')
            ->update(['plan_status' => 'trial_selected']);
    }

    /**
     * Rollback: kembalikan ENUM ke value lama.
     */
    public function down(): void
    {
        // Konversi trial_selected → pending (value lama)
        DB::table('users')
            ->where('plan_status', 'trial_selected')
            ->update(['plan_status' => 'active']); // sementara

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN plan_status ENUM('active','expired','cancelled','pending')
            NOT NULL DEFAULT 'active'
        ");

        // Kembalikan yang tadinya trial_selected → pending
        DB::table('users')
            ->where('plan_status', 'active')
            ->whereNull('plan_expires_at')
            ->update(['plan_status' => 'pending']);
    }
};
