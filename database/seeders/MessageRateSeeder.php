<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MessageRate;
use Illuminate\Support\Facades\DB;

/**
 * MessageRateSeeder - Initialize SaaS Billing Rates
 * 
 * Sets up configurable message rates for different message types and categories.
 * No hardcoded pricing - everything database-driven.
 */
class MessageRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('message_rates')->truncate();

        $rates = [
            // Text Messages
            [
                'type' => MessageRate::TYPE_TEXT,
                'category' => MessageRate::CATEGORY_GENERAL,
                'rate_per_message' => 150.00, // Rp 150 per text message
                'description' => 'Regular text message',
            ],
            [
                'type' => MessageRate::TYPE_TEXT,
                'category' => MessageRate::CATEGORY_MARKETING,
                'rate_per_message' => 200.00, // Rp 200 for marketing
                'description' => 'Marketing text message',
            ],
            [
                'type' => MessageRate::TYPE_TEXT,
                'category' => MessageRate::CATEGORY_UTILITY,
                'rate_per_message' => 100.00, // Rp 100 for utility (cheaper)
                'description' => 'Utility text message (notifications, updates)',
            ],
            [
                'type' => MessageRate::TYPE_TEXT,
                'category' => MessageRate::CATEGORY_AUTHENTICATION,
                'rate_per_message' => 100.00, // Rp 100 for auth (OTP, verification)
                'description' => 'Authentication text message (OTP, verification)',
            ],
            [
                'type' => MessageRate::TYPE_TEXT,
                'category' => MessageRate::CATEGORY_SERVICE,
                'rate_per_message' => 150.00, // Rp 150 for service
                'description' => 'Service text message (support, info)',
            ],

            // Media Messages (Images, Documents, etc.)
            [
                'type' => MessageRate::TYPE_MEDIA,
                'category' => MessageRate::CATEGORY_GENERAL,
                'rate_per_message' => 300.00, // Rp 300 per media message
                'description' => 'Media message (image, document, audio, video)',
            ],
            [
                'type' => MessageRate::TYPE_MEDIA,
                'category' => MessageRate::CATEGORY_MARKETING,
                'rate_per_message' => 400.00, // Rp 400 for marketing media
                'description' => 'Marketing media message',
            ],
            [
                'type' => MessageRate::TYPE_MEDIA,
                'category' => MessageRate::CATEGORY_UTILITY,
                'rate_per_message' => 250.00, // Rp 250 for utility media
                'description' => 'Utility media message',
            ],
            [
                'type' => MessageRate::TYPE_MEDIA,
                'category' => MessageRate::CATEGORY_SERVICE,
                'rate_per_message' => 300.00, // Rp 300 for service media
                'description' => 'Service media message',
            ],

            // Template Messages (WhatsApp Business templates)
            [
                'type' => MessageRate::TYPE_TEMPLATE,
                'category' => MessageRate::CATEGORY_GENERAL,
                'rate_per_message' => 500.00, // Rp 500 per template
                'description' => 'WhatsApp Business template message',
            ],
            [
                'type' => MessageRate::TYPE_TEMPLATE,
                'category' => MessageRate::CATEGORY_MARKETING,
                'rate_per_message' => 600.00, // Rp 600 for marketing template
                'description' => 'Marketing template message',
            ],
            [
                'type' => MessageRate::TYPE_TEMPLATE,
                'category' => MessageRate::CATEGORY_UTILITY,
                'rate_per_message' => 400.00, // Rp 400 for utility template
                'description' => 'Utility template message',
            ],
            [
                'type' => MessageRate::TYPE_TEMPLATE,
                'category' => MessageRate::CATEGORY_AUTHENTICATION,
                'rate_per_message' => 300.00, // Rp 300 for auth template (OTP)
                'description' => 'Authentication template message',
            ],
            [
                'type' => MessageRate::TYPE_TEMPLATE,
                'category' => MessageRate::CATEGORY_SERVICE,
                'rate_per_message' => 500.00, // Rp 500 for service template
                'description' => 'Service template message',
            ],

            // Campaign Messages (Broadcast/Bulk)
            [
                'type' => MessageRate::TYPE_CAMPAIGN,
                'category' => MessageRate::CATEGORY_GENERAL,
                'rate_per_message' => 175.00, // Rp 175 per campaign message
                'description' => 'Campaign/broadcast message',
            ],
            [
                'type' => MessageRate::TYPE_CAMPAIGN,
                'category' => MessageRate::CATEGORY_MARKETING,
                'rate_per_message' => 250.00, // Rp 250 for marketing campaign
                'description' => 'Marketing campaign message',
            ],
            [
                'type' => MessageRate::TYPE_CAMPAIGN,
                'category' => MessageRate::CATEGORY_SERVICE,
                'rate_per_message' => 200.00, // Rp 200 for service campaign
                'description' => 'Service announcement campaign',
            ],
        ];

        foreach ($rates as $rate) {
            MessageRate::create([
                'type' => $rate['type'],
                'category' => $rate['category'],
                'rate_per_message' => $rate['rate_per_message'],
                'currency' => 'IDR',
                'description' => $rate['description'],
                'is_active' => true,
                'metadata' => [
                    'created_by' => 'seeder',
                    'version' => '1.0',
                    'notes' => 'Initial SaaS billing rates',
                ],
                'effective_from' => now(),
                'effective_until' => null, // No expiry
            ]);
        }

        $this->command->info('Message rates seeded successfully!');
        $this->command->info('Total rates created: ' . count($rates));
        
        // Display rate summary
        $this->command->info('');
        $this->command->info('Rate Summary:');
        $this->command->info('- Text messages: Rp 100-200');
        $this->command->info('- Media messages: Rp 250-400');
        $this->command->info('- Template messages: Rp 300-600');
        $this->command->info('- Campaign messages: Rp 175-250');
        $this->command->info('');
        $this->command->info('💡 All rates are configurable via Owner Panel or database');
    }
}