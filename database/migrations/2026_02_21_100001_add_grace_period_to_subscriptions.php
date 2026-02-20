<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grace Period — Add 'grace' status to subscriptions
 * 
 * Business Rule:
 *   active → grace (3 days) → expired
 *   Users in grace period keep full access but see warning banner.
 * 
 * Changes:
 *   1. Add 'grace' to status ENUM → ('trial_selected', 'active', 'grace', 'expired')
 *   2. Add grace_ends_at nullable TIMESTAMP column
 *   3. Update is_active_flag generated column: IF(status IN ('active','grace'), 1, NULL)
 *      Grace counts as "active" for the single-active unique guard.
 *   4. Recreate unique index on (klien_id, is_active_flag)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Expand ENUM to include 'grace' ──
        DB::statement("
            ALTER TABLE subscriptions 
            MODIFY COLUMN status ENUM('trial_selected', 'active', 'grace', 'expired') 
            NOT NULL DEFAULT 'trial_selected'
        ");

        // ── Step 2: Add grace_ends_at column ──
        if (!Schema::hasColumn('subscriptions', 'grace_ends_at')) {
            DB::statement("
                ALTER TABLE subscriptions 
                ADD COLUMN grace_ends_at TIMESTAMP NULL DEFAULT NULL 
                AFTER expires_at
            ");
        }

        // ── Step 3: Drop existing unique index + generated column ──
        $indexExists = DB::select("
            SHOW INDEX FROM subscriptions WHERE Key_name = 'subscriptions_klien_single_active'
        ");
        if (!empty($indexExists)) {
            DB::statement("DROP INDEX subscriptions_klien_single_active ON subscriptions");
        }

        if (Schema::hasColumn('subscriptions', 'is_active_flag')) {
            DB::statement("ALTER TABLE subscriptions DROP COLUMN is_active_flag");
        }

        // ── Step 4: Recreate generated column with grace included ──
        DB::statement("
            ALTER TABLE subscriptions
            ADD COLUMN is_active_flag TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(status IN ('active', 'grace'), 1, NULL)) STORED
                AFTER status
        ");

        // ── Step 5: Recreate unique index ──
        DB::statement("
            CREATE UNIQUE INDEX subscriptions_klien_single_active
            ON subscriptions (klien_id, is_active_flag)
        ");
    }

    public function down(): void
    {
        // ── Reverse Step 5: Drop unique index ──
        $indexExists = DB::select("
            SHOW INDEX FROM subscriptions WHERE Key_name = 'subscriptions_klien_single_active'
        ");
        if (!empty($indexExists)) {
            DB::statement("DROP INDEX subscriptions_klien_single_active ON subscriptions");
        }

        // ── Reverse Step 4: Drop updated generated column ──
        if (Schema::hasColumn('subscriptions', 'is_active_flag')) {
            DB::statement("ALTER TABLE subscriptions DROP COLUMN is_active_flag");
        }

        // ── Restore original generated column (active only) ──
        DB::statement("
            ALTER TABLE subscriptions
            ADD COLUMN is_active_flag TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(status = 'active', 1, NULL)) STORED
                AFTER status
        ");

        DB::statement("
            CREATE UNIQUE INDEX subscriptions_klien_single_active
            ON subscriptions (klien_id, is_active_flag)
        ");

        // ── Reverse Step 2: Drop grace_ends_at ──
        if (Schema::hasColumn('subscriptions', 'grace_ends_at')) {
            DB::statement("ALTER TABLE subscriptions DROP COLUMN grace_ends_at");
        }

        // ── Reverse Step 1: Move any 'grace' rows to 'expired' first ──
        DB::statement("UPDATE subscriptions SET status = 'expired' WHERE status = 'grace'");

        // ── Shrink ENUM back to 3 values ──
        DB::statement("
            ALTER TABLE subscriptions 
            MODIFY COLUMN status ENUM('trial_selected', 'active', 'expired') 
            NOT NULL DEFAULT 'trial_selected'
        ");
    }
};
