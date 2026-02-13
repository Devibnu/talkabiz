<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Limit Fields to Plans Table
 * 
 * Setiap plan punya limit yang berbeda.
 * Ini adalah HARDCAP - tidak bisa di-bypass.
 * 
 * @author Senior Laravel SaaS Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plans') && !Schema::hasColumn('plans', 'limit_messages_monthly')) {
        Schema::table('plans', function (Blueprint $table) {
            // ==================== MESSAGE LIMITS ====================
            
            $table->unsignedInteger('limit_messages_monthly')->default(500)
                  ->after('quota_campaigns')
                  ->comment('Max pesan per bulan (0 = unlimited)');
            
            $table->unsignedInteger('limit_messages_daily')->default(100)
                  ->after('limit_messages_monthly')
                  ->comment('Max pesan per hari (0 = unlimited)');
            
            $table->unsignedInteger('limit_messages_hourly')->default(30)
                  ->after('limit_messages_daily')
                  ->comment('Max pesan per jam (0 = unlimited)');
            
            // ==================== RESOURCE LIMITS ====================
            
            $table->unsignedTinyInteger('limit_wa_numbers')->default(1)
                  ->after('limit_messages_hourly')
                  ->comment('Max nomor WA yang bisa dihubungkan');
            
            $table->unsignedTinyInteger('limit_active_campaigns')->default(1)
                  ->after('limit_wa_numbers')
                  ->comment('Max campaign aktif bersamaan');
            
            $table->unsignedInteger('limit_recipients_per_campaign')->default(100)
                  ->after('limit_active_campaigns')
                  ->comment('Max recipient per campaign');
        });
        }
        
        // Update existing plans with starter limits
        \DB::table('plans')->where('code', 'umkm-starter')->update([
            'limit_messages_monthly' => 500,
            'limit_messages_daily' => 100,
            'limit_messages_hourly' => 30,
            'limit_wa_numbers' => 1,
            'limit_active_campaigns' => 1,
            'limit_recipients_per_campaign' => 100,
        ]);
        
        // Growth plan
        \DB::table('plans')->where('code', 'umkm-growth')->update([
            'limit_messages_monthly' => 5000,
            'limit_messages_daily' => 500,
            'limit_messages_hourly' => 100,
            'limit_wa_numbers' => 2,
            'limit_active_campaigns' => 5,
            'limit_recipients_per_campaign' => 500,
        ]);
        
        // Pro plan
        \DB::table('plans')->where('code', 'umkm-pro')->update([
            'limit_messages_monthly' => 15000,
            'limit_messages_daily' => 1500,
            'limit_messages_hourly' => 300,
            'limit_wa_numbers' => 5,
            'limit_active_campaigns' => 10,
            'limit_recipients_per_campaign' => 1000,
        ]);
        
        // Corporate plans - higher limits
        \DB::table('plans')->where('code', 'corp-business')->update([
            'limit_messages_monthly' => 50000,
            'limit_messages_daily' => 5000,
            'limit_messages_hourly' => 1000,
            'limit_wa_numbers' => 10,
            'limit_active_campaigns' => 25,
            'limit_recipients_per_campaign' => 5000,
        ]);
        
        \DB::table('plans')->where('code', 'corp-enterprise')->update([
            'limit_messages_monthly' => 0, // Unlimited
            'limit_messages_daily' => 0,   // Unlimited
            'limit_messages_hourly' => 0,  // Unlimited
            'limit_wa_numbers' => 50,
            'limit_active_campaigns' => 100,
            'limit_recipients_per_campaign' => 50000,
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'limit_messages_monthly',
                'limit_messages_daily',
                'limit_messages_hourly',
                'limit_wa_numbers',
                'limit_active_campaigns',
                'limit_recipients_per_campaign',
            ]);
        });
    }
};
