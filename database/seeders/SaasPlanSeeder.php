<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SaaS Billing-First Plan Seeder
 * 
 * BILLING MODEL:
 * 1. Subscription = FEATURES (API, Analytics, Automation)
 * 2. Topup = MESSAGE CREDITS (saldo terpisah)
 * 3. NO FREE PLANS (semua berbayar)
 * 4. NO MESSAGE QUOTAS (pure feature-based)
 * 5. Clear value ladder for business growth
 */
class SaasPlanSeeder extends Seeder
{
    public function run(): void
    {
        // HAPUS semua plan lama
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Plan::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        echo "🧹 Cleared all plans for SaaS billing-first model\n";
        
        // PLAN 1: BASIC (Entry SaaS) - Rp 149k/bulan
        Plan::create([
            'code' => 'BASIC',
            'name' => 'Talkabiz Basic',
            'description' => 'Solusi WhatsApp marketing untuk UMKM. Fitur dasar untuk mulai berbisnis dengan WhatsApp secara profesional.',
            'segment' => Plan::SEGMENT_UMKM,
            'price' => 149000.00, // Rp 149k - no free plans!
            'currency' => 'IDR',
            'discount_price' => null,
            'duration_days' => 30,
            
            // FEATURE-BASED LIMITS (Business-focused)
            'max_wa_numbers' => 1,              // 1 WA number (single business)
            'max_campaigns' => 5,               // 5 marketing campaigns
            'max_campaign_recipients' => 1000,  // 1K contacts per campaign
            'max_automation_flows' => 2,        // 2 basic flows
            'has_advanced_automation' => false,
            'api_access' => false,              // No API for basic
            'api_rate_limit' => 0,
            'webhook_support' => false,         // No webhooks
            'max_team_members' => 1,            // Solo entrepreneur
            'multi_agent_chat' => false,        // No multi-agent
            'agent_performance_analytics' => false,
            'advanced_analytics' => false,     // Basic reporting only
            'export_data' => false,             // No data export
            'report_retention_months' => 3,     // 3 months history
            'support_level' => Plan::SUPPORT_BASIC,
            'custom_branding' => false,
            'priority_support' => false,
            
            // DEPRECATED QUOTA SYSTEM (ALWAYS 0)
            'quota_messages' => 0,              // NO MESSAGE QUOTAS!
            'quota_contacts' => 0,
            'quota_campaigns' => 0,
            'features' => [                     // For legacy compatibility
                Plan::FEATURE_INBOX,
                Plan::FEATURE_BROADCAST,
                Plan::FEATURE_TEMPLATE,
                Plan::FEATURE_AUTOMATION,       // Basic automation only
            ],
            
            // SAAS BILLING SETTINGS
            'is_purchasable' => true,           // Self-serve payment
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => false,
            'sort_order' => 1,
            'badge_text' => 'Starter',
            'badge_color' => 'primary',
            '_legacy_quota_based' => false,     // Pure feature-based
            'target_margin' => 70.00,           // 70% target margin
        ]);
        
        // PLAN 2: GROWTH (Business SaaS) - Rp 399k/bulan
        Plan::create([
            'code' => 'GROWTH',
            'name' => 'Talkabiz Growth',
            'description' => 'Untuk bisnis berkembang yang butuh skalabilitas. Automation canggih, analytics mendalam, dan API integration.',
            'segment' => Plan::SEGMENT_UMKM,
            'price' => 399000.00, // Rp 399k - premium UMKM tier
            'currency' => 'IDR',
            'discount_price' => 349000.00, // Launch promo
            'duration_days' => 30,
            
            // ADVANCED FEATURE LIMITS 
            'max_wa_numbers' => 3,              // 3 WA numbers (multi-product/branch)
            'max_campaigns' => 25,              // 25 campaigns (scale marketing)
            'max_campaign_recipients' => 5000,  // 5K contacts per campaign
            'max_automation_flows' => 10,       // 10 automation flows
            'has_advanced_automation' => true,  // Advanced flow builder
            'api_access' => true,               // API integration
            'api_rate_limit' => 2000,           // 2K API calls/hour
            'webhook_support' => true,          // Webhook notifications
            'max_team_members' => 5,            // Small team (5 people)
            'multi_agent_chat' => true,         // Multi-agent support
            'agent_performance_analytics' => true,
            'advanced_analytics' => true,      // Full analytics dashboard
            'export_data' => true,              // CSV/Excel export
            'report_retention_months' => 12,    // 1 year history
            'support_level' => Plan::SUPPORT_PRIORITY,
            'custom_branding' => true,          // White-label branding
            'priority_support' => true,
            
            // NO MESSAGE QUOTAS
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
            
            // SAAS BILLING SETTINGS
            'is_purchasable' => true,
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => true,           // RECOMMENDED TIER
            'sort_order' => 2,
            'badge_text' => 'Most Popular',
            'badge_color' => 'success',
            '_legacy_quota_based' => false,
            'target_margin' => 75.00,           // 75% target margin
        ]);
        
        // PLAN 3: PRO (Enterprise SaaS) - Rp 999k/bulan
        Plan::create([
            'code' => 'PRO',
            'name' => 'Talkabiz Pro',
            'description' => 'Solusi enterprise untuk bisnis besar. Unlimited capacity, dedicated support, advanced integrations & compliance.',
            'segment' => Plan::SEGMENT_CORPORATE,
            'price' => 999000.00, // Rp 999k - enterprise tier
            'currency' => 'IDR',
            'discount_price' => null,
            'duration_days' => 30,
            
            // ENTERPRISE LIMITS (High capacity)
            'max_wa_numbers' => Plan::UNLIMITED,        // Unlimited WA numbers
            'max_campaigns' => Plan::UNLIMITED,         // Unlimited campaigns
            'max_campaign_recipients' => Plan::UNLIMITED, // Unlimited recipients
            'max_automation_flows' => Plan::UNLIMITED,  // Unlimited flows
            'has_advanced_automation' => true,
            'api_access' => true,
            'api_rate_limit' => Plan::UNLIMITED,        // Unlimited API calls
            'webhook_support' => true,
            'max_team_members' => Plan::UNLIMITED,      // Unlimited team
            'multi_agent_chat' => true,
            'agent_performance_analytics' => true,
            'advanced_analytics' => true,
            'export_data' => true,
            'report_retention_months' => 36,            // 3 years retention
            'support_level' => Plan::SUPPORT_DEDICATED, // Dedicated support
            'custom_branding' => true,
            'priority_support' => true,
            
            // NO MESSAGE QUOTAS 
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
            
            // ENTERPRISE BILLING SETTINGS
            'is_purchasable' => true,           // Can buy online (high-touch sales)
            'is_visible' => true,
            'is_active' => true,
            'is_recommended' => false,
            'sort_order' => 3,
            'badge_text' => 'Enterprise',
            'badge_color' => 'dark',
            '_legacy_quota_based' => false,
            'target_margin' => 80.00,           // 80% target margin (enterprise premium)
        ]);
        
        echo "✅ Created 3 SaaS billing-first plans:\n";
        echo "   💎 Basic (Rp 149k) - Entry UMKM [NO FREE PLANS]\n";
        echo "   🚀 Growth (Rp 399k→349k) - Scaling Business [RECOMMENDED]\n"; 
        echo "   🏢 Pro (Rp 999k) - Enterprise Grade\n";
        echo "\n🎯 BILLING MODEL implemented:\n";
        echo "   ✅ SUBSCRIPTION = Features only (API, Analytics, etc)\n";
        echo "   ✅ TOPUP = Message credits (separate system)\n";
        echo "   ✅ NO FREE plans (all paid subscriptions)\n";
        echo "   ✅ NO MESSAGE quotas (pure feature value)\n";
        echo "   ✅ Clear value ladder for business growth\n";
        echo "   ✅ High target margins (70-80%)\n";
    }
}