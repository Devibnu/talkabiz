<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor Plans to Feature-Based Pricing
 * 
 * KONSEP FINAL (WAJIB DIIKUTI):
 * 1. Paket (Plan) = FITUR & AKSES, bukan kuota pesan
 * 2. Pengiriman pesan menggunakan SALDO (TOPUP), terpisah dari paket
 * 3. Tidak ada kuota pesan di paket
 * 4. Owner Panel adalah SSOT untuk harga & fitur paket
 * 
 * PERUBAHAN:
 * - Tambah field fitur-based: max_wa_numbers, max_campaigns, automation_flows, dll
 * - Deprecate field quota-based: quota_messages, limit_messages_*, dll (tanpa drop)
 * - Update business logic untuk fokus ke fitur
 * 
 * @author Senior Product Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            
            // ==================== FEATURE-BASED FIELDS (NEW) ====================
            
            if (!Schema::hasColumn('plans', 'max_wa_numbers')) {
                $table->unsignedTinyInteger('max_wa_numbers')->default(1)
                      ->after('features')
                      ->comment('Maksimal nomor WhatsApp yang bisa dihubungkan');
            }
            
            if (!Schema::hasColumn('plans', 'max_campaigns')) {
                $table->unsignedSmallInteger('max_campaigns')->default(1)
                      ->after('max_wa_numbers')
                      ->comment('Maksimal campaign aktif bersamaan (0 = unlimited)');
            }
            
            if (!Schema::hasColumn('plans', 'max_campaign_recipients')) {
                $table->unsignedInteger('max_campaign_recipients')->default(100)
                      ->after('max_campaigns') 
                      ->comment('Maksimal penerima per campaign (0 = unlimited)');
            }
            
            if (!Schema::hasColumn('plans', 'max_automation_flows')) {
                $table->unsignedSmallInteger('max_automation_flows')->default(0)
                      ->after('max_campaign_recipients')
                      ->comment('Maksimal automation flow (0 = tidak ada flow)');
            }
            
            if (!Schema::hasColumn('plans', 'has_advanced_automation')) {
                $table->boolean('has_advanced_automation')->default(false)
                      ->after('max_automation_flows')
                      ->comment('Fitur automation lanjutan (conditions, delays, etc)');
            }
            
            if (!Schema::hasColumn('plans', 'api_access')) {
                $table->boolean('api_access')->default(false)
                      ->after('has_advanced_automation')
                      ->comment('Akses ke API Talkabiz');
            }
            
            if (!Schema::hasColumn('plans', 'api_rate_limit')) {
                $table->unsignedInteger('api_rate_limit')->default(0)
                      ->after('api_access')
                      ->comment('Rate limit API per jam (0 = tidak ada API)');
            }
            
            if (!Schema::hasColumn('plans', 'webhook_support')) {
                $table->boolean('webhook_support')->default(false)
                      ->after('api_rate_limit')
                      ->comment('Support webhook events');
            }
            
            if (!Schema::hasColumn('plans', 'max_team_members')) {
                $table->unsignedTinyInteger('max_team_members')->default(1)
                      ->after('webhook_support')
                      ->comment('Maksimal anggota tim/agent (1 = single user)');
            }
            
            if (!Schema::hasColumn('plans', 'multi_agent_chat')) {
                $table->boolean('multi_agent_chat')->default(false)
                      ->after('max_team_members')
                      ->comment('Fitur multi-agent dalam 1 chat');
            }
            
            if (!Schema::hasColumn('plans', 'agent_performance_analytics')) {
                $table->boolean('agent_performance_analytics')->default(false)
                      ->after('multi_agent_chat')
                      ->comment('Analytics performa agent');
            }
            
            if (!Schema::hasColumn('plans', 'advanced_analytics')) {
                $table->boolean('advanced_analytics')->default(false)
                      ->after('agent_performance_analytics')
                      ->comment('Analytics lanjutan & custom reports');
            }
            
            if (!Schema::hasColumn('plans', 'export_data')) {
                $table->boolean('export_data')->default(false)
                      ->after('advanced_analytics')
                      ->comment('Export data chat, campaign, analytics');
            }
            
            if (!Schema::hasColumn('plans', 'report_retention_months')) {
                $table->unsignedTinyInteger('report_retention_months')->default(1)
                      ->after('export_data')
                      ->comment('Lama penyimpanan laporan (bulan)');
            }
            
            if (!Schema::hasColumn('plans', 'support_level')) {
                $table->enum('support_level', ['basic', 'priority', 'dedicated'])->default('basic')
                      ->after('report_retention_months')
                      ->comment('Level dukungan teknis');
            }
            
            if (!Schema::hasColumn('plans', 'custom_branding')) {
                $table->boolean('custom_branding')->default(false)
                      ->after('support_level')
                      ->comment('Custom branding/white-label');
            }
            
            if (!Schema::hasColumn('plans', 'priority_support')) {
                $table->boolean('priority_support')->default(false)
                      ->after('custom_branding')
                      ->comment('Dukungan prioritas (fast response)');
            }
            
            // ==================== DEPRECATION FLAGS ====================
            
            if (!Schema::hasColumn('plans', '_legacy_quota_based')) {
                $table->boolean('_legacy_quota_based')->default(true)
                      ->after('priority_support')
                      ->comment('DEPRECATED: Flag untuk compatibility, akan dihapus');
            }
            
            if (!Schema::hasColumn('plans', '_migration_notes')) {
                $table->text('_migration_notes')->nullable()
                      ->after('_legacy_quota_based')
                      ->comment('DEPRECATED: Notes migrasi dari quota-based');
            }
            
        });
        
        // ==================== UPDATE EXISTING PLANS TO FEATURE-BASED ====================
        
        $this->migrateExistingPlans();
    }

    private function migrateExistingPlans(): void
    {
        // Get existing plans and convert them to feature-based
        $plans = \DB::table('plans')->get();
        
        foreach ($plans as $plan) {
            // Determine feature configuration based on current plan
            $features = $this->mapLegacyPlanToFeatures($plan);
            
            // Update plan with new feature-based structure
            \DB::table('plans')
                ->where('id', $plan->id)
                ->update($features);
        }
    }
    
    private function mapLegacyPlanToFeatures($plan): array
    {
        // Map berdasarkan kode plan atau harga untuk menentukan fitur
        $planCode = strtoupper($plan->code);
        $price = (float) $plan->price;
        
        // Default features untuk semua paket
        $features = [
            '_legacy_quota_based' => false, // Mark sebagai migrated
            '_migration_notes' => "Migrated from quota-based on " . now()->toDateTimeString(),
            'support_level' => 'basic',
            'report_retention_months' => 3,
        ];
        
        // Starter Plan (murah, fitur terbatas)
        if (str_contains($planCode, 'STARTER') || $price <= 50000) {
            return array_merge($features, [
                'max_wa_numbers' => 1,
                'max_campaigns' => 1, 
                'max_campaign_recipients' => 100,
                'max_automation_flows' => 0,
                'has_advanced_automation' => false,
                'api_access' => false,
                'api_rate_limit' => 0,
                'webhook_support' => false,
                'max_team_members' => 1,
                'multi_agent_chat' => false,
                'agent_performance_analytics' => false,
                'advanced_analytics' => false,
                'export_data' => false,
                'custom_branding' => false,
                'priority_support' => false,
            ]);
        }
        
        // Growth/Professional Plan (menengah)  
        if (str_contains($planCode, 'GROWTH') || str_contains($planCode, 'PRO') || ($price > 50000 && $price <= 200000)) {
            return array_merge($features, [
                'max_wa_numbers' => 2,
                'max_campaigns' => 3,
                'max_campaign_recipients' => 500, 
                'max_automation_flows' => 2,
                'has_advanced_automation' => false,
                'api_access' => true,
                'api_rate_limit' => 100,
                'webhook_support' => false,
                'max_team_members' => 3,
                'multi_agent_chat' => false,
                'agent_performance_analytics' => false,
                'advanced_analytics' => true,
                'export_data' => true,
                'custom_branding' => false,
                'priority_support' => false,
            ]);
        }
        
        // Business Plan (tinggi)
        if (str_contains($planCode, 'BUSINESS') || ($price > 200000 && $price <= 500000)) {
            return array_merge($features, [
                'max_wa_numbers' => 5,
                'max_campaigns' => 10,
                'max_campaign_recipients' => 2000,
                'max_automation_flows' => 5,
                'has_advanced_automation' => true,
                'api_access' => true,
                'api_rate_limit' => 500,
                'webhook_support' => true,
                'max_team_members' => 5,
                'multi_agent_chat' => true,
                'agent_performance_analytics' => true,
                'advanced_analytics' => true,
                'export_data' => true,
                'custom_branding' => false,
                'priority_support' => true,
                'support_level' => 'priority',
            ]);
        }
        
        // Enterprise Plan (premium)
        if (str_contains($planCode, 'ENTERPRISE') || str_contains($planCode, 'CORP') || $price > 500000) {
            return array_merge($features, [
                'max_wa_numbers' => 20,
                'max_campaigns' => 0, // Unlimited
                'max_campaign_recipients' => 0, // Unlimited
                'max_automation_flows' => 0, // Unlimited
                'has_advanced_automation' => true,
                'api_access' => true,
                'api_rate_limit' => 2000,
                'webhook_support' => true,
                'max_team_members' => 20,
                'multi_agent_chat' => true,
                'agent_performance_analytics' => true,
                'advanced_analytics' => true,
                'export_data' => true,
                'custom_branding' => true,
                'priority_support' => true,
                'support_level' => 'dedicated',
                'report_retention_months' => 12,
            ]);
        }
        
        // Default fallback (jika tidak match dengan pola di atas)
        return array_merge($features, [
            'max_wa_numbers' => 1,
            'max_campaigns' => 1,
            'max_campaign_recipients' => 100,
            'max_automation_flows' => 0,
            'has_advanced_automation' => false,
            'api_access' => false,
            'api_rate_limit' => 0,
            'webhook_support' => false,
            'max_team_members' => 1,
            'multi_agent_chat' => false,
            'agent_performance_analytics' => false,
            'advanced_analytics' => false,
            'export_data' => false,
            'custom_branding' => false,
            'priority_support' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Drop new feature-based columns
            $table->dropColumn([
                'max_wa_numbers',
                'max_campaigns', 
                'max_campaign_recipients',
                'max_automation_flows',
                'has_advanced_automation',
                'api_access',
                'api_rate_limit',
                'webhook_support',
                'max_team_members',
                'multi_agent_chat',
                'agent_performance_analytics',
                'advanced_analytics',
                'export_data',
                'report_retention_months',
                'support_level',
                'custom_branding',
                'priority_support',
                '_legacy_quota_based',
                '_migration_notes',
            ]);
        });
    }
};