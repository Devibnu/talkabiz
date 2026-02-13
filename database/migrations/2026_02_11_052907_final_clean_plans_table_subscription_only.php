<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FINAL CLEAN: Plans Table — Subscription Only
 * 
 * KONSEP:
 * - Plan = FITUR & AKSES saja
 * - Pesan WhatsApp = SALDO (Topup), terpisah
 * - Hapus semua quota, margin, cost estimation, legacy fields
 * 
 * TARGET SCHEMA (16 kolom):
 * id, code, name, description, price_monthly, duration_days,
 * is_active, is_visible, is_self_serve, is_popular,
 * max_wa_numbers, max_campaigns, max_recipients_per_campaign,
 * features (json), created_at, updated_at
 */
return new class extends Migration
{
    /**
     * Columns to DROP from plans table
     */
    private array $columnsToDrop = [
        // Legacy quota fields
        'quota_messages',
        'quota_contacts',
        'quota_campaigns',
        
        // Message limit fields (saldo handles this)
        'limit_messages_monthly',
        'limit_messages_daily',
        'limit_messages_hourly',
        'limit_wa_numbers',
        'limit_active_campaigns',
        'limit_recipients_per_campaign',
        
        // Duplicate/redundant capacity fields
        'max_campaign_recipients',
        'max_automation_flows',
        'max_team_members',
        
        // Feature boolean columns (now stored in features JSON)
        'has_advanced_automation',
        'api_access',
        'api_rate_limit',
        'webhook_support',
        'multi_agent_chat',
        'agent_performance_analytics',
        'advanced_analytics',
        'export_data',
        'report_retention_months',
        'support_level',
        'custom_branding',
        'priority_support',
        
        // Internal/legacy fields
        '_legacy_quota_based',
        '_migration_notes',
        
        // Status flags (consolidated)
        'is_purchasable',
        'is_recommended',
        'is_enterprise',
        
        // Margin & display fields  
        'target_margin',
        'sort_order',
        'badge_text',
        'badge_color',
        
        // Segment (no longer needed, self_serve flag sufficient)
        'segment',
        
        // Currency (always IDR)
        'currency',
        
        // Discount (handled differently now)
        'discount_price',
        
        // Soft deletes (plans should never be hard-deleted, use is_active)
        'deleted_at',
    ];

    public function up(): void
    {
        // 1. Rename price → price_monthly (MySQL 5.7 compatible, idempotent)
        if (Schema::hasColumn('plans', 'price') && !Schema::hasColumn('plans', 'price_monthly')) {
            DB::statement('ALTER TABLE plans CHANGE COLUMN `price` `price_monthly` DECIMAL(12,2) NOT NULL DEFAULT 0');
        }

        Schema::table('plans', function (Blueprint $table) {
            // 2. Add max_recipients_per_campaign if not exists
            if (!Schema::hasColumn('plans', 'max_recipients_per_campaign')) {
                $table->unsignedInteger('max_recipients_per_campaign')->default(100)->after('max_campaigns');
            }
        });

        // 3. Copy max_campaign_recipients → max_recipients_per_campaign before dropping
        if (Schema::hasColumn('plans', 'max_campaign_recipients') && Schema::hasColumn('plans', 'max_recipients_per_campaign')) {
            DB::statement('UPDATE plans SET max_recipients_per_campaign = max_campaign_recipients WHERE max_campaign_recipients > 0');
        }

        Schema::table('plans', function (Blueprint $table) {
            // 4. Drop deprecated columns (idempotent)
            foreach ($this->columnsToDrop as $col) {
                if (Schema::hasColumn('plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // 5. Ensure features column is JSON type
        if (Schema::hasColumn('plans', 'features')) {
            // Already exists, good
        } else {
            Schema::table('plans', function (Blueprint $table) {
                $table->json('features')->nullable()->after('max_recipients_per_campaign');
            });
        }
    }

    public function down(): void
    {
        // Rename back
        if (Schema::hasColumn('plans', 'price_monthly') && !Schema::hasColumn('plans', 'price')) {
            DB::statement('ALTER TABLE plans CHANGE COLUMN `price_monthly` `price` DECIMAL(12,2) NOT NULL DEFAULT 0');
        }

        // Re-add dropped columns with defaults
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'segment')) {
                $table->string('segment', 20)->default('umkm')->after('description');
            }
            if (!Schema::hasColumn('plans', 'currency')) {
                $table->string('currency', 3)->default('IDR')->after('price');
            }
            if (!Schema::hasColumn('plans', 'discount_price')) {
                $table->decimal('discount_price', 12, 2)->nullable()->after('currency');
            }
            if (!Schema::hasColumn('plans', 'quota_messages')) {
                $table->unsignedInteger('quota_messages')->default(0)->after('duration_days');
            }
            if (!Schema::hasColumn('plans', 'quota_contacts')) {
                $table->unsignedInteger('quota_contacts')->default(0)->after('quota_messages');
            }
            if (!Schema::hasColumn('plans', 'quota_campaigns')) {
                $table->unsignedInteger('quota_campaigns')->default(0)->after('quota_contacts');
            }
            if (!Schema::hasColumn('plans', 'limit_messages_monthly')) {
                $table->unsignedInteger('limit_messages_monthly')->nullable()->after('quota_campaigns');
            }
            if (!Schema::hasColumn('plans', 'limit_messages_daily')) {
                $table->unsignedInteger('limit_messages_daily')->nullable()->after('limit_messages_monthly');
            }
            if (!Schema::hasColumn('plans', 'limit_messages_hourly')) {
                $table->unsignedInteger('limit_messages_hourly')->nullable()->after('limit_messages_daily');
            }
            if (!Schema::hasColumn('plans', 'limit_wa_numbers')) {
                $table->unsignedInteger('limit_wa_numbers')->nullable()->after('limit_messages_hourly');
            }
            if (!Schema::hasColumn('plans', 'limit_active_campaigns')) {
                $table->unsignedInteger('limit_active_campaigns')->nullable()->after('limit_wa_numbers');
            }
            if (!Schema::hasColumn('plans', 'limit_recipients_per_campaign')) {
                $table->unsignedInteger('limit_recipients_per_campaign')->nullable()->after('limit_active_campaigns');
            }
            if (!Schema::hasColumn('plans', 'deleted_at')) {
                $table->softDeletes();
            }
            // Other columns with safe defaults
            $boolColumns = ['is_purchasable', 'is_recommended', 'is_enterprise', '_legacy_quota_based',
                'has_advanced_automation', 'api_access', 'webhook_support', 'multi_agent_chat',
                'agent_performance_analytics', 'advanced_analytics', 'export_data', 'custom_branding', 'priority_support'];
            foreach ($boolColumns as $col) {
                if (!Schema::hasColumn('plans', $col)) {
                    $table->boolean($col)->default(false);
                }
            }
            $intColumns = ['api_rate_limit', 'max_campaign_recipients', 'max_automation_flows', 'max_team_members',
                'report_retention_months', 'sort_order'];
            foreach ($intColumns as $col) {
                if (!Schema::hasColumn('plans', $col)) {
                    $table->unsignedInteger($col)->default(0);
                }
            }
            if (!Schema::hasColumn('plans', 'target_margin')) {
                $table->decimal('target_margin', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('plans', 'support_level')) {
                $table->string('support_level', 20)->default('basic');
            }
            if (!Schema::hasColumn('plans', '_migration_notes')) {
                $table->text('_migration_notes')->nullable();
            }
            if (!Schema::hasColumn('plans', 'badge_text')) {
                $table->string('badge_text', 50)->nullable();
            }
            if (!Schema::hasColumn('plans', 'badge_color')) {
                $table->string('badge_color', 20)->nullable();
            }
        });
    }
};
