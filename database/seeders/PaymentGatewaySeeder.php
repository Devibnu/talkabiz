<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Midtrans Gateway
        PaymentGateway::updateOrCreate(
            ['name' => 'midtrans'],
            [
                'display_name' => 'Midtrans',
                'description' => 'Payment gateway untuk Indonesia (QRIS, GoPay, OVO, Bank Transfer, dll)',
                'is_enabled' => false,
                'is_active' => false,
                'environment' => 'sandbox',
                'settings' => [
                    'expiry_duration' => 60,
                    'payment_methods' => ['gopay', 'shopeepay', 'qris', 'bank_transfer'],
                ],
            ]
        );

        // Xendit Gateway
        PaymentGateway::updateOrCreate(
            ['name' => 'xendit'],
            [
                'display_name' => 'Xendit',
                'description' => 'Payment gateway untuk Southeast Asia (Virtual Account, E-Wallet, dll)',
                'is_enabled' => false,
                'is_active' => false,
                'environment' => 'sandbox',
                'settings' => [
                    'invoice_duration' => 86400,
                    'payment_methods' => ['ewallet', 'virtual_account', 'retail_outlet'],
                ],
            ]
        );

        $this->command->info('Payment gateways seeded successfully.');
    }
}
