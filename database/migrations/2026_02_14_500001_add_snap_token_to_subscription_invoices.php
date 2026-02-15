<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4.1 — Fast Payment Path
 * 
 * Adds snap_token to subscription_invoices for direct Snap popup reuse.
 * Previously snap token was only stored in PlanTransaction.pg_redirect_url
 * and had to be extracted via regex. Now stored directly for instant access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('snap_token', 255)
                ->nullable()
                ->after('idempotency_key')
                ->comment('Midtrans Snap token for direct popup reuse');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn('snap_token');
        });
    }
};
