<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menambahkan field onboarding untuk FTE (First-Time Experience)
     * UMKM Pilot users harus complete onboarding sebelum bisa kirim campaign.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'onboarding_complete')) {
                $table->boolean('onboarding_complete')->default(false)->after('admin_notes');
            }
            if (!Schema::hasColumn('users', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_complete');
            }
            // Track individual onboarding steps
            if (!Schema::hasColumn('users', 'onboarding_steps')) {
                $table->json('onboarding_steps')->nullable()->after('onboarding_completed_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_complete',
                'onboarding_completed_at',
                'onboarding_steps',
            ]);
        });
    }
};
