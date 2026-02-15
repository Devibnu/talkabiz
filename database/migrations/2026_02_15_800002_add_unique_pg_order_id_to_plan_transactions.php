<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * HARDENING PATCH: Add UNIQUE index on plan_transactions.pg_order_id
 * 
 * Prevents duplicate transaction rows from concurrent Midtrans webhooks.
 * Pre-flight: abort if duplicates exist (data integrity check).
 * 
 * MySQL allows multiple NULLs in a unique index — safe for the 2 NULL rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: check for existing duplicates
        $duplicates = DB::select("
            SELECT pg_order_id, COUNT(*) as cnt 
            FROM plan_transactions 
            WHERE pg_order_id IS NOT NULL 
            GROUP BY pg_order_id 
            HAVING cnt > 1
        ");

        if (count($duplicates) > 0) {
            $ids = collect($duplicates)->pluck('pg_order_id')->implode(', ');
            throw new \RuntimeException(
                "Cannot create unique index: duplicate pg_order_id values found: [{$ids}]. "
                . "Clean up duplicates before running this migration."
            );
        }

        // Drop existing non-unique index if present, then add unique
        $indexes = DB::select("SHOW INDEX FROM plan_transactions WHERE Key_name = 'plan_transactions_pg_order_id_index'");
        if (count($indexes) > 0) {
            Schema::table('plan_transactions', function (Blueprint $table) {
                $table->dropIndex('plan_transactions_pg_order_id_index');
            });
        }

        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->unique('pg_order_id', 'plan_transactions_pg_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->dropUnique('plan_transactions_pg_order_id_unique');
        });

        // Restore original non-unique index
        Schema::table('plan_transactions', function (Blueprint $table) {
            $table->index('pg_order_id', 'plan_transactions_pg_order_id_index');
        });
    }
};
