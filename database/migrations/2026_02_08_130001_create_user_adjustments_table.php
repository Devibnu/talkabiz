<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL SECURITY NOTE:
     * - Adjustment harus immutable setelah tercreate
     * - Semua perubahan balance HANYA lewat ledger_entries
     * - Tidak boleh ada UPDATE/DELETE pada record setelah processed
     */
    public function up(): void
    {
        // ===================== USER ADJUSTMENTS TABLE =====================
        // Main table untuk semua user balance adjustments
        if (!Schema::hasTable('user_adjustments')) {
            Schema::create('user_adjustments', function (Blueprint $table) {
            $table->id();
            
            // ===== IDENTIFICATION =====
            $table->string('adjustment_id', 32)->unique()->index()
                  ->comment('Unique adjustment identifier (ADJ_YYYYMMDD_XXXXX)');
                  
            // ===== USER & AMOUNT INFO =====
            $table->foreignId('user_id')->constrained()
                  ->comment('Target user yang akan di-adjust balancenya');
            $table->enum('direction', ['credit', 'debit'])->index()
                  ->comment('Direction: credit = tambah balance, debit = kurangi balance');
            $table->decimal('amount', 15, 2)->index()
                  ->comment('Nominal adjustment (selalu positif, direction menentukan +/-)');
            $table->decimal('balance_before', 15, 2)->nullable()
                  ->comment('Balance user sebelum adjustment (diisi saat processing)');
            $table->decimal('balance_after', 15, 2)->nullable()
                  ->comment('Balance user setelah adjustment (diisi saat processing)');

            // ===== REASON & JUSTIFICATION =====
            $table->enum('reason_code', [
                'system_error', 'payment_error', 'refund_manual', 'bonus_campaign',
                'compensation', 'migration', 'technical_issue', 'fraud_recovery',
                'promotion_bonus', 'loyalty_reward', 'chargeback', 'dispute_resolution',
                'test_correction', 'data_correction', 'manual_override', 'other'
            ])->index()->comment('Standardized reason codes untuk adjustment');
            
            $table->text('reason_note')->comment('MANDATORY: Detailed explanation untuk adjustment');
            $table->string('attachment_path')->nullable()
                  ->comment('Path ke attachment (screenshot, email, dokumen pendukung)');
            $table->json('supporting_data')->nullable()
                  ->comment('Additional data: original transaction, reference numbers, etc');

            // ===== APPROVAL WORKFLOW ===== 
            $table->enum('status', [
                'pending_approval', 'auto_approved', 'manually_approved', 
                'rejected', 'processed', 'failed'
            ])->default('pending_approval')->index();
            
            $table->boolean('requires_approval')->default(false)->index()
                  ->comment('TRUE jika amount > threshold dan butuh manual approval');
            $table->decimal('approval_threshold', 15, 2)->nullable()
                  ->comment('Threshold yang berlaku saat adjustment ini dibuat');

            // ===== ACTOR & AUDIT INFO =====
            $table->foreignId('created_by')->constrained('users')
                  ->comment('Owner/Admin yang initiate adjustment');
            $table->foreignId('approved_by')->nullable()->constrained('users')
                  ->comment('User yang approve adjustment (jika butuh approval)');
            $table->foreignId('processed_by')->nullable()->constrained('users')
                  ->comment('User yang process adjustment ke ledger');
            
            // ===== TECHNICAL DETAILS =====
            $table->string('ip_address', 45)->nullable()
                  ->comment('IP address dari creator');
            $table->text('user_agent')->nullable()
                  ->comment('Browser/client info dari creator');
            $table->json('request_metadata')->nullable()
                  ->comment('Additional request context');

            // ===== PROCESSING INFO =====
            $table->bigInteger('ledger_entry_id')->nullable()->index()
                  ->comment('FK ke ledger_entries setelah processed');
            $table->timestamp('approved_at')->nullable()
                  ->comment('Kapan di-approve');
            $table->timestamp('processed_at')->nullable()
                  ->comment('Kapan di-process ke ledger');
            $table->timestamp('failed_at')->nullable()
                  ->comment('Kapan processing gagal');
            
            // ===== ERROR HANDLING =====
            $table->text('failure_reason')->nullable()
                  ->comment('Alasan gagal processing (jika status = failed)');
            $table->integer('retry_count')->default(0)
                  ->comment('Berapa kali sudah di-retry');
            $table->json('processing_log')->nullable()
                  ->comment('Log detail dari processing steps');

            // ===== SECURITY FLAGS =====
            $table->boolean('is_high_risk')->default(false)->index()
                  ->comment('Flag untuk adjustment yang high risk');
            $table->boolean('is_locked')->default(false)->index()
                  ->comment('Lock untuk prevent modification');
            $table->string('security_hash')->nullable()
                  ->comment('Hash untuk detect tampering');

            $table->timestamps();
            $table->softDeletes(); // Soft delete untuk audit trail

            // ===== INDEXES =====
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'requires_approval']);
            $table->index(['reason_code', 'created_at']);
            $table->index(['amount', 'direction']);
            $table->index(['created_by', 'created_at']);
        });
}

        // ===================== ADJUSTMENT APPROVALS TABLE =====================
        // Table untuk log approval workflow
        if (!Schema::hasTable('adjustment_approvals')) {
            Schema::create('adjustment_approvals', function (Blueprint $table) {
            $table->id();
            
            // ===== RELATION =====
            $table->foreignId('adjustment_id')->constrained('user_adjustments')->cascadeOnDelete();
            
            // ===== APPROVAL INFO =====
            $table->enum('action', ['approve', 'reject', 'request_more_info'])
                  ->comment('Action yang diambil approver');
            $table->foreignId('approver_id')->constrained('users')
                  ->comment('User yang melakukan action');
            $table->text('approval_note')->nullable()
                  ->comment('Note dari approver');
            
            // ===== CONTEXT =====
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('approval_metadata')->nullable()
                  ->comment('Context saat approval (browser info, etc)');

            $table->timestamps();

            // ===== INDEXES =====
            $table->index(['adjustment_id', 'created_at']);
            $table->index(['approver_id', 'action']);
        });
}

        // ===================== ADJUSTMENT CATEGORIES TABLE =====================
        // Table untuk kategorisasi adjustment untuk reporting
        if (!Schema::hasTable('adjustment_categories')) {
            Schema::create('adjustment_categories', function (Blueprint $table) {
            $table->id();
            
            $table->string('code', 50)->unique()
                  ->comment('Category code (e.g., SYSTEM_ERROR, MANUAL_REFUND)');
            $table->string('name')
                  ->comment('Human readable category name');
            $table->text('description')->nullable()
                  ->comment('Detailed description tentang kategori');
            $table->decimal('auto_approval_limit', 15, 2)->default(0)
                  ->comment('Max amount untuk auto approval untuk kategori ini');
            $table->boolean('is_active')->default(true)
                  ->comment('Apakah kategori masih aktif');
            $table->boolean('requires_documentation')->default(false)
                  ->comment('Apakah wajib upload dokumen pendukung');
            
            $table->timestamps();
            
            $table->index(['code', 'is_active']);
        });
}

        // Populate default categories
        DB::table('adjustment_categories')->insertOrIgnore([
            [
                'code' => 'SYSTEM_ERROR',
                'name' => 'System Error Correction',
                'description' => 'Koreksi karena error system atau bug',
                'auto_approval_limit' => 100000.00,
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'PAYMENT_ERROR',
                'name' => 'Payment Processing Error',
                'description' => 'Koreksi karena error payment gateway',
                'auto_approval_limit' => 500000.00,
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'MANUAL_REFUND',
                'name' => 'Manual Refund',
                'description' => 'Refund manual atas permintaan customer',
                'auto_approval_limit' => 50000.00,
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'BONUS_CAMPAIGN',
                'name' => 'Promotional Bonus',
                'description' => 'Bonus dari campaign atau promosi',
                'auto_approval_limit' => 25000.00,
                'requires_documentation' => false,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'COMPENSATION',
                'name' => 'Service Compensation',
                'description' => 'Kompensasi atas service downtime atau issue',
                'auto_approval_limit' => 200000.00,
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'DATA_MIGRATION',
                'name' => 'Data Migration',
                'description' => 'Adjustment untuk migrasi data dari system lama',
                'auto_approval_limit' => 0, // Always require approval
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'FRAUD_RECOVERY',
                'name' => 'Fraud Recovery',
                'description' => 'Recovery balance dari fraud atau abuse',
                'auto_approval_limit' => 0, // Always require approval
                'requires_documentation' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'code' => 'TEST_CORRECTION',
                'name' => 'Test Environment Correction',
                'description' => 'Koreksi dari test environment yang masuk production',
                'auto_approval_limit' => 10000.00,
                'requires_documentation' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjustment_approvals');
        Schema::dropIfExists('adjustment_categories');
        Schema::dropIfExists('user_adjustments');
    }
};