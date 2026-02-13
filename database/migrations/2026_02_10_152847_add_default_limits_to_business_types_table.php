<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add default_limits to business_types table
 * 
 * PURPOSE:
 * - Store default quota limits per business type
 * - Applied automatically during onboarding
 * - Can be overridden by owner per user
 * - Backward compatible with existing users
 * 
 * STRUCTURE:
 * {
 *   "max_active_campaign": 1,
 *   "daily_message_quota": 100,
 *   "monthly_message_quota": 1000,
 *   "campaign_send_enabled": true
 * }
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('business_types') && !Schema::hasColumn('business_types', 'default_limits')) {
        Schema::table('business_types', function (Blueprint $table) {
            // Add default_limits JSON column
            $table->json('default_limits')
                ->nullable()
                ->after('requires_manual_approval')
                ->comment('Default quota limits applied during onboarding (can be overridden)');
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_types', function (Blueprint $table) {
            $table->dropColumn('default_limits');
        });
    }
};
