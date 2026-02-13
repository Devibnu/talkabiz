<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Quota Tracking Fields to Users Table
 * 
 * UMKM-First HARDCAP Implementation:
 * Tracking penggunaan kuota di level user untuk enforcement limit ketat.
 * 
 * STARTER PLAN LIMITS:
 * - Monthly: 500 pesan
 * - Daily: 100 pesan  
 * - Hourly: 30 pesan
 * - Max WA Numbers: 1
 * - Max Active Campaigns: 1
 * - Max Recipients/Campaign: 100
 * 
 * @author Senior Laravel SaaS Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'messages_sent_monthly')) {
        Schema::table('users', function (Blueprint $table) {
            // ==================== USAGE COUNTERS ====================
            
            // Monthly usage (reset setiap awal bulan)
            $table->unsignedInteger('messages_sent_monthly')->default(0)
                  ->after('plan_source')
                  ->comment('Jumlah pesan terkirim bulan ini');
            
            $table->date('monthly_reset_date')->nullable()
                  ->after('messages_sent_monthly')
                  ->comment('Tanggal reset counter bulanan');
            
            // Daily usage (reset setiap hari)
            $table->unsignedInteger('messages_sent_daily')->default(0)
                  ->after('monthly_reset_date')
                  ->comment('Jumlah pesan terkirim hari ini');
            
            $table->date('daily_reset_date')->nullable()
                  ->after('messages_sent_daily')
                  ->comment('Tanggal reset counter harian');
            
            // Hourly usage (reset setiap jam)
            $table->unsignedInteger('messages_sent_hourly')->default(0)
                  ->after('daily_reset_date')
                  ->comment('Jumlah pesan terkirim jam ini');
            
            $table->timestamp('hourly_reset_at')->nullable()
                  ->after('messages_sent_hourly')
                  ->comment('Waktu reset counter per jam');
            
            // ==================== ACTIVE RESOURCES ====================
            
            $table->unsignedTinyInteger('active_campaigns_count')->default(0)
                  ->after('hourly_reset_at')
                  ->comment('Jumlah campaign aktif saat ini');
            
            $table->unsignedTinyInteger('connected_wa_numbers')->default(0)
                  ->after('active_campaigns_count')
                  ->comment('Jumlah nomor WA terhubung');
            
            // ==================== QUOTA EXCEEDED TRACKING ====================
            
            $table->timestamp('last_quota_exceeded_at')->nullable()
                  ->after('connected_wa_numbers')
                  ->comment('Terakhir kali kuota habis');
            
            $table->string('last_quota_exceeded_type', 20)->nullable()
                  ->after('last_quota_exceeded_at')
                  ->comment('Tipe limit yang terakhir exceeded: monthly/daily/hourly');
            
            // Index for quota queries
            $table->index(['current_plan_id', 'plan_status', 'messages_sent_monthly']);
        });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_plan_id', 'plan_status', 'messages_sent_monthly']);
            
            $table->dropColumn([
                'messages_sent_monthly',
                'monthly_reset_date',
                'messages_sent_daily',
                'daily_reset_date',
                'messages_sent_hourly',
                'hourly_reset_at',
                'active_campaigns_count',
                'connected_wa_numbers',
                'last_quota_exceeded_at',
                'last_quota_exceeded_type',
            ]);
        });
    }
};
