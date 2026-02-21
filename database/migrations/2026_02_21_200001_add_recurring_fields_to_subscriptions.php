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
            $table->string('midtrans_subscription_id')->nullable()->after('cancelled_at');
            $table->text('recurring_token')->nullable()->after('midtrans_subscription_id');
            $table->boolean('auto_renew')->default(true)->after('recurring_token');
            $table->timestamp('last_renewal_at')->nullable()->after('auto_renew');
            $table->unsignedTinyInteger('renewal_attempts')->default(0)->after('last_renewal_at');
        });

        // Index for the scheduler query: active + auto_renew + expiring soon
        DB::statement("
            CREATE INDEX subscriptions_auto_renew_idx 
            ON subscriptions (status, auto_renew, expires_at)
        ");
    }

    public function down(): void
    {
        // Drop index first
        $indexExists = DB::select("
            SHOW INDEX FROM subscriptions WHERE Key_name = 'subscriptions_auto_renew_idx'
        ");
        if (!empty($indexExists)) {
            DB::statement("DROP INDEX subscriptions_auto_renew_idx ON subscriptions");
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
