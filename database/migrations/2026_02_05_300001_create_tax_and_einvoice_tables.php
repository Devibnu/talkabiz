<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax & E-Invoice Tables Migration
 * 
 * Menambahkan layer pajak untuk compliance:
 * 1. client_tax_profiles - Data NPWP, status PKP per client
 * 2. Extend invoices dengan detail pajak
 * 3. e_invoices - Struktur untuk e-Faktur (future)
 * 
 * ATURAN BISNIS:
 * ==============
 * 1. PPN 11% dihitung saat invoice dibuat
 * 2. Invoice SSOT keuangan - tidak diubah setelah paid
 * 3. PKP = Pengusaha Kena Pajak (wajib faktur)
 * 4. Non-PKP = tidak wajib faktur pajak
 * 
 * TAX CALCULATION:
 * ================
 * - Subtotal = harga sebelum pajak
 * - Tax = Subtotal * tax_rate (11%)
 * - Total = Subtotal + Tax - Discount
 * 
 * @author Senior Tax Compliance Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== CLIENT TAX PROFILES ====================
        if (!Schema::hasTable('client_tax_profiles')) {
            Schema::create('client_tax_profiles', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('klien_id')
                  ->unique()
                  ->constrained('klien')
                  ->onDelete('cascade');
            
            // ==================== ENTITY INFO ====================
            $table->string('entity_name', 255)->nullable()
                  ->comment('Nama entitas sesuai NPWP');
            $table->string('entity_address', 500)->nullable()
                  ->comment('Alamat sesuai NPWP');
            
            // ==================== NPWP INFO ====================
            $table->string('npwp', 20)->nullable()
                  ->comment('NPWP 15 digit (format: XX.XXX.XXX.X-XXX.XXX)');
            $table->string('npwp_name', 255)->nullable()
                  ->comment('Nama di NPWP');
            $table->string('npwp_address', 500)->nullable()
                  ->comment('Alamat di NPWP');
            $table->date('npwp_registered_at')->nullable();
            
            // ==================== PKP STATUS ====================
            $table->boolean('is_pkp')->default(false)
                  ->comment('Pengusaha Kena Pajak - wajib faktur');
            $table->string('pkp_number', 50)->nullable()
                  ->comment('Nomor Pengukuhan PKP');
            $table->date('pkp_registered_at')->nullable();
            $table->date('pkp_expired_at')->nullable();
            
            // ==================== TAX SETTINGS ====================
            $table->boolean('tax_exempt')->default(false)
                  ->comment('Bebas PPN (e.g., edukasi, kesehatan)');
            $table->string('tax_exempt_reason', 255)->nullable();
            $table->decimal('custom_tax_rate', 5, 2)->nullable()
                  ->comment('Custom rate jika ada (null = pakai default)');
            
            // ==================== CONTACT INFO ====================
            $table->string('tax_contact_name', 100)->nullable();
            $table->string('tax_contact_email', 100)->nullable();
            $table->string('tax_contact_phone', 20)->nullable();
            
            // ==================== DOCUMENTS ====================
            $table->string('npwp_document_path', 255)->nullable()
                  ->comment('Scan NPWP');
            $table->string('pkp_document_path', 255)->nullable()
                  ->comment('Scan SKP PKP');
            
            // ==================== AUDIT ====================
            $table->foreignId('verified_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_status', 30)->default('pending')
                  ->comment('pending, verified, rejected');
            $table->text('verification_notes')->nullable();
            
            $table->timestamps();
            
            $table->index('npwp');
            $table->index('is_pkp');
            $table->index('verification_status');
        });
}

        // ==================== EXTEND INVOICES TABLE ====================
        Schema::table('invoices', function (Blueprint $table) {
            // Tax details
            $table->decimal('tax_rate', 5, 2)->default(11.00)->after('tax')
                  ->comment('PPN rate saat invoice dibuat');
            $table->string('tax_type', 30)->default('ppn')->after('tax_rate')
                  ->comment('ppn, ppn_bm, pph, exempt');
            $table->string('tax_calculation', 30)->default('exclusive')->after('tax_type')
                  ->comment('exclusive (tambah), inclusive (sudah termasuk)');
            
            // Buyer tax info (snapshot at invoice time)
            if (!Schema::hasColumn('invoices', 'buyer_npwp')) {
                $table->string('buyer_npwp', 20)->nullable()->after('tax_calculation');
            }
            if (!Schema::hasColumn('invoices', 'buyer_npwp_name')) {
                $table->string('buyer_npwp_name', 255)->nullable()->after('buyer_npwp');
            }
            if (!Schema::hasColumn('invoices', 'buyer_npwp_address')) {
                $table->string('buyer_npwp_address', 500)->nullable()->after('buyer_npwp_name');
            }
            if (!Schema::hasColumn('invoices', 'buyer_is_pkp')) {
                $table->boolean('buyer_is_pkp')->default(false)->after('buyer_npwp_address');
            }
            
            // Seller tax info
            if (!Schema::hasColumn('invoices', 'seller_npwp')) {
                $table->string('seller_npwp', 20)->nullable()->after('buyer_is_pkp');
            }
            if (!Schema::hasColumn('invoices', 'seller_npwp_name')) {
                $table->string('seller_npwp_name', 255)->nullable()->after('seller_npwp');
            }
            if (!Schema::hasColumn('invoices', 'seller_npwp_address')) {
                $table->string('seller_npwp_address', 500)->nullable()->after('seller_npwp_name');
            }
            
            // E-Faktur reference
            $table->string('efaktur_number', 50)->nullable()->after('seller_npwp_address')
                  ->comment('Nomor e-Faktur jika sudah di-generate');
            if (!Schema::hasColumn('invoices', 'efaktur_generated_at')) {
                $table->timestamp('efaktur_generated_at')->nullable()->after('efaktur_number');
            }
            
            // Tax flags
            $table->boolean('is_tax_invoice')->default(false)->after('efaktur_generated_at')
                  ->comment('True = formal tax invoice (faktur pajak)');
            $table->boolean('tax_locked')->default(false)->after('is_tax_invoice')
                  ->comment('True = tax info tidak boleh diubah');
            
            $table->index('efaktur_number');
            $table->index('is_tax_invoice');
        });

        // ==================== E-INVOICES TABLE (e-Faktur) ====================
        if (!Schema::hasTable('e_invoices')) {
            Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('invoice_id')
                  ->constrained('invoices')
                  ->onDelete('cascade');
            
            // ==================== E-FAKTUR IDENTITY ====================
            $table->string('efaktur_number', 30)->unique()
                  ->comment('Format: 010.XXX-XX.XXXXXXXX');
            $table->string('transaction_code', 2)->default('01')
                  ->comment('01=kepada pihak lain, 02=kepada pemungut, dll');
            $table->string('status_code', 2)->default('1')
                  ->comment('1=normal, 2=pengganti, 3=pembatalan');
            
            // ==================== SELLER INFO ====================
            $table->string('seller_npwp', 20);
            $table->string('seller_name', 255);
            $table->string('seller_address', 500);
            
            // ==================== BUYER INFO ====================
            $table->string('buyer_npwp', 20);
            $table->string('buyer_name', 255);
            $table->string('buyer_address', 500);
            $table->boolean('buyer_is_pkp')->default(false);
            
            // ==================== TAX DETAILS ====================
            $table->decimal('dpp', 18, 2)->default(0)
                  ->comment('Dasar Pengenaan Pajak (subtotal)');
            $table->decimal('ppn', 18, 2)->default(0)
                  ->comment('PPN amount');
            $table->decimal('ppn_bm', 18, 2)->default(0)
                  ->comment('PPnBM jika ada');
            $table->decimal('total', 18, 2)->default(0);
            
            // ==================== LINE ITEMS ====================
            $table->json('line_items')->nullable()
                  ->comment('Detail barang/jasa sesuai format DJP');
            
            // ==================== DATES ====================
            $table->date('faktur_date')
                  ->comment('Tanggal faktur pajak');
            $table->date('approval_date')->nullable()
                  ->comment('Tanggal approval DJP');
            
            // ==================== STATUS ====================
            $table->enum('status', [
                'draft',        // Belum diupload
                'uploaded',     // Sudah diupload ke DJP
                'approved',     // Disetujui DJP
                'rejected',     // Ditolak DJP
                'cancelled',    // Dibatalkan
                'replaced',     // Diganti faktur lain
            ])->default('draft');
            
            $table->string('djp_response_code', 10)->nullable();
            $table->text('djp_response_message')->nullable();
            
            // ==================== DOCUMENTS ====================
            $table->string('pdf_path', 255)->nullable()
                  ->comment('Path ke PDF faktur');
            $table->string('xml_path', 255)->nullable()
                  ->comment('Path ke XML untuk upload DJP');
            $table->string('qr_code', 500)->nullable()
                  ->comment('QR code untuk validasi');
            
            // ==================== REFERENCE ====================
            $table->string('reference_efaktur', 30)->nullable()
                  ->comment('Nomor faktur yang diganti jika status=replaced');
            $table->text('notes')->nullable();
            
            // ==================== AUDIT ====================
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['seller_npwp', 'faktur_date']);
            $table->index(['buyer_npwp', 'faktur_date']);
            $table->index('status');
        });
}

        // ==================== TAX SETTINGS TABLE ====================
        if (!Schema::hasTable('tax_settings')) {
            Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();
            
            // Seller info (company)
            $table->string('company_name', 255);
            $table->string('company_npwp', 20);
            $table->string('company_pkp_number', 50)->nullable();
            $table->string('company_address', 500);
            
            // Default rates
            $table->decimal('default_ppn_rate', 5, 2)->default(11.00);
            $table->boolean('auto_apply_tax')->default(true)
                  ->comment('Otomatis hitung pajak di invoice');
            
            // E-Faktur settings
            $table->boolean('efaktur_enabled')->default(false);
            $table->string('efaktur_api_url', 255)->nullable();
            $table->text('efaktur_api_key')->nullable();
            
            // Numbering
            $table->string('efaktur_prefix', 20)->nullable()
                  ->comment('Prefix nomor faktur');
            $table->unsignedInteger('efaktur_last_number')->default(0);
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
        Schema::dropIfExists('e_invoices');
        
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'tax_rate',
                'tax_type',
                'tax_calculation',
                'buyer_npwp',
                'buyer_npwp_name',
                'buyer_npwp_address',
                'buyer_is_pkp',
                'seller_npwp',
                'seller_npwp_name',
                'seller_npwp_address',
                'efaktur_number',
                'efaktur_generated_at',
                'is_tax_invoice',
                'tax_locked',
            ]);
        });
        
        Schema::dropIfExists('client_tax_profiles');
    }
};
