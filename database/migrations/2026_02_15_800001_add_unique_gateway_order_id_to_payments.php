<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * HARDENING PATCH: Add UNIQUE index on payments.gateway_order_id
 * 
 * Prevents duplicate payment rows from concurrent Midtrans webhooks.
 * Pre-flight: abort if duplicates exist (data integrity check).
 * 
 * MySQL allows multiple NULLs in a unique index — safe for nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-flight: check for existing duplicates
        $duplicates = DB::select("
            SELECT gateway_order_id, COUNT(*) as cnt 
            FROM payments 
            WHERE gateway_order_id IS NOT NULL 
            GROUP BY gateway_order_id 
            HAVING cnt > 1
        ");

        if (count($duplicates) > 0) {
            $ids = collect($duplicates)->pluck('gateway_order_id')->implode(', ');
            throw new \RuntimeException(
                "Cannot create unique index: duplicate gateway_order_id values found: [{$ids}]. "
                . "Clean up duplicates before running this migration."
            );
        }

        // Drop the old non-unique index if present, then add unique
        $indexes = DB::select("SHOW INDEX FROM payments WHERE Key_name = 'payments_gateway_order_id_index'");
        if (count($indexes) > 0) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_gateway_order_id_index');
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('gateway_order_id', 'payments_gateway_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_gateway_order_id_unique');
        });

        // Restore original non-unique index
        Schema::table('payments', function (Blueprint $table) {
            $table->index('gateway_order_id', 'payments_gateway_order_id_index');
        });
    }
};
