<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing boolean columns that scopes/views already reference.
     * Defaults ensure backward compatibility — existing plans remain
     * self-serve, visible, and not enterprise.
     */
    public function up(): void
    {
        if (Schema::hasTable('plans') && !Schema::hasColumn('plans', 'is_self_serve')) {
        Schema::table('plans', function (Blueprint $table) {
            // Self-serve: can be purchased directly (landing/pricing page)
            // Default TRUE so all existing plans remain purchasable
            $table->boolean('is_self_serve')->default(true)->after('is_recommended');

            // Enterprise: requires contacting sales, mutually exclusive with self-serve
            // Default FALSE so existing plans are NOT flagged enterprise
            $table->boolean('is_enterprise')->default(false)->after('is_self_serve');

            // Popular/recommended highlight on pricing page (max 1 typically)
            // Default FALSE so no existing plan is auto-highlighted
            $table->boolean('is_popular')->default(false)->after('is_enterprise');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['is_self_serve', 'is_enterprise', 'is_popular']);
        });
    }
};
