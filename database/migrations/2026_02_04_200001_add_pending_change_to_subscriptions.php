<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add pending_change and replaced status to subscriptions
 * 
 * UPGRADE & DOWNGRADE FLOW:
 * =========================
 * 
 * UPGRADE (immediate):
 * 1. Create new subscription (active)
 * 2. Mark old subscription as 'replaced'
 * 3. New snapshot berlaku langsung
 * 
 * DOWNGRADE (pending):
 * 1. Simpan pending_change JSON
 * 2. Tetap pakai plan lama sampai expires_at
 * 3. Scheduled job process pending at end of period
 * 
 * pending_change JSON structure:
 * {
 *   "new_plan_id": 1,
 *   "new_plan_snapshot": {...},
 *   "new_price": 50000,
 *   "requested_at": "2026-02-04 10:00:00",
 *   "effective_at": "2026-03-01 00:00:00",
 *   "reason": "downgrade"
 * }
 * 
 * @see SA Document: Upgrade & Downgrade Flow
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Add pending_change column for downgrade flow
            if (!Schema::hasColumn('subscriptions', 'pending_change')) {
                $table->json('pending_change')
                      ->nullable()
                      ->after('plan_snapshot')
                      ->comment('Pending plan change (downgrade) - applied at period end');
            }

            // Add replaced_by column to track upgrade chain
            if (!Schema::hasColumn('subscriptions', 'replaced_by')) {
                $table->foreignId('replaced_by')
                      ->nullable()
                      ->after('status')
                      ->comment('ID of subscription that replaced this one (upgrade)');
            }

            // Add replaced_at timestamp
            if (!Schema::hasColumn('subscriptions', 'replaced_at')) {
                $table->timestamp('replaced_at')
                      ->nullable()
                      ->after('replaced_by')
                      ->comment('When this subscription was replaced');
            }

            // Add change_type to track what kind of change occurred
            if (!Schema::hasColumn('subscriptions', 'change_type')) {
                $table->string('change_type', 20)
                      ->nullable()
                      ->after('replaced_at')
                      ->comment('new|upgrade|downgrade|renewal');
            }

            // Add previous_subscription_id for chain tracking
            if (!Schema::hasColumn('subscriptions', 'previous_subscription_id')) {
                $table->foreignId('previous_subscription_id')
                      ->nullable()
                      ->after('change_type')
                      ->comment('Previous subscription in chain');
            }
        });

        // Note: status 'replaced' added to enum via alter
        // Since MySQL alter enum is tricky, we'll handle it at model level
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'pending_change')) {
                $table->dropColumn('pending_change');
            }
            if (Schema::hasColumn('subscriptions', 'replaced_by')) {
                $table->dropColumn('replaced_by');
            }
            if (Schema::hasColumn('subscriptions', 'replaced_at')) {
                $table->dropColumn('replaced_at');
            }
            if (Schema::hasColumn('subscriptions', 'change_type')) {
                $table->dropColumn('change_type');
            }
            if (Schema::hasColumn('subscriptions', 'previous_subscription_id')) {
                $table->dropColumn('previous_subscription_id');
            }
        });
    }
};
