<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Create 3 FINAL Example Plans - Feature-Based (NO QUOTAS!)
 * 
 * KONSEP FINAL:
 * 1. Plans = FEATURES only (API, Webhook, Analytics, etc)
 * 2. Messages = SALDO system (topup separately)
 * 3. No message quotas in plans
 * 4. Clear value proposition based on business needs
 */
class ExamplePlanSeeder extends Seeder
{
    public function run(): void
    {
        // HAPUS plans lama (non-production safe)
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Plan::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        echo "🗑️  Cleared old inconsistent plans\n";
        
        // PLAN 1: BASIC (UMKM Entry Level)
        Plan::create([
            'code' => 'BASIC',
            'name' => 'Talkabiz Basic',
            'description' => 'Solusi WhatsApp marketing untuk UMKM yang baru memulai. Fitur core untuk chat & broadcast sederhana.',
            'segment' => Plan::SEGMENT_UMKM,
            'price' => 99000.00, // Rp 99k/bulan - affordable untuk UMKM
            'currency' => 'IDR',
            'discount_price' => null,
            'duration_days' => 30,
            
            // FEATURE-BASED FIELDS (NO MESSAGE QUOTAS!)
            'max_wa_numbers' => 1,           // 1 WhatsApp number
            'max_campaigns' => 3,            // 3 campaigns
            'max_campaign_recipients' => 500, // 500 recipients per campaign
            'max_automation_flows' => 0,     // No automation
            'has_advanced_automation' => false,
            'api_access' => false,           // No API
            'api_rate_limit' => 0,
            'webhook_support' => false,      // No webhooks  
            'max_team_members' => 1,         // Solo user
            'multi_agent_chat' => false,     // No multi-agent
            'agent_performance_analytics' => false,
            'advanced_analytics' => false,  // Basic stats only
            'export_data' => false,          // No data export
            'report_retention_months' => 1,  // 1 month retention
            'support_level' => Plan::SUPPORT_BASIC,
            'custom_branding' => false,
            'priority_support' => false,
            
            // DEPRECATED QUOTA FIELDS (set to 0 - tidak dipakai!)
            'quota_messages' => 0,           // DEPRECATED: Use saldo system
            'quota_contacts' => 0,           // DEPRECATED
            'quota_campaigns' => 0,          // DEPRECATED
            'features' => [                  // DEPRECATED: Use dedicated columns 
                Plan::FEATURE_INBOX,
                Plan::FEATURE_BROADCAST, 
                Plan::FEATURE_TEMPLATE,
            ],
            
            // VISIBILITY & PURCHASE
            'is_purchasable' => true,
            'is_visible' => true,
            'is_active' => true, 
            'is_recommended' => false,
            'sort_order' => 1,
            'badge_text' => 'Populer',
            'badge_color' => 'primary',
            
            // LEGACY COMPATIBILITY
            '_legacy_quota_based' => false,  // This is feature-based plan
        ]);
        
        // PLAN 2: PROFESSIONAL (UMKM Advanced) 
        Plan::create([
            'code' => 'PROFESSIONAL',
            'name' => 'Talkabiz Professional', 
            'description' => 'Untuk bisnis yang berkembang. Automation, analytics, dan integrasi API untuk scale up operations.',
            'segment' => Plan::SEGMENT_UMKM,
            'price' => 299000.00, // Rp 299k/bulan - good value for growing business
            'currency' => 'IDR', 
            'discount_price' => 249000.00, // Promo price
            'duration_days' => 30,
            
            // FEATURE-BASED FIELDS
            'max_wa_numbers' => 3,           // 3 WhatsApp numbers
            'max_campaigns' => 10,           // 10 campaigns
            'max_campaign_recipients' => 2000, // 2K recipients per campaign  
            'max_automation_flows' => 5,     // 5 automation flows
            'has_advanced_automation' => true,
            'api_access' => true,            // API access included
            'api_rate_limit' => 1000,        // 1K API calls/hour
            'webhook_support' => true,       // Webhook integration
            'max_team_members' => 3,         // Small team
            'multi_agent_chat' => true,      // Multi-agent support
            'agent_performance_analytics' => true,
            'advanced_analytics' => true,   // Full analytics
            'export_data' => true,           // Data export
            'report_retention_months' => 6,  // 6 months retention
            'support_level' => Plan::SUPPORT_PRIORITY,
            'custom_branding' => true,       // Custom branding
            'priority_support' => true,
            
            // DEPRECATED QUOTA FIELDS (set to 0)
            'quota_messages' => 0,
            'quota_contacts' => 0, 
            'quota_campaigns' => 0,
            'features' => [
                Plan::FEATURE_INBOX,
                Plan::FEATURE_BROADCAST,
                Plan::FEATURE_CAMPAIGN,
                Plan::FEATURE_TEMPLATE,
                Plan::FEATURE_AUTOMATION,
                Plan::FEATURE_API,
                Plan::FEATURE_WEBHOOK,
                Plan::FEATURE_MULTI_AGENT,
                Plan::FEATURE_ANALYTICS,
                Plan::FEATURE_EXPORT,
            ],
            
            // VISIBILITY & PURCHASE
            'is_purchasable' => true,
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => true,        // RECOMMENDED plan
            'sort_order' => 2,
            'badge_text' => 'Terbaik',
            'badge_color' => 'success',
            
            // LEGACY COMPATIBILITY
            '_legacy_quota_based' => false,
        ]);
        
        // PLAN 3: ENTERPRISE (Corporate)
        Plan::create([
            'code' => 'ENTERPRISE',
            'name' => 'Talkabiz Enterprise',
            'description' => 'Solusi enterprise untuk perusahaan besar. Unlimited capacity, dedicated support, dan custom integrations.',
            'segment' => Plan::SEGMENT_CORPORATE,
            'price' => 2499000.00, // Rp 2.5jt/bulan - enterprise pricing
            'currency' => 'IDR',
            'discount_price' => null,
            'duration_days' => 30,
            
            // FEATURE-BASED FIELDS - UNLIMITED/HIGH LIMITS
            'max_wa_numbers' => Plan::UNLIMITED,    // Unlimited
            'max_campaigns' => Plan::UNLIMITED,     // Unlimited
            'max_campaign_recipients' => Plan::UNLIMITED, // Unlimited recipients
            'max_automation_flows' => Plan::UNLIMITED, // Unlimited flows
            'has_advanced_automation' => true,
            'api_access' => true,
            'api_rate_limit' => Plan::UNLIMITED,    // Unlimited API calls
            'webhook_support' => true,
            'max_team_members' => Plan::UNLIMITED,  // Unlimited team
            'multi_agent_chat' => true,
            'agent_performance_analytics' => true,
            'advanced_analytics' => true,
            'export_data' => true,
            'report_retention_months' => 24,        // 2 years retention
            'support_level' => Plan::SUPPORT_DEDICATED, 
            'custom_branding' => true,
            'priority_support' => true,
            
            // DEPRECATED QUOTA FIELDS
            'quota_messages' => 0,
            'quota_contacts' => 0,
            'quota_campaigns' => 0, 
            'features' => [
                Plan::FEATURE_INBOX,
                Plan::FEATURE_BROADCAST,
                Plan::FEATURE_CAMPAIGN, 
                Plan::FEATURE_TEMPLATE,
                Plan::FEATURE_AUTOMATION,
                Plan::FEATURE_API,
                Plan::FEATURE_WEBHOOK,
                Plan::FEATURE_MULTI_AGENT,
                Plan::FEATURE_ANALYTICS,
                Plan::FEATURE_EXPORT,
            ],
            
            // VISIBILITY & PURCHASE
            'is_purchasable' => false,       // NOT self-service (contact sales)
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => false,
            'sort_order' => 3,
            'badge_text' => 'Enterprise',
            'badge_color' => 'dark',
            
            // LEGACY COMPATIBILITY  
            '_legacy_quota_based' => false,
        ]);
        
        echo "✅ Created 3 FINAL example plans:\n";
        echo "   1. Basic (Rp 99k) - Entry UMKM\n";
        echo "   2. Professional (Rp 299k) - Growing UMKM [RECOMMENDED]\n"; 
        echo "   3. Enterprise (Rp 2.5jt) - Corporate\n";
        echo "\n🎯 KONSEP FINAL implemented:\n";
        echo "   ✅ Plans = FEATURES only\n";
        echo "   ✅ No message quotas\n"; 
        echo "   ✅ Clear value differentiation\n";
        echo "   ✅ Proper pricing based on business value\n";
    }
}