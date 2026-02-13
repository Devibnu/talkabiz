<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Standalone Migration for meta_costs table
 * 
 * This creates the meta_costs table independently to unblock Plan creation.
 * The table stores Meta/Gupshup costs per message category.
 * 
 * Categories:
 * - marketing: Broadcast, campaign, promo messages
 * - utility: Order notifications, shipping updates  
 * - authentication: OTP, verification codes
 * - service: Reply to customer (usually free)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if table already exists (from enhance_auto_pricing_system migration)
        if (Schema::hasTable('meta_costs')) {
            return;
        }

        Schema::create('meta_costs', function (Blueprint $table) {
            $table->id();
            
            // Category identifier (unique)
            $table->string('category', 50)->unique();
            
            // Display name for UI
            $table->string('display_name', 100);
            
            // Cost per message in IDR (decimal for precision)
            $table->decimal('cost_per_message', 10, 2)->default(0);
            
            // Source of the cost data
            $table->string('source', 50)->default('initial'); // initial, manual, api, gupshup
            
            // When this cost became effective
            $table->timestamp('effective_from')->useCurrent();
            
            // Historical tracking
            $table->decimal('previous_cost', 10, 2)->nullable();
            $table->timestamp('previous_cost_date')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });

        // Seed default values with safe estimates
        // These are approximate costs based on Indonesia pricing
        $this->seedDefaultCosts();
    }

    /**
     * Seed default Meta costs
     */
    private function seedDefaultCosts(): void
    {
        $now = now();
        
        $costs = [
            [
                'category' => 'marketing',
                'display_name' => 'Marketing',
                'cost_per_message' => 500.00, // Rp 500 per message (estimate)
                'source' => 'initial',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'category' => 'utility',
                'display_name' => 'Utility',
                'cost_per_message' => 300.00, // Rp 300 per message (estimate)
                'source' => 'initial',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'category' => 'authentication',
                'display_name' => 'Authentication',
                'cost_per_message' => 350.00, // Rp 350 per message (estimate)
                'source' => 'initial',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'category' => 'service',
                'display_name' => 'Service',
                'cost_per_message' => 0.00, // Free (customer-initiated)
                'source' => 'initial',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('meta_costs')->insertOrIgnore($costs);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_costs');
    }
};
