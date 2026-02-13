<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionPlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::table('plans')->delete();

            DB::table('plans')->insert([
                [
                    'code'                        => 'basic',
                    'name'                        => 'Basic',
                    'description'                 => 'Paket UMKM Starter',
                    'price_monthly'               => 149000,
                    'duration_days'               => 30,
                    'is_active'                   => 1,
                    'is_visible'                  => 1,
                    'is_self_serve'               => 1,
                    'is_popular'                  => 0,
                    'sort_order'                  => 10,
                    'max_wa_numbers'              => 1,
                    'max_campaigns'               => 5,
                    'max_recipients_per_campaign' => 2000,
                    'features'                    => json_encode(['broadcast', 'inbox', 'template']),
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ],
                [
                    'code'                        => 'growth',
                    'name'                        => 'Growth',
                    'description'                 => 'Paket Bisnis Berkembang',
                    'price_monthly'               => 349000,
                    'duration_days'               => 30,
                    'is_active'                   => 1,
                    'is_visible'                  => 1,
                    'is_self_serve'               => 1,
                    'is_popular'                  => 1,
                    'sort_order'                  => 20,
                    'max_wa_numbers'              => 3,
                    'max_campaigns'               => 15,
                    'max_recipients_per_campaign' => 5000,
                    'features'                    => json_encode(['broadcast', 'inbox', 'template', 'analytics', 'api']),
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ],
                [
                    'code'                        => 'pro',
                    'name'                        => 'Pro',
                    'description'                 => 'Paket Enterprise',
                    'price_monthly'               => 999000,
                    'duration_days'               => 30,
                    'is_active'                   => 1,
                    'is_visible'                  => 1,
                    'is_self_serve'               => 0,
                    'is_popular'                  => 0,
                    'sort_order'                  => 30,
                    'max_wa_numbers'              => 10,
                    'max_campaigns'               => 9999,
                    'max_recipients_per_campaign' => 20000,
                    'features'                    => json_encode(['broadcast', 'inbox', 'template', 'analytics', 'api', 'multi_agent', 'webhook']),
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ],
            ]);
        });

        $this->command->info('✅ 3 production plans seeded: Basic, Growth, Pro');
    }
}
