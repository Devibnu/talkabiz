<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix Foreign Keys: pengguna → users
 * 
 * MASALAH:
 * - plan_transactions.created_by FK → pengguna.id
 * - plan_transactions.processed_by FK → pengguna.id
 * - user_plans.assigned_by FK → pengguna.id
 * 
 * Tetapi auth system menggunakan App\Models\User (tabel users),
 * sehingga auth()->id() menghasilkan users.id.
 * Insert gagal karena FK constraint violation.
 * 
 * SOLUSI:
 * - Drop FK lama ke pengguna
 * - Recreate FK ke users.id
 * - ON DELETE SET NULL dipertahankan
 * - Data existing dipertahankan (tidak ada data loss)
 * 
 * AMAN:
 * - Hanya mengubah FK constraint, bukan data/kolom
 * - Kolom tetap unsignedBigInteger nullable
 * - Existing data tetap utuh
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // 1. plan_transactions.created_by: pengguna → users
        // =====================================================
        Schema::table('plan_transactions', function (Blueprint $table) {
            // Drop old FK to pengguna
            $table->dropForeign(['created_by']);
        });

        Schema::table('plan_transactions', function (Blueprint $table) {
            // Recreate FK to users  
            $table->foreign('created_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });

        Log::info('Migration: plan_transactions.created_by FK changed pengguna → users');

        // =====================================================
        // 2. plan_transactions.processed_by: pengguna → users
        // =====================================================
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
        });

        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->foreign('processed_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });

        Log::info('Migration: plan_transactions.processed_by FK changed pengguna → users');

        // =====================================================
        // 3. user_plans.assigned_by: pengguna → users
        // =====================================================
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
        });

        Schema::table('user_plans', function (Blueprint $table) {
            $table->foreign('assigned_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });

        Log::info('Migration: user_plans.assigned_by FK changed pengguna → users');
    }

    public function down(): void
    {
        // Rollback: Kembalikan FK ke pengguna (original state)

        // 1. plan_transactions.created_by
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->foreign('created_by')
                  ->references('id')
                  ->on('pengguna')
                  ->onDelete('set null');
        });

        // 2. plan_transactions.processed_by
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
        });
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->foreign('processed_by')
                  ->references('id')
                  ->on('pengguna')
                  ->onDelete('set null');
        });

        // 3. user_plans.assigned_by
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
        });
        Schema::table('user_plans', function (Blueprint $table) {
            $table->foreign('assigned_by')
                  ->references('id')
                  ->on('pengguna')
                  ->onDelete('set null');
        });
    }
};
