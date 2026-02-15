<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Revenue Lock Phase 1 — Lock subscription status ke 3 status saja:
 *   - trial_selected (belum bayar)
 *   - active (invoice paid + transaction success + belum expired)
 *   - expired (sudah expired / cancelled / replaced)
 * 
 * Mapping dari status lama:
 *   pending    → trial_selected
 *   active     → active (tetap)
 *   expired    → expired (tetap)
 *   cancelled  → expired
 *   replaced   → expired (via soft_delete)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Migrate existing data to new status values
        DB::statement("UPDATE subscriptions SET status = 'expired' WHERE status IN ('cancelled')");
        
        // Step 2: For 'replaced' — soft delete and set expired
        DB::statement("UPDATE subscriptions SET status = 'expired', deleted_at = NOW() WHERE status = 'replaced'");
        
        // Step 3: Rename 'pending' to 'trial_selected'
        DB::statement("UPDATE subscriptions SET status = 'pending' WHERE status = 'pending'"); // no-op, ensure consistency
        
        // Step 4: Change ENUM to new 3 values
        // MySQL requires recreating ENUM. Use ALTER COLUMN MODIFY.
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN status ENUM('trial_selected', 'active', 'expired') NOT NULL DEFAULT 'trial_selected'");
        
        // Step 5: Convert remaining 'pending' rows (they get mapped to trial_selected by MySQL ENUM default)
        // Since we changed ENUM, any 'pending' rows become '' (empty string) — update them
        DB::statement("UPDATE subscriptions SET status = 'trial_selected' WHERE status = '' OR status IS NULL");
    }

    public function down(): void
    {
        // Restore original ENUM
        DB::statement("ALTER TABLE subscriptions MODIFY COLUMN status ENUM('active', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending'");
        
        // Reverse mapping
        DB::statement("UPDATE subscriptions SET status = 'pending' WHERE status = 'trial_selected'");
    }
};
