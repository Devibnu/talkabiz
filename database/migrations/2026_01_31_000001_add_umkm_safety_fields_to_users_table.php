<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menambahkan field role, segment, launch_phase, dan safety guards
     * untuk UMKM-first onboarding dengan default values yang aman.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Role & Segment (default UMKM untuk soft-launch)
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 50)->default('umkm')->after('about_me');
            }
            if (!Schema::hasColumn('users', 'segment')) {
                $table->string('segment', 50)->default('umkm')->after('role');
            }
            if (!Schema::hasColumn('users', 'launch_phase')) {
                $table->string('launch_phase', 50)->default('UMKM_PILOT')->after('segment');
            }
            
            // Safety Guards - New users cannot blast immediately
            if (!Schema::hasColumn('users', 'max_active_campaign')) {
                $table->unsignedInteger('max_active_campaign')->default(0)->after('launch_phase');
            }
            if (!Schema::hasColumn('users', 'template_status')) {
                $table->string('template_status', 50)->default('approval_required')->after('max_active_campaign');
            }
            if (!Schema::hasColumn('users', 'daily_message_quota')) {
                $table->unsignedInteger('daily_message_quota')->default(0)->after('template_status');
            }
            if (!Schema::hasColumn('users', 'monthly_message_quota')) {
                $table->unsignedInteger('monthly_message_quota')->default(0)->after('daily_message_quota');
            }
            if (!Schema::hasColumn('users', 'campaign_send_enabled')) {
                $table->boolean('campaign_send_enabled')->default(false)->after('monthly_message_quota');
            }
            
            // Risk & Onboarding Tracking
            if (!Schema::hasColumn('users', 'risk_level')) {
                $table->string('risk_level', 20)->default('baseline')->after('campaign_send_enabled');
            }
            if (!Schema::hasColumn('users', 'onboarded_at')) {
                $table->timestamp('onboarded_at')->nullable()->after('risk_level');
            }
            if (!Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('onboarded_at');
            }
            if (!Schema::hasColumn('users', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            
            // Notes for admin
            if (!Schema::hasColumn('users', 'admin_notes')) {
                $table->text('admin_notes')->nullable()->after('approved_by');
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
                'role',
                'segment', 
                'launch_phase',
                'max_active_campaign',
                'template_status',
                'daily_message_quota',
                'monthly_message_quota',
                'campaign_send_enabled',
                'risk_level',
                'onboarded_at',
                'approved_at',
                'approved_by',
                'admin_notes',
            ]);
        });
    }
};
