<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for Refund & Dispute Flow
 * 
 * DESIGN PRINCIPLES:
 * - Invoice tetap SSOT keuangan
 * - Semua refund/dispute melalui approval Owner
 * - Full audit trail
 * - Credit balance sebagai opsi utama
 * - Data integrity & compliance
 * 
 * FLOW:
 * Client submit → Pending Review → Owner Approve/Reject → Process → Complete/Rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Refund Requests
         * 
         * Request refund dari klien atas invoice yang sudah dibayar.
         * Harus diapprove oleh Owner sebelum diproses.
         */
        if (!Schema::hasTable('refund_requests')) {
            Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 32)->unique();
            
            // Relations
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('klien_id')->constrained('klien');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions');
            
            // Request details
            $table->enum('reason', [
                'service_not_working',      // Layanan tidak berfungsi
                'duplicate_payment',        // Pembayaran ganda
                'wrong_amount',             // Jumlah salah
                'service_not_used',         // Layanan tidak digunakan
                'downgrade_difference',     // Selisih downgrade
                'cancellation',             // Pembatalan
                'other',                    // Lainnya
            ]);
            $table->text('description')->nullable();            // Penjelasan dari klien
            $table->text('evidence')->nullable();               // Bukti pendukung (JSON array of URLs)
            
            // Amount
            $table->bigInteger('requested_amount');             // Jumlah yang diminta
            $table->bigInteger('approved_amount')->nullable();  // Jumlah yang disetujui
            $table->string('currency', 3)->default('IDR');
            
            // Refund method
            $table->enum('refund_method', [
                'credit_balance',   // Ke saldo klien (default, recommended)
                'bank_transfer',    // Transfer bank
                'original_method',  // Ke metode pembayaran asli
            ])->default('credit_balance');
            
            // Bank details (for bank_transfer)
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            
            // Status flow
            $table->enum('status', [
                'pending',          // Menunggu review owner
                'under_review',     // Sedang direview
                'approved',         // Disetujui, belum diproses
                'processing',       // Sedang diproses
                'completed',        // Refund selesai
                'rejected',         // Ditolak
                'cancelled',        // Dibatalkan oleh klien
            ])->default('pending');
            
            // Owner review
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();           // Catatan dari Owner
            $table->text('rejection_reason')->nullable();       // Alasan penolakan
            
            // Processing
            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->timestamp('processed_at')->nullable();
            $table->string('transaction_reference')->nullable(); // Reference transaksi refund
            
            // Metadata
            $table->json('invoice_snapshot')->nullable();       // Snapshot invoice saat request
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['invoice_id', 'status']);
            $table->index(['status', 'created_at']);
        });
}

        /**
         * Dispute Requests
         * 
         * Dispute/sengketa atas transaksi atau layanan.
         * Berbeda dari refund - lebih kompleks, mungkin melibatkan investigasi.
         */
        if (!Schema::hasTable('dispute_requests')) {
            Schema::create('dispute_requests', function (Blueprint $table) {
            $table->id();
            $table->string('dispute_number', 32)->unique();
            
            // Relations
            $table->foreignId('klien_id')->constrained('klien');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions');
            $table->foreignId('related_refund_id')->nullable()->constrained('refund_requests');
            
            // Dispute type
            $table->enum('type', [
                'billing_error',        // Kesalahan billing
                'service_quality',      // Kualitas layanan tidak sesuai
                'unauthorized_charge',  // Transaksi tidak dikenal
                'sla_breach',           // Pelanggaran SLA
                'contract_issue',       // Masalah kontrak
                'other',                // Lainnya
            ]);
            
            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'critical',
            ])->default('medium');
            
            // Details
            $table->string('subject');                          // Judul dispute
            $table->text('description');                        // Penjelasan lengkap
            $table->json('evidence')->nullable();               // Array of evidence URLs/descriptions
            
            // Monetary impact
            $table->bigInteger('disputed_amount')->nullable();  // Jumlah yang disengketakan
            $table->bigInteger('resolved_amount')->nullable();  // Jumlah yang diselesaikan
            $table->string('currency', 3)->default('IDR');
            
            // Status flow
            $table->enum('status', [
                'submitted',        // Baru disubmit
                'acknowledged',     // Diterima
                'investigating',    // Dalam investigasi
                'pending_info',     // Menunggu info tambahan dari klien
                'resolved_favor_client',    // Diselesaikan menguntungkan klien
                'resolved_favor_owner',     // Diselesaikan menguntungkan owner
                'resolved_partial',         // Diselesaikan sebagian
                'rejected',                 // Ditolak
                'escalated',                // Dieskalasi
                'closed',                   // Ditutup
            ])->default('submitted');
            
            // Resolution details
            $table->enum('resolution_type', [
                'refund_full',              // Refund penuh
                'refund_partial',           // Refund sebagian
                'credit_compensation',      // Kompensasi credit
                'service_extension',        // Perpanjangan layanan
                'no_action',                // Tidak ada aksi
                'other',                    // Lainnya
            ])->nullable();
            $table->text('resolution_description')->nullable();
            
            // Owner handling
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            
            // Impact tracking
            $table->json('impact_analysis')->nullable();        // Analisis dampak
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['type', 'status']);
            $table->index(['priority', 'status']);
            $table->index(['status', 'created_at']);
        });
}

        /**
         * Refund Events (Audit Log)
         * 
         * Append-only log untuk semua perubahan pada refund request.
         */
        if (!Schema::hasTable('refund_events')) {
            Schema::create('refund_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refund_requests')->onDelete('cascade');
            
            $table->enum('event_type', [
                'created',
                'submitted',
                'status_changed',
                'reviewed',
                'approved',
                'rejected',
                'processing_started',
                'completed',
                'cancelled',
                'note_added',
                'amount_adjusted',
                'method_changed',
            ]);
            
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('comment')->nullable();
            
            $table->enum('actor_type', ['client', 'owner', 'system'])->default('system');
            $table->foreignId('actor_id')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['refund_id', 'event_type']);
            $table->index(['created_at']);
        });
}

        /**
         * Dispute Events (Audit Log)
         * 
         * Append-only log untuk semua perubahan pada dispute.
         */
        if (!Schema::hasTable('dispute_events')) {
            Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('dispute_requests')->onDelete('cascade');
            
            $table->enum('event_type', [
                'submitted',
                'acknowledged',
                'status_changed',
                'assigned',
                'investigation_started',
                'info_requested',
                'info_received',
                'resolved',
                'escalated',
                'closed',
                'note_added',
                'evidence_added',
            ]);
            
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('comment')->nullable();
            
            $table->enum('actor_type', ['client', 'owner', 'system'])->default('system');
            $table->foreignId('actor_id')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['dispute_id', 'event_type']);
            $table->index(['created_at']);
        });
}

        /**
         * Credit Transactions
         * 
         * Catatan transaksi credit balance (refund, kompensasi, dll).
         * Terhubung ke DompetSaldo.
         */
        if (!Schema::hasTable('credit_transactions')) {
            Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 32)->unique();
            
            $table->foreignId('klien_id')->constrained('klien');
            $table->foreignId('dompet_saldo_id')->constrained('dompet_saldo');
            
            $table->enum('type', [
                'refund',               // Dari refund
                'compensation',         // Kompensasi dispute
                'bonus',                // Bonus
                'adjustment',           // Manual adjustment
                'migration',            // Migrasi dari sistem lain
            ]);
            
            // Amount
            $table->bigInteger('amount');                       // Positif = tambah, negatif = kurang
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->string('currency', 3)->default('IDR');
            
            // Reference
            $table->nullableMorphs('reference');                // refund_request, dispute_request, etc.
            
            $table->text('description')->nullable();
            
            // Approval
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['klien_id', 'type']);
            $table->index(['created_at']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('refund_events');
        Schema::dropIfExists('dispute_requests');
        Schema::dropIfExists('refund_requests');
    }
};
