<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add pricing_multiplier for business type segmentation:
     * - Multiplier determines final cost: base_price × multiplier
     * - Default 1.00 = standard pricing
     * - < 1.00 = discount for enterprise/volume customers
     * - > 1.00 = premium pricing (if needed)
     */
    public function up(): void
    {
        if (!Schema::hasTable('business_types')) {
            return;
        }

        Schema::table('business_types', function (Blueprint $table) {
            if (!Schema::hasColumn('business_types', 'pricing_multiplier')) {
                $table->decimal('pricing_multiplier', 5, 2)
                    ->default(1.00)
                    ->after('display_order')
                    ->comment('Price multiplier: base × this = final cost');
            }
        });

        try {
            Schema::table('business_types', function (Blueprint $table) {
                $table->index('pricing_multiplier');
            });
        } catch (\Exception $e) {
            // Index already exists
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_types', function (Blueprint $table) {
            $table->dropIndex(['pricing_multiplier']);
            $table->dropColumn('pricing_multiplier');
        });
    }
};
