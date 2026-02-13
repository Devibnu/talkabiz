<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menambahkan field tax yang sesuai requirement:
     * - subtotal, tax_rate, tax_amount, total_amount, tax_snapshot
     * - Immutable enforcement setelah status PAID
     * - PDF generation fields
     */
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            // ==================== TAX CALCULATION FIELDS ====================
            
            if (!Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 15, 2)->nullable()->after('amount')
                      ->comment('Amount before tax (immutable after paid)');
            }
            
            if (!Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal')
                      ->comment('Calculated tax amount (immutable after paid)');
            }
            
            if (!Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 15, 2)->default(0)->after('tax_amount')
                      ->comment('Discount applied (immutable after paid)');
            }
            
            if (!Schema::hasColumn('invoices', 'total_calculated')) {
                $table->decimal('total_calculated', 15, 2)->nullable()->after('discount_amount')
                      ->comment('Calculated total: subtotal + tax_amount - discount_amount + admin_fee (immutable after paid)');
            }
            
            // ==================== TAX SNAPSHOT ====================
            if (!Schema::hasColumn('invoices', 'tax_snapshot')) {
                $table->json('tax_snapshot')->nullable()->after('total_calculated')
                      ->comment('Complete tax calculation snapshot (immutable after paid)');
            }
            
            // ==================== PDF & DOCUMENT FIELDS ====================
            if (!Schema::hasColumn('invoices', 'pdf_path')) {
                $table->string('pdf_path', 500)->nullable()->after('tax_snapshot')
                      ->comment('Path to generated PDF invoice');
            }
            
            if (!Schema::hasColumn('invoices', 'pdf_generated_at')) {
                $table->timestamp('pdf_generated_at')->nullable()->after('pdf_path')
                      ->comment('When PDF was generated');
            }
            
            if (!Schema::hasColumn('invoices', 'pdf_hash')) {
                $table->string('pdf_hash', 64)->nullable()->after('pdf_generated_at')
                      ->comment('SHA-256 hash for PDF integrity verification');
            }
            
            // ==================== IMMUTABILITY ENFORCEMENT ====================
            if (!Schema::hasColumn('invoices', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('pdf_hash')
                      ->comment('True = invoice locked (immutable), automatically set when status = paid');
            }
            
            if (!Schema::hasColumn('invoices', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('is_locked')
                      ->comment('When invoice was locked');
            }
            
            if (!Schema::hasColumn('invoices', 'locked_by')) {
                $table->unsignedBigInteger('locked_by')->nullable()->after('locked_at')
                      ->comment('User ID who locked the invoice');
            }
            
            // ==================== COMPANY PROFILE REFERENCE ====================
            if (!Schema::hasColumn('invoices', 'company_profile_id')) {
                $table->unsignedBigInteger('company_profile_id')->nullable()->after('locked_by')
                      ->comment('Reference to company profile used for this invoice');
            }
            
            // ==================== INVOICE NUMBERING ====================
            if (!Schema::hasColumn('invoices', 'formatted_invoice_number')) {
                $table->string('formatted_invoice_number', 100)->nullable()->after('company_profile_id')
                      ->comment('Formatted invoice number for display (e.g., INV/2026/02/00123)');
            }
            
            // ==================== TAX COMPLIANCE FLAGS ====================
            if (!Schema::hasColumn('invoices', 'requires_tax_invoice')) {
                $table->boolean('requires_tax_invoice')->default(false)->after('formatted_invoice_number')
                      ->comment('True if this invoice requires formal tax invoice (faktur pajak)');
            }
            
            if (!Schema::hasColumn('invoices', 'tax_status')) {
                $table->enum('tax_status', ['not_applicable', 'calculated', 'invoiced', 'reported'])->default('not_applicable')->after('requires_tax_invoice')
                      ->comment('Tax processing status');
            }
        });

        // ==================== INDEXES (safe) ====================
        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index(['is_locked', 'status']);
                $table->index(['tax_status', 'created_at']);
                $table->index(['company_profile_id', 'status']);
                $table->index('formatted_invoice_number');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }

        // ==================== FOREIGN KEYS (safe) ====================
        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('locked_by')->references('id')->on('users')->onDelete('set null');
            });
        } catch (\Exception $e) { /* FK already exists */ }

        try {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreign('company_profile_id')->references('id')->on('company_profiles')->onDelete('set null');
            });
        } catch (\Exception $e) { /* FK already exists */ }

        // ==================== INVOICE ITEMS TABLE (untuk detailed invoices) ====================
        if (!Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            
            // Item details
            $table->string('item_code', 50)->nullable();
            $table->string('item_name');
            $table->text('item_description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('pcs');
            
            // Pricing
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2); // quantity * unit_price
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2); // subtotal - discount + tax
            
            // Tax details per item
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->string('tax_type', 30)->nullable();
            $table->boolean('is_tax_inclusive')->default(false);
            
            // Metadata
            $table->json('item_metadata')->nullable();
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index(['invoice_id', 'sort_order']);
        });
}

        // ==================== INVOICE AUDIT LOG ====================
        if (!Schema::hasTable('invoice_audit_logs')) {
            Schema::create('invoice_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            
            // Audit details
            $table->string('event_type', 50); // created, updated, paid, locked, pdf_generated
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            
            // Actor information
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // Validation
            $table->boolean('is_valid_change')->default(true);
            $table->text('validation_notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['invoice_id', 'event_type', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_audit_logs');
        Schema::dropIfExists('invoice_items');
        
        Schema::table('invoices', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['locked_by']);
            $table->dropForeign(['company_profile_id']);
            
            // Drop columns
            $table->dropColumn([
                'subtotal',
                'tax_amount', 
                'discount_amount',
                'total_calculated',
                'tax_snapshot',
                'pdf_path',
                'pdf_generated_at',
                'pdf_hash',
                'is_locked',
                'locked_at',
                'locked_by',
                'company_profile_id',
                'formatted_invoice_number',
                'requires_tax_invoice',
                'tax_status'
            ]);
        });
    }
};