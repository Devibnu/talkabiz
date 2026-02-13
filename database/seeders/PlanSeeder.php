<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

/**
 * PlanSeeder
 * 
 * Seeder untuk data paket WA Blast.
 * 
 * SEGMENT:
 * - UMKM: 3 paket (Starter, Growth, Pro)
 * - Corporate: 2 paket (Business, Enterprise)
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // ==================== UMKM PLANS ====================
            [
                'code' => 'umkm-starter',
                'name' => 'Starter',
                'description' => 'Paket starter GRATIS untuk UMKM yang baru memulai. Auto-assign saat registrasi.',
                'segment' => Plan::SEGMENT_UMKM,
                'price' => 0, // FREE - auto-assign saat register
                'currency' => 'IDR',
                'discount_price' => null,
                'duration_days' => 30,
                'quota_messages' => 1000,
                'quota_contacts' => 500,
                'quota_campaigns' => 3,
                'limit_messages_monthly' => 1000,
                'limit_messages_daily' => 50,
                'limit_messages_hourly' => 20,
                'limit_wa_numbers' => 1,
                'limit_active_campaigns' => 3,
                'limit_recipients_per_campaign' => 200,
                'features' => [
                    Plan::FEATURE_INBOX,
                    Plan::FEATURE_CAMPAIGN,
                    Plan::FEATURE_TEMPLATE,
                ],
                'is_purchasable' => false, // Cannot be purchased, auto-assigned on register
                'is_visible' => true,
                'is_active' => true,
                'is_recommended' => false,
                'is_self_serve' => true,
                'is_enterprise' => false,
                'is_popular' => false,
                'target_margin' => null,
                'sort_order' => 0, // First in list
                'badge_text' => 'Free',
                'badge_color' => 'success',
            ],
            [
                'code' => 'umkm-growth',
                'name' => 'Growth',
                'description' => 'Untuk UMKM yang sudah mulai berkembang. Kuota lebih besar dan fitur lebih lengkap.',
                'segment' => Plan::SEGMENT_UMKM,
                'price' => 350000,
                'currency' => 'IDR',
                'discount_price' => 299000,
                'duration_days' => 30,
                'quota_messages' => 5000,
                'quota_contacts' => 2000,
                'quota_campaigns' => 10,
                'limit_messages_monthly' => 5000,
                'limit_messages_daily' => 200,
                'limit_messages_hourly' => 50,
                'limit_wa_numbers' => 2,
                'limit_active_campaigns' => 10,
                'limit_recipients_per_campaign' => 1000,
                'features' => [
                    Plan::FEATURE_INBOX,
                    Plan::FEATURE_CAMPAIGN,
                    Plan::FEATURE_BROADCAST,
                    Plan::FEATURE_TEMPLATE,
                    Plan::FEATURE_ANALYTICS,
                ],
                'is_purchasable' => true,
                'is_visible' => true,
                'is_active' => true,
                'is_recommended' => true,
                'is_self_serve' => true,
                'is_enterprise' => false,
                'is_popular' => true, // Default popular
                'target_margin' => 25.00,
                'sort_order' => 2,
                'badge_text' => 'Paling Populer',
                'badge_color' => 'warning',
            ],
            [
                'code' => 'umkm-pro',
                'name' => 'Pro',
                'description' => 'Untuk UMKM yang serius dengan WhatsApp marketing. Kuota besar dan semua fitur terbuka.',
                'segment' => Plan::SEGMENT_UMKM,
                'price' => 750000,
                'currency' => 'IDR',
                'discount_price' => null,
                'duration_days' => 30,
                'quota_messages' => 15000,
                'quota_contacts' => 5000,
                'quota_campaigns' => 25,
                'limit_messages_monthly' => 15000,
                'limit_messages_daily' => 500,
                'limit_messages_hourly' => 100,
                'limit_wa_numbers' => 5,
                'limit_active_campaigns' => 25,
                'limit_recipients_per_campaign' => 3000,
                'features' => [
                    Plan::FEATURE_INBOX,
                    Plan::FEATURE_CAMPAIGN,
                    Plan::FEATURE_BROADCAST,
                    Plan::FEATURE_FLOW,
                    Plan::FEATURE_TEMPLATE,
                    Plan::FEATURE_MULTI_AGENT,
                    Plan::FEATURE_ANALYTICS,
                    Plan::FEATURE_EXPORT,
                ],
                'is_purchasable' => true,
                'is_visible' => true,
                'is_active' => true,
                'is_recommended' => false,
                'is_self_serve' => true,
                'is_enterprise' => false,
                'is_popular' => false,
                'target_margin' => 30.00,
                'sort_order' => 3,
                'badge_text' => 'Popular',
                'badge_color' => 'success',
            ],

            // ==================== CORPORATE PLANS ====================
            [
                'code' => 'corp-business',
                'name' => 'Business',
                'description' => 'Untuk perusahaan menengah. Kuota besar dengan dukungan dedicated support.',
                'segment' => Plan::SEGMENT_CORPORATE,
                'price' => 2500000,
                'currency' => 'IDR',
                'discount_price' => null,
                'duration_days' => 30,
                'quota_messages' => 50000,
                'quota_contacts' => 20000,
                'quota_campaigns' => 50,
                'limit_messages_monthly' => 50000,
                'limit_messages_daily' => 2000,
                'limit_messages_hourly' => 500,
                'limit_wa_numbers' => 10,
                'limit_active_campaigns' => 50,
                'limit_recipients_per_campaign' => 10000,
                'features' => [
                    Plan::FEATURE_INBOX,
                    Plan::FEATURE_CAMPAIGN,
                    Plan::FEATURE_BROADCAST,
                    Plan::FEATURE_FLOW,
                    Plan::FEATURE_TEMPLATE,
                    Plan::FEATURE_MULTI_AGENT,
                    Plan::FEATURE_ANALYTICS,
                    Plan::FEATURE_EXPORT,
                    Plan::FEATURE_API,
                ],
                'is_purchasable' => false, // Corporate = manual assign
                'is_visible' => true,
                'is_active' => true,
                'is_recommended' => false,
                'is_self_serve' => false,
                'is_enterprise' => true,
                'is_popular' => false,
                'target_margin' => 35.00,
                'sort_order' => 10,
                'badge_text' => 'Corporate',
                'badge_color' => 'info',
            ],
            [
                'code' => 'corp-enterprise',
                'name' => 'Enterprise',
                'description' => 'Untuk enterprise besar. Unlimited kuota dengan dedicated account manager.',
                'segment' => Plan::SEGMENT_CORPORATE,
                'price' => 7500000,
                'currency' => 'IDR',
                'discount_price' => null,
                'duration_days' => 30,
                'quota_messages' => 200000,
                'quota_contacts' => 100000,
                'quota_campaigns' => 100,
                'limit_messages_monthly' => null, // Unlimited
                'limit_messages_daily' => null, // Unlimited
                'limit_messages_hourly' => null, // Unlimited
                'limit_wa_numbers' => null, // Unlimited
                'limit_active_campaigns' => null, // Unlimited
                'limit_recipients_per_campaign' => null, // Unlimited
                'features' => [
                    Plan::FEATURE_INBOX,
                    Plan::FEATURE_CAMPAIGN,
                    Plan::FEATURE_BROADCAST,
                    Plan::FEATURE_FLOW,
                    Plan::FEATURE_TEMPLATE,
                    Plan::FEATURE_MULTI_AGENT,
                    Plan::FEATURE_ANALYTICS,
                    Plan::FEATURE_EXPORT,
                    Plan::FEATURE_API,
                ],
                'is_purchasable' => false, // Corporate = manual assign
                'is_visible' => true,
                'is_active' => true,
                'is_recommended' => false,
                'is_self_serve' => false,
                'is_enterprise' => true,
                'is_popular' => false,
                'target_margin' => 40.00,
                'sort_order' => 11,
                'badge_text' => 'Enterprise',
                'badge_color' => 'dark',
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['code' => $planData['code']],
                $planData
            );
        }

        $this->command->info('✅ Plans seeded with SSOT fields: 3 UMKM + 2 Corporate');
    }
}
