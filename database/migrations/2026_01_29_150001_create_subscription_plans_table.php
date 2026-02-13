<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Subscription Plans Table
 * 
 * HYBRID PRICING - Platform Fee Bulanan
 * Setiap klien punya 1 subscription plan yang menentukan limit dan fitur.
 * 
 * ATURAN BISNIS:
 * - Free: Inbox only, no campaign blast
 * - Starter: Campaign kecil
 * - Pro: Campaign besar
 * - Enterprise: Custom limit
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique(); // free, starter, pro, enterprise
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            
            // Monthly Platform Fee
            $table->decimal('monthly_fee', 12, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            
            // LIMITS - Anti Spam
            $table->unsignedInteger('max_daily_send')->default(0); // 0 = unlimited
            $table->unsignedInteger('max_monthly_send')->default(0); // 0 = unlimited
            $table->unsignedInteger('max_active_campaign')->default(1);
            $table->unsignedInteger('max_contacts')->default(1000);
            
            // FEATURES
            $table->boolean('inbox_enabled')->default(true);
            $table->boolean('campaign_enabled')->default(false);
            $table->boolean('broadcast_enabled')->default(false);
            $table->boolean('template_enabled')->default(false);
            $table->boolean('api_access_enabled')->default(false);
            
            // Priority (untuk sorting)
            $table->unsignedTinyInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true); // Tampil di pricing page
            
            $table->timestamps();
        });
        
        // Insert default plans
        DB::table('subscription_plans')->insert([
            [
                'name' => 'free',
                'display_name' => 'Free',
                'description' => 'Untuk coba-coba. Inbox only, tidak bisa blast campaign.',
                'monthly_fee' => 0,
                'currency' => 'IDR',
                'max_daily_send' => 0, // Tidak bisa kirim campaign
                'max_monthly_send' => 0,
                'max_active_campaign' => 0,
                'max_contacts' => 100,
                'inbox_enabled' => true,
                'campaign_enabled' => false,
                'broadcast_enabled' => false,
                'template_enabled' => false,
                'api_access_enabled' => false,
                'priority' => 0,
                'is_active' => true,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'starter',
                'display_name' => 'Starter',
                'description' => 'Untuk UMKM kecil. Campaign terbatas.',
                'monthly_fee' => 99000,
                'currency' => 'IDR',
                'max_daily_send' => 100,
                'max_monthly_send' => 1000,
                'max_active_campaign' => 2,
                'max_contacts' => 500,
                'inbox_enabled' => true,
                'campaign_enabled' => true,
                'broadcast_enabled' => false,
                'template_enabled' => true,
                'api_access_enabled' => false,
                'priority' => 10,
                'is_active' => true,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'pro',
                'display_name' => 'Pro',
                'description' => 'Untuk bisnis berkembang. Campaign besar.',
                'monthly_fee' => 299000,
                'currency' => 'IDR',
                'max_daily_send' => 500,
                'max_monthly_send' => 10000,
                'max_active_campaign' => 5,
                'max_contacts' => 5000,
                'inbox_enabled' => true,
                'campaign_enabled' => true,
                'broadcast_enabled' => true,
                'template_enabled' => true,
                'api_access_enabled' => true,
                'priority' => 20,
                'is_active' => true,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'enterprise',
                'display_name' => 'Enterprise',
                'description' => 'Untuk perusahaan besar. Custom limit.',
                'monthly_fee' => 999000,
                'currency' => 'IDR',
                'max_daily_send' => 0, // Unlimited
                'max_monthly_send' => 0, // Unlimited
                'max_active_campaign' => 0, // Unlimited
                'max_contacts' => 0, // Unlimited
                'inbox_enabled' => true,
                'campaign_enabled' => true,
                'broadcast_enabled' => true,
                'template_enabled' => true,
                'api_access_enabled' => true,
                'priority' => 30,
                'is_active' => true,
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        
        // Add subscription_plan_id to klien table
        if (Schema::hasTable('klien') && !Schema::hasColumn('klien', 'subscription_plan_id')) {
        Schema::table('klien', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')
                ->nullable()
                ->after('tipe_paket')
                ->constrained('subscription_plans')
                ->nullOnDelete();
        });
        }
    }

    public function down(): void
    {
        Schema::table('klien', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn('subscription_plan_id');
        });
        
        Schema::dropIfExists('subscription_plans');
    }
};
