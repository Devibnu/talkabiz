<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            KlienSeeder::class,
            PenggunaSeeder::class,
            TemplateSeeder::class,
            LandingContentSeeder::class,
            ProductionPlanSeeder::class,
            // ExamplePlanSeeder::class, // Uncomment for fresh installations with example plans
        ]);
    }
}
