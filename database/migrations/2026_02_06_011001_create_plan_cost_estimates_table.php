<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Plan Cost Estimates Table
 *
 * Menyimpan estimasi biaya per kategori untuk simulasi nilai paket.
 * Per plan bisa punya berbeda estimasi usage per kategori.
 *
 * @author Senior Laravel SaaS Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plan_cost_estimates')) {
            Schema::create('plan_cost_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')
                  ->constrained('plans')
                  ->onDelete('cascade')
                  ->comment('Reference ke plans');

            // Estimasi penggunaan per kategori (untuk simulasi nilai paket)
            $table->unsignedInteger('estimate_marketing')->default(0)
                  ->comment('Estimasi pesan marketing per bulan');

            $table->unsignedInteger('estimate_utility')->default(0)
                  ->comment('Estimasi pesan utility per bulan');

            $table->unsignedInteger('estimate_authentication')->default(0)
                  ->comment('Estimasi pesan authentication per bulan');

            $table->unsignedInteger('estimate_service')->default(0)
                  ->comment('Estimasi pesan service per bulan');

            // Metadata
            $table->timestamps();

            // Index
            $table->unique('plan_id');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_cost_estimates');
    }
};
