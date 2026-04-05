<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-Renewal Recurring Subscription — Add recurring fields to subscriptions table.
 * 
 * New columns:
 *   - midtrans_subscription_id: Midtrans recurring subscription reference
 *   - recurring_token:          Saved card token from Midtrans for server-to-server charge
 *   - auto_renew:               Whether the subscription auto-renews (default true)
 *   - last_renewal_at:          Timestamp of last successful auto-renewal
 *   - renewal_attempts:         Number of failed renewal attempts (reset on success)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'midtrans_subscription_id')) {
                $table->string('midtrans_subscription_id')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('subscriptions', 'recurring_token')) {
                $table->text('recurring_token')->nullable()->after('midtrans_subscription_id');
            }
            if (!Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->boolean('auto_renew')->default(true)->after('recurring_token');
            }
            if (!Schema::hasColumn('subscriptions', 'last_renewal_at')) {
                $table->timestamp('last_renewal_at')->nullable()->after('auto_renew');
            }
            if (!Schema::hasColumn('subscriptions', 'renewal_attempts')) {
                $table->unsignedTinyInteger('renewal_attempts')->default(0)->after('last_renewal_at');
            }
        });

        // Index for the scheduler query: active + auto_renew + expiring soon
        try {
            DB::statement(" 
                CREATE INDEX subscriptions_auto_renew_idx 
                ON subscriptions (status, auto_renew, expires_at)
            ");
        } catch (\Throwable $e) {
            // Ignore if the index already exists in test databases.
        }
    }

    public function down(): void
    {
        // Drop index first
        if (DB::getDriverName() !== 'sqlite') {
            $indexExists = DB::select(" 
                SHOW INDEX FROM subscriptions WHERE Key_name = 'subscriptions_auto_renew_idx'
            ");
            if (!empty($indexExists)) {
                DB::statement("DROP INDEX subscriptions_auto_renew_idx ON subscriptions");
            }
        } else {
            try {
                Schema::table('subscriptions', function (Blueprint $table) {
                    $table->dropIndex('subscriptions_auto_renew_idx');
                });
            } catch (\Throwable $e) {
                // Ignore if absent.
            }
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'midtrans_subscription_id',
                'recurring_token',
                'auto_renew',
                'last_renewal_at',
                'renewal_attempts',
            ]);
        });
    }
};
