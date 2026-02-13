<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting Tables Migration
 * 
 * Tables untuk agregasi KPI dan reporting:
 * 1. kpi_snapshots_monthly - Snapshot KPI bulanan (MRR, ARPU, Churn, dll)
 * 2. kpi_snapshots_daily - Snapshot KPI harian (untuk trend)
 * 3. client_reports_monthly - Report per client per bulan
 * 
 * ATURAN:
 * =======
 * 1. Data read-only setelah di-generate
 * 2. Cache heavy untuk performance
 * 3. Agregasi via scheduled commands
 * 4. Tidak mengubah transaction logic
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== KPI SNAPSHOTS MONTHLY ====================
        if (!Schema::hasTable('kpi_snapshots_monthly')) {
            Schema::create('kpi_snapshots_monthly', function (Blueprint $table) {
            $table->id();
            
            // Period
            $table->string('period', 7)->unique()->comment('YYYY-MM format');
            $table->date('period_start');
            $table->date('period_end');
            
            // ==================== REVENUE METRICS ====================
            $table->decimal('mrr', 18, 2)->default(0)->comment('Monthly Recurring Revenue');
            $table->decimal('arr', 18, 2)->default(0)->comment('Annual Recurring Revenue = MRR * 12');
            $table->decimal('total_revenue', 18, 2)->default(0)->comment('Total invoice paid');
            $table->decimal('subscription_revenue', 18, 2)->default(0)->comment('Revenue dari subscription');
            $table->decimal('topup_revenue', 18, 2)->default(0)->comment('Revenue dari top-up');
            $table->decimal('addon_revenue', 18, 2)->default(0)->comment('Revenue dari add-on');
            
            // ==================== COST METRICS ====================
            $table->decimal('total_meta_cost', 18, 2)->default(0)->comment('Total biaya Meta');
            $table->decimal('gross_margin', 18, 2)->default(0)->comment('Revenue - Cost');
            $table->decimal('gross_margin_percent', 6, 2)->default(0)->comment('(Margin / Revenue) * 100');
            
            // ==================== CLIENT METRICS ====================
            $table->unsignedInteger('total_clients')->default(0)->comment('Total klien terdaftar');
            $table->unsignedInteger('active_clients')->default(0)->comment('Klien dengan subscription active');
            $table->unsignedInteger('new_clients')->default(0)->comment('Klien baru bulan ini');
            $table->unsignedInteger('churned_clients')->default(0)->comment('Klien churn bulan ini');
            $table->decimal('churn_rate', 6, 2)->default(0)->comment('Churn % = churned / (active + churned)');
            $table->decimal('retention_rate', 6, 2)->default(0)->comment('100 - churn_rate');
            
            // ==================== ARPU METRICS ====================
            $table->decimal('arpu', 14, 2)->default(0)->comment('Average Revenue Per User');
            $table->decimal('arppu', 14, 2)->default(0)->comment('Average Revenue Per Paying User');
            
            // ==================== USAGE METRICS ====================
            $table->unsignedBigInteger('total_messages_sent')->default(0);
            $table->unsignedBigInteger('total_messages_delivered')->default(0);
            $table->unsignedBigInteger('total_messages_read')->default(0);
            $table->unsignedBigInteger('total_messages_failed')->default(0);
            $table->decimal('delivery_rate', 6, 2)->default(0)->comment('Delivered / Sent * 100');
            $table->decimal('read_rate', 6, 2)->default(0)->comment('Read / Delivered * 100');
            
            // ==================== CATEGORY BREAKDOWN ====================
            $table->json('revenue_by_plan')->nullable()->comment('Revenue per plan');
            $table->json('clients_by_plan')->nullable()->comment('Klien per plan');
            $table->json('usage_by_category')->nullable()->comment('Messages per category');
            $table->json('cost_by_category')->nullable()->comment('Cost per category');
            
            // ==================== RISK INDICATORS ====================
            $table->unsignedInteger('clients_near_limit')->default(0)->comment('Klien >=80% limit');
            $table->unsignedInteger('clients_negative_margin')->default(0)->comment('Klien margin negatif');
            $table->unsignedInteger('clients_blocked')->default(0)->comment('Klien diblock');
            $table->unsignedInteger('invoices_overdue')->default(0)->comment('Invoice overdue');
            
            // ==================== METADATA ====================
            $table->timestamp('calculated_at')->nullable();
            $table->unsignedInteger('calculation_duration_ms')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index('period_start');
        });
}
        
        // ==================== KPI SNAPSHOTS DAILY ====================
        if (!Schema::hasTable('kpi_snapshots_daily')) {
            Schema::create('kpi_snapshots_daily', function (Blueprint $table) {
            $table->id();
            
            // Period
            $table->date('snapshot_date')->unique();
            
            // Revenue (daily)
            $table->decimal('revenue', 18, 2)->default(0);
            $table->decimal('meta_cost', 18, 2)->default(0);
            $table->decimal('gross_margin', 18, 2)->default(0);
            
            // Clients
            $table->unsignedInteger('active_clients')->default(0);
            $table->unsignedInteger('new_signups')->default(0);
            $table->unsignedInteger('churned')->default(0);
            
            // Usage
            $table->unsignedBigInteger('messages_sent')->default(0);
            $table->unsignedBigInteger('messages_delivered')->default(0);
            $table->unsignedBigInteger('messages_failed')->default(0);
            
            // Invoices
            $table->unsignedInteger('invoices_created')->default(0);
            $table->unsignedInteger('invoices_paid')->default(0);
            $table->decimal('invoices_amount_paid', 18, 2)->default(0);
            
            // Running totals (for quick access)
            $table->decimal('mtd_revenue', 18, 2)->default(0)->comment('Month-to-date revenue');
            $table->decimal('mtd_cost', 18, 2)->default(0)->comment('Month-to-date cost');
            
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            
            $table->index('snapshot_date');
        });
}
        
        // ==================== CLIENT REPORTS MONTHLY ====================
        if (!Schema::hasTable('client_reports_monthly')) {
            Schema::create('client_reports_monthly', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            $table->string('period', 7)->comment('YYYY-MM format');
            
            // Subscription
            $table->string('plan_name', 100)->nullable();
            $table->string('subscription_status', 30)->nullable();
            $table->decimal('subscription_price', 14, 2)->default(0);
            
            // Usage
            $table->unsignedBigInteger('messages_sent')->default(0);
            $table->unsignedBigInteger('messages_delivered')->default(0);
            $table->unsignedBigInteger('messages_read')->default(0);
            $table->unsignedBigInteger('messages_failed')->default(0);
            
            // Limits
            $table->unsignedBigInteger('message_limit')->nullable()->comment('Limit dari plan');
            $table->decimal('usage_percent', 6, 2)->default(0)->comment('Usage / Limit * 100');
            
            // Costs
            $table->decimal('total_meta_cost', 14, 2)->default(0);
            $table->decimal('total_billed', 14, 2)->default(0);
            $table->decimal('margin', 14, 2)->default(0);
            $table->decimal('margin_percent', 6, 2)->default(0);
            
            // Invoices
            $table->unsignedInteger('invoices_count')->default(0);
            $table->decimal('invoices_total', 14, 2)->default(0);
            $table->decimal('invoices_paid', 14, 2)->default(0);
            $table->decimal('invoices_outstanding', 14, 2)->default(0);
            
            // Category breakdown
            $table->json('usage_by_category')->nullable();
            $table->json('cost_by_category')->nullable();
            
            // Status flags
            $table->boolean('is_near_limit')->default(false);
            $table->boolean('is_over_limit')->default(false);
            $table->boolean('has_negative_margin')->default(false);
            $table->boolean('has_overdue_invoice')->default(false);
            
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            
            $table->unique(['klien_id', 'period']);
            $table->index(['period']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports_monthly');
        Schema::dropIfExists('kpi_snapshots_daily');
        Schema::dropIfExists('kpi_snapshots_monthly');
    }
};
