<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Plan Fields to Users Table
 * 
 * UMKM-First Design:
 * - Setiap user UMKM langsung punya plan
 * - Tidak perlu Klien untuk soft-launch
 * - current_plan_id = plan yang aktif sekarang
 * 
 * @author Senior Laravel SaaS Engineer
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'current_plan_id')) {
        Schema::table('users', function (Blueprint $table) {
            // Current Active Plan
            $table->foreignId('current_plan_id')
                  ->nullable()
                  ->after('onboarding_steps')
                  ->constrained('plans')
                  ->nullOnDelete()
                  ->comment('Plan aktif user saat ini');
            
            // Plan Period
            $table->timestamp('plan_started_at')
                  ->nullable()
                  ->after('current_plan_id')
                  ->comment('Tanggal mulai plan');
            
            $table->timestamp('plan_expires_at')
                  ->nullable()
                  ->after('plan_started_at')
                  ->comment('Tanggal berakhir plan (null = unlimited)');
            
            // Plan Status
            $table->enum('plan_status', ['active', 'expired', 'cancelled', 'pending'])
                  ->default('pending')
                  ->after('plan_expires_at')
                  ->comment('Status plan user');
            
            // Plan Source (untuk tracking)
            $table->enum('plan_source', ['registration', 'purchase', 'admin', 'promo', 'upgrade'])
                  ->default('registration')
                  ->after('plan_status')
                  ->comment('Sumber aktivasi plan');
            
            // Index for quick lookups
            $table->index(['current_plan_id', 'plan_status']);
        });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_plan_id', 'plan_status']);
            $table->dropForeign(['current_plan_id']);
            $table->dropColumn([
                'current_plan_id',
                'plan_started_at',
                'plan_expires_at',
                'plan_status',
                'plan_source',
            ]);
        });
    }
};
