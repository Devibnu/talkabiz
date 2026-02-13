<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Seed common Indonesian business types
     * 
     * COMPATIBILITY NOTE:
     * - Codes use lowercase to match existing klien.tipe_bisnis ENUM
     * - DO NOT change these codes without migrating klien table first
     * 
     * DEFAULT LIMITS:
     * - Applied automatically during onboarding
     * - Can be overridden by owner per user
     * - Scaled based on business type and risk level
     */
    public function run(): void
    {
        $businessTypes = [
            [
                'code' => 'perorangan',
                'name' => 'Perorangan / Individu',
                'description' => 'Usaha yang dimiliki dan dijalankan oleh individu (pribadi)',
                'is_active' => true,
                'display_order' => 10,
                'pricing_multiplier' => 1.00, // Standard pricing
                'risk_level' => 'medium',
                'minimum_balance_buffer' => 50000, // Rp 50k buffer
                'requires_manual_approval' => false,
                'default_limits' => [
                    'max_active_campaign' => 1,
                    'daily_message_quota' => 100,
                    'monthly_message_quota' => 1000,
                    'campaign_send_enabled' => true,
                ],
            ],
            [
                'code' => 'cv',
                'name' => 'CV (Commanditaire Vennootschap)',
                'description' => 'Persekutuan Komanditer - badan usaha yang didirikan atas dasar kepercayaan',
                'is_active' => true,
                'display_order' => 20,
                'pricing_multiplier' => 0.95, // 5% discount untuk CV
                'risk_level' => 'low',
                'minimum_balance_buffer' => 25000, // Rp 25k buffer (lower risk)
                'requires_manual_approval' => false,
                'default_limits' => [
                    'max_active_campaign' => 3,
                    'daily_message_quota' => 250,
                    'monthly_message_quota' => 3000,
                    'campaign_send_enabled' => true,
                ],
            ],
            [
                'code' => 'pt',
                'name' => 'PT (Perseroan Terbatas)',
                'description' => 'Badan hukum berbentuk persekutuan modal yang didirikan berdasarkan perjanjian',
                'is_active' => true,
                'display_order' => 30,
                'pricing_multiplier' => 0.90, // 10% discount untuk PT (enterprise)
                'risk_level' => 'low',
                'minimum_balance_buffer' => 0, // No buffer (trusted enterprise)
                'requires_manual_approval' => false,
                'default_limits' => [
                    'max_active_campaign' => 5,
                    'daily_message_quota' => 500,
                    'monthly_message_quota' => 10000,
                    'campaign_send_enabled' => true,
                ],
            ],
            [
                'code' => 'ud',
                'name' => 'UD (Usaha Dagang)',
                'description' => 'Bentuk usaha perseorangan atau kelompok kecil yang bergerak di bidang perdagangan',
                'is_active' => true,
                'display_order' => 40,
                'pricing_multiplier' => 0.98, // 2% discount untuk UD
                'risk_level' => 'medium',
                'minimum_balance_buffer' => 50000, // Rp 50k buffer
                'requires_manual_approval' => false,
                'default_limits' => [
                    'max_active_campaign' => 2,
                    'daily_message_quota' => 150,
                    'monthly_message_quota' => 2000,
                    'campaign_send_enabled' => true,
                ],
            ],
            [
                'code' => 'lainnya',
                'name' => 'Lainnya',
                'description' => 'Tipe bisnis lainnya yang tidak termasuk kategori di atas',
                'is_active' => true,
                'display_order' => 100,
                'pricing_multiplier' => 1.00, // Standard pricing
                'risk_level' => 'high',
                'minimum_balance_buffer' => 100000, // Rp 100k buffer (high risk)
                'requires_manual_approval' => true, // Requires approval
                'default_limits' => [
                    'max_active_campaign' => 1,
                    'daily_message_quota' => 50,
                    'monthly_message_quota' => 500,
                    'campaign_send_enabled' => false, // Needs manual activation
                ],
            ],
        ];

        foreach ($businessTypes as $type) {
            BusinessType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }

        $this->command->info('✅ Seeded ' . count($businessTypes) . ' business types (compatible with klien.tipe_bisnis ENUM)');
    }
}
