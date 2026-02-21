<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add anti-abuse tracking fields to user_plans table.
 * 
 * Used by PlanChangeService to enforce:
 * - Max 2 plan changes per billing cycle
 * - 3-day cooldown between changes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->timestamp('last_plan_change_at')->nullable()->after('notes')
                ->comment('Last time this klien changed plan (anti-abuse cooldown)');
            $table->unsignedInteger('plan_change_count')->default(0)->after('last_plan_change_at')
                ->comment('Number of plan changes in current billing cycle (max 2)');
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn(['last_plan_change_at', 'plan_change_count']);
        });
    }
};
