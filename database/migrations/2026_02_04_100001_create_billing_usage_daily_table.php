<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Usage Daily Table
 * 
 * Agregasi biaya harian untuk billing & invoice.
 * 
 * ATURAN BISNIS:
 * ==============
 * 1. Hitung biaya saat delivery sukses (fallback: sent)
 * 2. Jangan hitung inbound messages
 * 3. Pisahkan usage (limit) dan billing (cost)
 * 4. Agregasi per klien per hari per kategori
 * 
 * TUJUAN:
 * =======
 * 1. Invoice bulanan
 * 2. Dashboard owner & client
 * 3. Cost monitoring & alerts
 * 4. Audit trail billing
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('billing_usage_daily')) {
            Schema::create('billing_usage_daily', function (Blueprint $table) {
            $table->id();
            
            // ==================== IDENTIFIERS ====================
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            $table->date('usage_date')->comment('Tanggal pemakaian');
            $table->string('message_category', 50)->default('marketing')
                  ->comment('marketing, utility, authentication, service');
            
            // ==================== USAGE COUNTS (untuk limit) ====================
            $table->unsignedInteger('messages_sent')->default(0)
                  ->comment('Jumlah pesan sukses sent');
            $table->unsignedInteger('messages_delivered')->default(0)
                  ->comment('Jumlah pesan sukses delivered');
            $table->unsignedInteger('messages_read')->default(0)
                  ->comment('Jumlah pesan dibaca');
            $table->unsignedInteger('messages_failed')->default(0)
                  ->comment('Jumlah pesan gagal (tidak dihitung billing)');
            
            // ==================== BILLING (cost tracking) ====================
            $table->decimal('meta_cost_per_message', 10, 2)->default(0)
                  ->comment('Biaya Meta per pesan (IDR) saat itu');
            $table->decimal('total_meta_cost', 14, 2)->default(0)
                  ->comment('Total biaya Meta = cost_per_message * billable_count');
            $table->decimal('sell_price_per_message', 10, 2)->default(0)
                  ->comment('Harga jual per pesan (IDR)');
            $table->decimal('total_revenue', 14, 2)->default(0)
                  ->comment('Total revenue = sell_price * billable_count');
            $table->decimal('total_profit', 14, 2)->default(0)
                  ->comment('Total profit = revenue - meta_cost');
            $table->decimal('margin_percentage', 6, 2)->default(0)
                  ->comment('Margin % = (profit / revenue) * 100');
            
            // ==================== BILLABLE COUNT ====================
            $table->unsignedInteger('billable_count')->default(0)
                  ->comment('Jumlah pesan yang di-bill (delivered, fallback sent)');
            $table->string('billing_trigger', 20)->default('delivered')
                  ->comment('delivered atau sent (fallback jika delivery callback tidak datang)');
            
            // ==================== STATUS ====================
            $table->boolean('is_invoiced')->default(false)
                  ->comment('Sudah masuk invoice?');
            $table->unsignedBigInteger('invoice_id')->nullable()
                  ->comment('FK ke invoices table jika ada');
            $table->timestamp('invoiced_at')->nullable();
            
            // ==================== AUDIT ====================
            $table->timestamp('last_aggregated_at')->nullable()
                  ->comment('Terakhir di-aggregate');
            $table->unsignedInteger('aggregation_count')->default(0)
                  ->comment('Berapa kali di-aggregate (untuk debugging)');
            
            $table->timestamps();
            
            // ==================== INDEXES ====================
            // Unique per klien, tanggal, kategori
            $table->unique(['klien_id', 'usage_date', 'message_category'], 'billing_usage_unique');
            
            // Query indexes
            $table->index(['klien_id', 'usage_date']);
            $table->index(['usage_date']);
            $table->index(['is_invoiced', 'usage_date']);
        });
}
        
        // ==================== CLIENT COST LIMITS TABLE ====================
        if (!Schema::hasTable('client_cost_limits')) {
            Schema::create('client_cost_limits', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            
            // Daily/Monthly cost limits (IDR)
            $table->decimal('daily_cost_limit', 14, 2)->nullable()
                  ->comment('Max biaya per hari (null = unlimited)');
            $table->decimal('monthly_cost_limit', 14, 2)->nullable()
                  ->comment('Max biaya per bulan (null = unlimited)');
            
            // Current usage
            $table->decimal('current_daily_cost', 14, 2)->default(0)
                  ->comment('Biaya hari ini');
            $table->decimal('current_monthly_cost', 14, 2)->default(0)
                  ->comment('Biaya bulan ini');
            $table->date('current_date')->nullable()
                  ->comment('Tanggal terakhir update daily');
            $table->string('current_month', 7)->nullable()
                  ->comment('Bulan terakhir update monthly (YYYY-MM)');
            
            // Alert thresholds
            $table->unsignedTinyInteger('alert_threshold_percent')->default(80)
                  ->comment('Alert jika usage >= X%');
            $table->boolean('alert_sent_daily')->default(false);
            $table->boolean('alert_sent_monthly')->default(false);
            
            // Actions on limit
            $table->enum('action_on_limit', ['block', 'warn', 'notify'])->default('warn')
                  ->comment('Apa yang dilakukan saat limit tercapai');
            
            // Status
            $table->boolean('is_blocked')->default(false)
                  ->comment('True jika diblock karena limit');
            $table->timestamp('blocked_at')->nullable();
            $table->string('block_reason', 100)->nullable();
            
            $table->timestamps();
            
            $table->unique('klien_id');
        });
}
        
        // ==================== BILLING EVENTS TABLE (Append-Only) ====================
        if (!Schema::hasTable('billing_events')) {
            Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            
            // Reference
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            $table->unsignedBigInteger('message_log_id')->nullable()->index();
            $table->unsignedBigInteger('message_event_id')->nullable()->index()
                  ->comment('FK ke message_events');
            
            // Message info
            $table->string('provider_message_id', 100)->index();
            $table->string('message_category', 50)->default('marketing');
            
            // Event that triggered billing
            $table->string('trigger_event', 20)
                  ->comment('sent, delivered - event yang trigger billing');
            $table->timestamp('event_timestamp')
                  ->comment('Timestamp event dari provider');
            
            // Costs (at time of billing)
            $table->decimal('meta_cost', 10, 2)->default(0)
                  ->comment('Biaya Meta saat itu');
            $table->decimal('sell_price', 10, 2)->default(0)
                  ->comment('Harga jual saat itu');
            $table->decimal('profit', 10, 2)->default(0)
                  ->comment('sell_price - meta_cost');
            
            // Deduplication
            $table->boolean('is_duplicate')->default(false)
                  ->comment('True jika sudah pernah di-bill');
            
            // Direction (untuk filter inbound)
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound');
            
            $table->timestamps();
            
            // Prevent double billing
            $table->unique(['provider_message_id', 'trigger_event'], 'billing_event_unique');
            
            // Query indexes
            $table->index(['klien_id', 'created_at']);
            $table->index(['message_category', 'created_at']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('client_cost_limits');
        Schema::dropIfExists('billing_usage_daily');
    }
};
