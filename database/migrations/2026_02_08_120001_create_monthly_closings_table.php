<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * KONSEP MUTLAK:
     * 1. Ledger adalah sumber kebenaran saldo
     * 2. Closing bulanan bersifat read-only (snapshot)
     * 3. Tidak ada edit data historis
     */
    public function up(): void
    {
        if (!Schema::hasTable('monthly_closings')) {
            Schema::create('monthly_closings', function (Blueprint $table) {
            // Primary identifier
            $table->id();
            
            // Period identification
            $table->unsignedInteger('year')
                  ->comment('Tahun closing (YYYY)');
                  
            $table->unsignedTinyInteger('month')
                  ->comment('Bulan closing (1-12)');
                  
            $table->string('period_key', 7)
                  ->unique()
                  ->comment('Format: YYYY-MM untuk unique constraint');
                  
            $table->date('period_start')
                  ->comment('Tanggal mulai periode (1st of month)');
                  
            $table->date('period_end')
                  ->comment('Tanggal akhir periode (last day of month)');
            
            // Closing status & control
            $table->enum('status', ['in_progress', 'completed', 'failed', 'locked'])
                  ->default('in_progress')
                  ->comment('Status proses closing');
                  
            $table->timestamp('closing_started_at')
                  ->comment('Waktu closing mulai diproses');
                  
            $table->timestamp('closing_completed_at')
                  ->nullable()
                  ->comment('Waktu closing selesai dan locked');
                  
            $table->boolean('is_locked')
                  ->default(false)
                  ->comment('Apakah data sudah locked (immutable)');
                  
            $table->text('closing_notes')
                  ->nullable()
                  ->comment('Catatan manual dari admin/finance');
            
            // Ledger data aggregation (FROM LEDGER SOURCE OF TRUTH)
            $table->decimal('opening_balance', 18, 2)
                  ->default(0)
                  ->comment('Saldo awal periode dari ledger');
                  
            $table->decimal('total_topup', 18, 2)
                  ->default(0)
                  ->comment('Total credit/topup dalam periode');
                  
            $table->decimal('total_debit', 18, 2)
                  ->default(0)
                  ->comment('Total debit/usage dalam periode');
                  
            $table->decimal('total_refund', 18, 2)
                  ->default(0)
                  ->comment('Total refund dalam periode');
                  
            $table->decimal('closing_balance', 18, 2)
                  ->default(0)
                  ->comment('Saldo akhir periode dari ledger');
            
            // Validation & consistency checks
            $table->decimal('calculated_closing_balance', 18, 2)
                  ->default(0)
                  ->comment('Opening + Topup - Debit + Refund (for validation)');
                  
            $table->decimal('balance_variance', 18, 2)
                  ->default(0)
                  ->comment('Selisih closing vs calculated (should be 0)');
                  
            $table->boolean('is_balanced')
                  ->default(false)
                  ->comment('Apakah perhitungan balance sudah cocok');
            
            // Transaction counts & statistics
            $table->unsignedBigInteger('total_transactions')
                  ->default(0)
                  ->comment('Total jumlah transaksi ledger dalam periode');
                  
            $table->unsignedBigInteger('credit_transactions_count')
                  ->default(0)
                  ->comment('Jumlah transaksi credit/topup');
                  
            $table->unsignedBigInteger('debit_transactions_count')
                  ->default(0)
                  ->comment('Jumlah transaksi debit/usage');
                  
            $table->unsignedBigInteger('refund_transactions_count')
                  ->default(0)
                  ->comment('Jumlah transaksi refund');
            
            // User statistics
            $table->unsignedInteger('active_users_count')
                  ->default(0)
                  ->comment('Jumlah user yang ada transaksi dalam periode');
                  
            $table->unsignedInteger('topup_users_count')
                  ->default(0)
                  ->comment('Jumlah user yang topup dalam periode');
            
            // Financial insights
            $table->decimal('average_balance_per_user', 15, 2)
                  ->nullable()
                  ->comment('Rata-rata saldo per user di akhir periode');
                  
            $table->decimal('average_topup_per_user', 15, 2)
                  ->nullable()
                  ->comment('Rata-rata topup per user dalam periode');
                  
            $table->decimal('average_usage_per_user', 15, 2)
                  ->nullable()
                  ->comment('Rata-rata usage per user dalam periode');
            
            // Export & reporting metadata
            $table->json('export_files')
                  ->nullable()
                  ->comment('Metadata file export yang sudah dibuat');
                  
            $table->timestamp('last_exported_at')
                  ->nullable()
                  ->comment('Terakhir kali data di-export');
                  
            $table->json('export_summary')
                  ->nullable()
                  ->comment('Summary hasil export (file sizes, record counts, etc.)');
            
            // Data source tracking
            $table->timestamp('data_source_from')
                  ->comment('Timestamp data ledger paling awal yang dianalisa');
                  
            $table->timestamp('data_source_to')
                  ->comment('Timestamp data ledger paling akhir yang dianalisa');
                  
            $table->string('data_source_version', 50)
                  ->nullable()
                  ->comment('Versi sistem saat closing (untuk audit trail)');
            
            // Error handling
            $table->text('error_details')
                  ->nullable()
                  ->comment('Detail error jika closing gagal');
                  
            $table->json('validation_results')
                  ->nullable()
                  ->comment('Hasil validasi consistency checks');
                  
            $table->unsignedInteger('retry_count')
                  ->default(0)
                  ->comment('Berapa kali closing ini di-retry');
            
            // Processing metadata
            $table->unsignedInteger('processing_time_seconds')
                  ->nullable()
                  ->comment('Waktu yang dibutuhkan untuk proses closing');
                  
            $table->unsignedBigInteger('memory_usage_mb')
                  ->nullable()
                  ->comment('Peak memory usage saat proses closing');
                  
            $table->string('processed_by', 100)
                  ->comment('Job/User yang memproses closing ini');
            
            // Audit tracking
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('User yang trigger closing (null jika auto job)');
                  
            $table->timestamps();
            
            // ==================== INDEXES ====================
            
            // Primary lookups
            $table->index(['year', 'month'], 'idx_monthly_closings_period');
            $table->index(['status'], 'idx_monthly_closings_status');
            $table->index(['is_locked'], 'idx_monthly_closings_locked');
            
            // Date ranges
            $table->index(['period_start', 'period_end'], 'idx_monthly_closings_date_range');
            $table->index(['closing_completed_at'], 'idx_monthly_closings_completed');
            
            // Financial queries
            $table->index(['is_balanced'], 'idx_monthly_closings_balanced');
            $table->index(['closing_balance'], 'idx_monthly_closings_balance');
            
            // Export tracking
            $table->index(['last_exported_at'], 'idx_monthly_closings_exported');
            
            // CHECK constraints via raw SQL (Blueprint::check not available)
        });

        // Add CHECK constraints via raw SQL
        try {
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_valid_month CHECK (month >= 1 AND month <= 12)");
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_valid_year CHECK (year >= 2020 AND year <= 2100)");
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_valid_period CHECK (period_end >= period_start)");
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_positive_topup CHECK (total_topup >= 0)");
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_positive_debit CHECK (total_debit >= 0)");
            DB::statement("ALTER TABLE monthly_closings ADD CONSTRAINT chk_positive_refund CHECK (total_refund >= 0)");
        } catch (\Exception $e) {
            // Constraints may already exist or not be supported
        }
}

        // Create monthly_closing_details table untuk breakdown per user
        if (!Schema::hasTable('monthly_closing_details')) {
            Schema::create('monthly_closing_details', function (Blueprint $table) {
            $table->id();
            
            // Foreign key ke monthly_closings
            $table->foreignId('monthly_closing_id')
                  ->constrained('monthly_closings')
                  ->cascadeOnDelete()
                  ->comment('Reference ke monthly closing utama');
            
            // User identification
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete()
                  ->comment('User yang di-breakdown');
                  
            $table->string('user_name', 255)
                  ->comment('Snapshot nama user saat closing (immutable)');
                  
            $table->string('user_email', 255)
                  ->comment('Snapshot email user saat closing (immutable)');
            
            // Per-user financial data
            $table->decimal('opening_balance', 15, 2)
                  ->default(0)
                  ->comment('Saldo awal user di periode ini');
                  
            $table->decimal('total_topup', 15, 2)
                  ->default(0)
                  ->comment('Total topup user dalam periode');
                  
            $table->decimal('total_debit', 15, 2)
                  ->default(0)
                  ->comment('Total debit user dalam periode');
                  
            $table->decimal('total_refund', 15, 2)
                  ->default(0)
                  ->comment('Total refund user dalam periode');
                  
            $table->decimal('closing_balance', 15, 2)
                  ->default(0)
                  ->comment('Saldo akhir user di periode ini');
            
            // User transaction counts
            $table->unsignedInteger('credit_count')->default(0);
            $table->unsignedInteger('debit_count')->default(0);
            $table->unsignedInteger('refund_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            
            // User activity metadata
            $table->timestamp('first_transaction_at')
                  ->nullable()
                  ->comment('Transaksi pertama user dalam periode');
                  
            $table->timestamp('last_transaction_at')
                  ->nullable()
                  ->comment('Transaksi terakhir user dalam periode');
                  
            $table->unsignedInteger('active_days')
                  ->default(0)
                  ->comment('Berapa hari user ada aktivitas dalam periode');
            
            $table->timestamps();
            
            // Indexes untuk monthly_closing_details
            $table->index(['monthly_closing_id', 'user_id'], 'idx_closing_details_main');
            $table->index(['user_id', 'monthly_closing_id'], 'idx_closing_details_user');
            $table->index(['closing_balance'], 'idx_closing_details_balance');
            $table->index(['total_topup'], 'idx_closing_details_topup');
            $table->index(['total_debit'], 'idx_closing_details_debit');
            
            // Unique constraint
            $table->unique(['monthly_closing_id', 'user_id'], 'unq_closing_details_user');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_closing_details');
        Schema::dropIfExists('monthly_closings');
    }
};