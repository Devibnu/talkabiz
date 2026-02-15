<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-Active Subscription DB Guard
 * 
 * Adds a generated column + unique index to ensure that
 * at most ONE subscription per klien can be 'active' at any time.
 * 
 * HOW IT WORKS:
 * ─────────────
 * is_active_flag = IF(status = 'active', 1, NULL)  (STORED generated column)
 * UNIQUE(klien_id, is_active_flag)
 * 
 * MySQL UNIQUE allows multiple NULLs → expired/trial_selected rows are unconstrained.
 * But only ONE row per klien_id can have is_active_flag = 1.
 * 
 * ZERO RISK:
 * ──────────
 * - Pre-flight check: verify no dual-active rows before adding constraint
 * - Generated column is STORED → automatically maintained by MySQL
 * - No application code changes needed — MySQL enforces it transparently
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Pre-flight: verify no klien has multiple active subscriptions ──
        $dualActive = DB::select("
            SELECT klien_id, COUNT(*) as cnt
            FROM subscriptions
            WHERE status = 'active'
              AND deleted_at IS NULL
            GROUP BY klien_id
            HAVING cnt > 1
        ");

        if (!empty($dualActive)) {
            $klienIds = implode(', ', array_map(fn ($row) => $row->klien_id, $dualActive));
            throw new \RuntimeException(
                "BLOCKED: Found klien(s) with multiple active subscriptions: [{$klienIds}]. " .
                "Fix data before running this migration."
            );
        }

        // ── Step 1: Add generated column ──
        // IF status = 'active' → 1, else NULL
        // STORED so it's physically written and can be indexed
        DB::statement("
            ALTER TABLE subscriptions
            ADD COLUMN is_active_flag TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(status = 'active', 1, NULL)) STORED
                AFTER status
        ");

        // ── Step 2: Add unique index ──
        // UNIQUE(klien_id, is_active_flag) → only one active per klien
        DB::statement("
            CREATE UNIQUE INDEX subscriptions_klien_single_active
            ON subscriptions (klien_id, is_active_flag)
        ");
    }

    public function down(): void
    {
        // Drop unique index first
        $indexExists = DB::select("
            SHOW INDEX FROM subscriptions WHERE Key_name = 'subscriptions_klien_single_active'
        ");

        if (!empty($indexExists)) {
            DB::statement("DROP INDEX subscriptions_klien_single_active ON subscriptions");
        }

        // Drop generated column
        if (Schema::hasColumn('subscriptions', 'is_active_flag')) {
            DB::statement("ALTER TABLE subscriptions DROP COLUMN is_active_flag");
        }
    }
};
