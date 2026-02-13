<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Enhanced Auto Pricing System
 * 
 * PART A: AUTO PRICING ENGINE (ANTI BONCOS)
 * 
 * ENHANCEMENTS:
 * 1. Per-category Meta cost tracking
 * 2. Client risk level system
 * 3. Warmup state integration
 * 4. Enhanced owner controls
 * 5. Client-facing simple pricing
 * 
 * FORMULA PRICING:
 * ================
 * client_price = meta_cost × (1 + base_margin) × health_factor × risk_factor × warmup_factor
 * 
 * FACTORS:
 * - Health A: ×1.0 (normal margin)
 * - Health B: ×1.05 (+5%)
 * - Health C: ×1.15 (+15%)
 * - Health D: BLOCKED
 * 
 * - Risk Low: ×1.0
 * - Risk Medium: ×1.05
 * - Risk High: ×1.10
 * 
 * - Warmup NEW/WARMING: ×1.0 (limited volume)
 * - Warmup STABLE: ×1.0 (full capacity)
 * - Warmup COOLDOWN: BLOCKED
 * 
 * GUARDRAILS:
 * - min_margin: Owner dapat set minimum margin (default 20%)
 * - max_discount: Batas diskon maksimal per kategori
 * - locked_pricing: Lock harga per plan agar tidak auto-adjust
 * - category_override: Owner dapat override harga per kategori
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==========================================
        // 1. META COST PER CATEGORY
        // ==========================================
        if (!Schema::hasTable('meta_costs')) {
            Schema::create('meta_costs', function (Blueprint $table) {
            $table->id();
            
            // Category (marketing, utility, authentication, service)
            $table->string('category', 50)->unique();
            
            // Cost from Meta/Gupshup (in IDR)
            $table->decimal('cost_per_message', 10, 2);
            
            // Display name
            $table->string('display_name', 100);
            
            // Last update source
            $table->string('source', 50)->default('manual'); // manual, api, gupshup
            
            // Effective date
            $table->timestamp('effective_from')->useCurrent();
            
            // History tracking
            $table->decimal('previous_cost', 10, 2)->nullable();
            $table->timestamp('previous_cost_date')->nullable();
            
            $table->timestamps();
        });
}

        // ==========================================
        // 2. CLIENT RISK LEVELS
        // ==========================================
        if (!Schema::hasTable('client_risk_levels')) {
            Schema::create('client_risk_levels', function (Blueprint $table) {
            $table->id();
            
            // Client reference
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            
            // Risk level
            $table->enum('risk_level', ['low', 'medium', 'high', 'blocked'])->default('low');
            
            // Risk score (calculated)
            $table->decimal('risk_score', 5, 2)->default(0); // 0-100
            
            // Factors that determine risk
            $table->decimal('payment_score', 5, 2)->default(100); // Payment history
            $table->decimal('usage_score', 5, 2)->default(100); // Usage patterns
            $table->decimal('health_score', 5, 2)->default(100); // Their health impact
            $table->decimal('violation_score', 5, 2)->default(100); // Policy violations
            
            // Risk-based adjustments
            $table->decimal('margin_adjustment_percent', 5, 2)->default(0); // Extra margin
            $table->decimal('max_discount_percent', 5, 2)->default(10); // Max discount allowed
            $table->boolean('pricing_locked', )->default(false); // Lock their pricing
            
            // Auto-calculated
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('failed_payments')->default(0);
            $table->unsignedInteger('late_payments')->default(0);
            $table->unsignedInteger('violations_count')->default(0);
            
            // Timestamps
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();
            
            $table->unique('klien_id');
        });
}

        // ==========================================
        // 3. OWNER PRICING CONTROLS
        // ==========================================
        // Add owner control fields to pricing_settings
        Schema::table('pricing_settings', function (Blueprint $table) {
            // Global controls
            if (!Schema::hasColumn('pricing_settings', 'global_minimum_margin')) {
                $table->decimal('global_minimum_margin', 5, 2)->default(20)->after('max_margin_percent');
            }
            if (!Schema::hasColumn('pricing_settings', 'global_max_discount')) {
                $table->decimal('global_max_discount', 5, 2)->default(15)->after('global_minimum_margin');
            }
            
            // Plan-level lock
            if (!Schema::hasColumn('pricing_settings', 'lock_plan_pricing')) {
                $table->boolean('lock_plan_pricing')->default(false)->after('global_max_discount');
            }
            
            // Meta cost alert threshold
            if (!Schema::hasColumn('pricing_settings', 'meta_cost_alert_threshold')) {
                $table->decimal('meta_cost_alert_threshold', 5, 2)->default(10)->after('lock_plan_pricing');
            }
            
            // Auto-adjust on warmup state change
            if (!Schema::hasColumn('pricing_settings', 'adjust_on_warmup_change')) {
                $table->boolean('adjust_on_warmup_change')->default(true)->after('adjust_on_health_drop');
            }
            
            // Risk-based pricing enabled
            if (!Schema::hasColumn('pricing_settings', 'risk_pricing_enabled')) {
                $table->boolean('risk_pricing_enabled')->default(true)->after('adjust_on_warmup_change');
            }
            
            // Category-level pricing enabled (vs flat pricing)
            if (!Schema::hasColumn('pricing_settings', 'category_pricing_enabled')) {
                $table->boolean('category_pricing_enabled')->default(true)->after('risk_pricing_enabled');
            }
        });

        // ==========================================
        // 4. CATEGORY PRICING OVERRIDES (Owner)
        // ==========================================
        if (!Schema::hasTable('category_pricing_overrides')) {
            Schema::create('category_pricing_overrides', function (Blueprint $table) {
            $table->id();
            
            // Category
            $table->string('category', 50)->unique();
            
            // Override values (null = use auto-calculated)
            $table->decimal('override_price', 10, 2)->nullable();
            $table->decimal('override_margin', 5, 2)->nullable();
            $table->decimal('min_margin_override', 5, 2)->nullable();
            $table->decimal('max_discount_override', 5, 2)->nullable();
            
            // Is this category locked from auto-adjust?
            $table->boolean('is_locked')->default(false);
            
            // Display settings for client
            $table->string('client_display_name', 100)->nullable();
            $table->text('client_description')->nullable();
            
            // Who set this override
            $table->foreignId('set_by')->nullable()->constrained('pengguna');
            
            $table->timestamps();
        });
}

        // ==========================================
        // 5. CLIENT PRICING DISPLAY (Simple view for clients)
        // ==========================================
        if (!Schema::hasTable('client_pricing_cache')) {
            Schema::create('client_pricing_cache', function (Blueprint $table) {
            $table->id();
            
            // Client reference (null = default for all clients)
            $table->foreignId('klien_id')->nullable()->constrained('klien')->cascadeOnDelete();
            
            // Display pricing (what client sees)
            $table->decimal('display_price_per_message', 10, 2); // Flat price shown
            $table->string('display_label', 100)->default('Harga per Pesan');
            
            // Category breakdown (hidden details)
            $table->decimal('marketing_price', 10, 2);
            $table->decimal('utility_price', 10, 2);
            $table->decimal('authentication_price', 10, 2);
            $table->decimal('service_price', 10, 2);
            
            // Estimated messages (for display)
            $table->unsignedInteger('estimated_messages_per_10k')->default(0);
            
            // Last calculation
            $table->json('calculation_details')->nullable();
            $table->timestamp('calculated_at')->nullable();
            
            $table->timestamps();
            
            $table->unique('klien_id');
        });
}

        // ==========================================
        // 6. PRICING ALERTS LOG
        // ==========================================
        if (!Schema::hasTable('pricing_alerts')) {
            Schema::create('pricing_alerts', function (Blueprint $table) {
            $table->id();
            
            // Alert type
            $table->enum('alert_type', [
                'margin_low',           // Margin below threshold
                'meta_cost_increase',   // Meta cost went up
                'meta_cost_decrease',   // Meta cost went down
                'client_risk_high',     // Client became high risk
                'pricing_blocked',      // Pricing calculation blocked
                'warmup_impact',        // Warmup state affected pricing
                'health_impact',        // Health score affected pricing
            ]);
            
            // Severity
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            
            // Context
            $table->foreignId('klien_id')->nullable()->constrained('klien');
            $table->string('category', 50)->nullable();
            
            // Details
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            
            // Resolution
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('pengguna');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            
            // Notification sent
            $table->boolean('notification_sent')->default(false);
            $table->string('notification_channel')->nullable(); // telegram, email
            
            $table->timestamps();
            
            $table->index(['alert_type', 'is_resolved']);
            $table->index(['severity', 'created_at']);
        });
}

        // ==========================================
        // 7. INSERT DEFAULT META COSTS
        // ==========================================
        DB::table('meta_costs')->insertOrIgnore([
            [
                'category' => 'marketing',
                'display_name' => 'Marketing',
                'cost_per_message' => 450, // Meta cost in IDR
                'source' => 'initial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'utility',
                'display_name' => 'Utility',
                'cost_per_message' => 200,
                'source' => 'initial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'authentication',
                'display_name' => 'Authentication',
                'cost_per_message' => 250,
                'source' => 'initial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'service',
                'display_name' => 'Service',
                'cost_per_message' => 0, // Free for service/replies
                'source' => 'initial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // ==========================================
        // 8. INSERT DEFAULT CATEGORY OVERRIDES
        // ==========================================
        DB::table('category_pricing_overrides')->insertOrIgnore([
            [
                'category' => 'marketing',
                'client_display_name' => 'Pesan Promosi',
                'client_description' => 'Broadcast, campaign, promo',
                'override_price' => null,
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'utility',
                'client_display_name' => 'Notifikasi',
                'client_description' => 'Order, pengiriman, update status',
                'override_price' => null,
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'authentication',
                'client_display_name' => 'Verifikasi',
                'client_description' => 'OTP, konfirmasi akun',
                'override_price' => null,
                'is_locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'service',
                'client_display_name' => 'Balas Pesan',
                'client_description' => 'Reply inbox, chat',
                'override_price' => 0, // Free
                'is_locked' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // ==========================================
        // 9. INSERT DEFAULT CLIENT PRICING CACHE (Global)
        // ==========================================
        DB::table('client_pricing_cache')->insertOrIgnore([
            'klien_id' => null, // Global default
            'display_price_per_message' => 500, // Rp 500 flat display
            'display_label' => 'Harga per Pesan',
            'marketing_price' => 600,
            'utility_price' => 300,
            'authentication_price' => 350,
            'service_price' => 0,
            'estimated_messages_per_10k' => 20, // Rp 10,000 = ~20 messages
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_alerts');
        Schema::dropIfExists('client_pricing_cache');
        Schema::dropIfExists('category_pricing_overrides');
        Schema::dropIfExists('client_risk_levels');
        Schema::dropIfExists('meta_costs');

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'global_minimum_margin',
                'global_max_discount',
                'lock_plan_pricing',
                'meta_cost_alert_threshold',
                'adjust_on_warmup_change',
                'risk_pricing_enabled',
                'category_pricing_enabled',
            ]);
        });
    }
};
