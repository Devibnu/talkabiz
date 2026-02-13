<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menambahkan kolom untuk tracking cost & revenue secara akurat.
     */
    public function up(): void
    {
        // ==================== WHATSAPP MESSAGE LOGS ====================
        // Tambah kolom revenue & cost_price untuk tracking profit per pesan
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            // Cost dari Gupshup (cost modal)
            if (!Schema::hasColumn('whatsapp_message_logs', 'cost_price')) {
                $table->decimal('cost_price', 10, 4)->default(0)->after('cost')
                    ->comment('Harga modal dari Gupshup per message');
            }
            
            // Revenue yang dicharge ke user
            if (!Schema::hasColumn('whatsapp_message_logs', 'revenue')) {
                $table->decimal('revenue', 10, 4)->default(0)->after('cost_price')
                    ->comment('Revenue yang dicharge ke user');
            }
            
            // Profit = revenue - cost_price
            if (!Schema::hasColumn('whatsapp_message_logs', 'profit')) {
                $table->decimal('profit', 10, 4)->default(0)->after('revenue')
                    ->comment('Profit per message');
            }
            
            // Category pricing yang digunakan
            if (!Schema::hasColumn('whatsapp_message_logs', 'pricing_category')) {
                $table->string('pricing_category', 50)->nullable()->after('profit')
                    ->comment('Kategori pricing: marketing, utility, auth, service');
            }
            
            // Index untuk agregasi — moved to try-catch below
        });

        // Add indexes safely
        try {
            Schema::table('whatsapp_message_logs', function (Blueprint $table) {
                $table->index(['klien_id', 'created_at'], 'wml_klien_date_idx');
                $table->index(['campaign_id', 'status'], 'wml_campaign_status_idx');
                $table->index(['pricing_category', 'created_at'], 'wml_category_date_idx');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }

        // ==================== WHATSAPP CAMPAIGNS ====================
        // Tambah kolom profit tracking per campaign
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_campaigns', 'total_cost_price')) {
                $table->decimal('total_cost_price', 14, 2)->default(0)->after('actual_cost')
                    ->comment('Total cost modal dari Gupshup');
            }
            
            if (!Schema::hasColumn('whatsapp_campaigns', 'total_revenue')) {
                $table->decimal('total_revenue', 14, 2)->default(0)->after('total_cost_price')
                    ->comment('Total revenue dari user');
            }
            
            if (!Schema::hasColumn('whatsapp_campaigns', 'total_profit')) {
                $table->decimal('total_profit', 14, 2)->default(0)->after('total_revenue')
                    ->comment('Total profit');
            }
            
            if (!Schema::hasColumn('whatsapp_campaigns', 'profit_margin')) {
                $table->decimal('profit_margin', 5, 2)->default(0)->after('total_profit')
                    ->comment('Profit margin percentage');
            }
        });

        // ==================== OWNER COST SETTINGS ====================
        // Tabel untuk menyimpan cost per message dari Gupshup
        if (!Schema::hasTable('owner_cost_settings')) {
            Schema::create('owner_cost_settings', function (Blueprint $table) {
                $table->id();
                $table->string('category', 50)->unique()->comment('marketing, utility, auth, service');
                $table->string('display_name', 100);
                $table->decimal('cost_per_message', 10, 4)->default(0)->comment('Cost modal dari Gupshup');
                $table->decimal('default_price', 10, 4)->default(0)->comment('Default harga jual ke user');
                $table->string('currency', 3)->default('IDR');
                $table->text('notes')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
            
            // Seed default cost (perkiraan Gupshup pricing)
            \Illuminate\Support\Facades\DB::table('owner_cost_settings')->insertOrIgnore([
                [
                    'category' => 'marketing',
                    'display_name' => 'Marketing / Broadcast',
                    'cost_per_message' => 85.00, // ~$0.005 USD
                    'default_price' => 150.00,
                    'currency' => 'IDR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'category' => 'utility',
                    'display_name' => 'Utility / Notification',
                    'cost_per_message' => 50.00, // ~$0.003 USD
                    'default_price' => 100.00,
                    'currency' => 'IDR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'category' => 'authentication',
                    'display_name' => 'Authentication / OTP',
                    'cost_per_message' => 60.00, // ~$0.0035 USD
                    'default_price' => 120.00,
                    'currency' => 'IDR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'category' => 'service',
                    'display_name' => 'Service / Reply',
                    'cost_per_message' => 25.00, // Free window, small cost
                    'default_price' => 50.00,
                    'currency' => 'IDR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        // ==================== PROFIT SNAPSHOTS ====================
        // Daily/monthly snapshot untuk historical data
        if (!Schema::hasTable('profit_snapshots')) {
            Schema::create('profit_snapshots', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date');
                $table->enum('period_type', ['daily', 'monthly'])->default('daily');
                $table->foreignId('klien_id')->nullable()->constrained('klien')->nullOnDelete();
                
                // Message counts
                $table->unsignedInteger('total_messages')->default(0);
                $table->unsignedInteger('sent_messages')->default(0);
                $table->unsignedInteger('delivered_messages')->default(0);
                $table->unsignedInteger('failed_messages')->default(0);
                
                // Financial
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->decimal('total_revenue', 14, 2)->default(0);
                $table->decimal('total_profit', 14, 2)->default(0);
                $table->decimal('profit_margin', 5, 2)->default(0);
                
                // Per category breakdown (JSON)
                $table->json('category_breakdown')->nullable();
                
                // Metadata
                $table->unsignedInteger('active_users')->default(0);
                $table->decimal('arpu', 10, 2)->default(0)->comment('Average Revenue Per User');
                $table->decimal('avg_cost_per_message', 10, 4)->default(0);
                $table->decimal('avg_revenue_per_message', 10, 4)->default(0);
                
                $table->timestamps();
                
                // Indexes
                $table->unique(['snapshot_date', 'period_type', 'klien_id'], 'profit_snapshot_unique');
                $table->index(['period_type', 'snapshot_date'], 'ps_period_date_idx');
                $table->index(['klien_id', 'snapshot_date'], 'ps_klien_date_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropIndex('wml_klien_date_idx');
            $table->dropIndex('wml_campaign_status_idx');
            $table->dropIndex('wml_category_date_idx');
            $table->dropColumn(['cost_price', 'revenue', 'profit', 'pricing_category']);
        });
        
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropColumn(['total_cost_price', 'total_revenue', 'total_profit', 'profit_margin']);
        });
        
        Schema::dropIfExists('profit_snapshots');
        Schema::dropIfExists('owner_cost_settings');
    }
};
