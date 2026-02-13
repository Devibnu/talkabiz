<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add SSOT (Single Source of Truth) fields to plans table
 * 
 * Sebagai bagian dari arsitektur Modul Paket yang disetujui SA.
 * Field ini diperlukan untuk:
 * - is_popular: Menandai paket "Paling Populer" (hanya 1 boleh true)
 * - is_self_serve: Paket bisa dibeli langsung via landing page
 * - is_enterprise: Paket enterprise (hubungi sales)
 * - target_margin: Target margin untuk Auto Pricing Engine
 * 
 * @see SA Document: Modul Paket / Subscription Plan
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'is_self_serve')) {
                $table->boolean('is_self_serve')->default(true)
                      ->after('is_recommended')
                      ->comment('true = tampil di landing & bisa beli langsung');
            }
            
            if (!Schema::hasColumn('plans', 'is_enterprise')) {
                $table->boolean('is_enterprise')->default(false)
                      ->after('is_self_serve')
                      ->comment('true = paket enterprise, hubungi sales');
            }
            
            if (!Schema::hasColumn('plans', 'is_popular')) {
                $table->boolean('is_popular')->default(false)
                      ->after('is_enterprise')
                      ->comment('true = tampilkan badge Popular (max 1)');
            }
            
            if (!Schema::hasColumn('plans', 'target_margin')) {
                $table->decimal('target_margin', 5, 2)->nullable()
                      ->after('is_popular')
                      ->comment('Target margin % untuk pricing engine');
            }
        });

        // Add index safely
        try {
            Schema::table('plans', function (Blueprint $table) {
                $table->index(['is_active', 'is_self_serve'], 'idx_plans_landing');
            });
        } catch (\Exception $e) {
            // Index already exists
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex('idx_plans_landing');
            $table->dropColumn(['is_self_serve', 'is_enterprise', 'is_popular', 'target_margin']);
        });
    }
};
