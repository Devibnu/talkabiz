<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message Logs Table - Financial-Grade Idempotency Message Tracking
 * 
 * TUJUAN:
 * ======
 * 1. Satu pesan logis = maksimal 1 kali terkirim
 * 2. Retry worker TIDAK BOLEH mengirim ulang pesan sukses
 * 3. Audit trail lengkap untuk setiap pesan
 * 4. Mencegah double-send pada timeout/crash
 * 
 * IDEMPOTENCY KEY:
 * ================
 * Format: msg_{context}_{unique_id}
 * Contoh:
 * - Campaign: msg_campaign_{kampanye_id}_{target_id}
 * - Inbox: msg_inbox_{percakapan_id}_{uuid}
 * - API: msg_api_{klien_id}_{uuid}
 * 
 * Scope: Per message (bukan per user/campaign)
 * Ini memastikan setiap PESAN INDIVIDUAL unik
 * 
 * STATE MACHINE:
 * ==============
 * pending → sending → sent (FINAL SUCCESS)
 *                  → failed → can retry → sending
 *                  → failed (MAX_RETRIES) (FINAL FAIL)
 * 
 * ATURAN RETRY:
 * =============
 * - Status 'sent' = FINAL, TIDAK BOLEH retry
 * - Status 'sending' = Worker aktif, skip
 * - Status 'failed' dengan retry < max = BOLEH retry
 * - Status 'pending' = Belum diproses, BOLEH process
 * 
 * @author Senior Software Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            
            // ==================== RELASI ====================
            $table->unsignedBigInteger('klien_id');
            $table->unsignedBigInteger('pengguna_id')->nullable(); // User yang trigger
            $table->unsignedBigInteger('kampanye_id')->nullable(); // Null jika bukan campaign
            $table->unsignedBigInteger('target_kampanye_id')->nullable(); // Spesifik target
            $table->unsignedBigInteger('percakapan_inbox_id')->nullable(); // Jika dari inbox
            
            // ==================== IDEMPOTENCY ====================
            /**
             * UNIQUE KEY - Jantung idempotency
             * 
             * Format yang aman:
             * - Campaign: "msg_campaign_{kampanye_id}_{target_id}"
             * - Inbox: "msg_inbox_{percakapan_id}_{uuid}"
             * - API: "msg_api_{klien_id}_{uuid}"
             * 
             * CRITICAL: Key HARUS dibuat SEBELUM insert record
             * Jika insert gagal karena duplicate = idempotent (skip)
             */
            $table->string('idempotency_key', 128)->unique();
            
            // ==================== MESSAGE DATA ====================
            $table->string('phone_number', 20); // Nomor tujuan (format: 628xxx)
            $table->enum('message_type', [
                'text',
                'template',
                'image',
                'document',
                'audio', 
                'video',
                'location',
                'contact',
                'interactive', // Buttons, lists
            ])->default('text');
            
            /**
             * Template name jika type = template
             */
            $table->string('template_name')->nullable();
            
            /**
             * Content hash untuk detect duplicate content
             * MD5(phone + content) - optional dedup layer
             */
            $table->string('content_hash', 32)->nullable()->index();
            
            /**
             * Message content (encrypted in production)
             * Tidak menyimpan full content, hanya summary untuk audit
             */
            $table->text('message_content')->nullable();
            
            /**
             * Variables/parameters untuk template
             */
            $table->json('message_params')->nullable();
            
            // ==================== STATUS (STATE MACHINE) ====================
            /**
             * Status lifecycle:
             * 
             * pending  → Baru dibuat, belum diproses worker
             * sending  → Worker sedang memproses, JANGAN sentuh
             * sent     → SUKSES terkirim ke WA API (FINAL SUCCESS)
             * delivered→ Sudah sampai ke device penerima
             * read     → Sudah dibaca penerima
             * failed   → Gagal, cek retry_count untuk retry eligibility
             * expired  → Timeout / dibatalkan
             */
            $table->enum('status', [
                'pending',   // Baru dibuat
                'sending',   // Sedang diproses worker
                'sent',      // FINAL: Berhasil terkirim
                'delivered', // Terkirim ke device
                'read',      // Dibaca
                'failed',    // Gagal
                'expired',   // Timeout / cancelled
            ])->default('pending');
            
            /**
             * Sub-status untuk detail error
             */
            $table->string('status_detail')->nullable();
            
            // ==================== PROVIDER RESPONSE ====================
            /**
             * Message ID dari WhatsApp provider
             * Ini BUKTI bahwa pesan sudah terkirim
             */
            $table->string('provider_message_id', 128)->nullable()->index();
            
            /**
             * Provider name (gupshup, fonnte, wablas, dll)
             */
            $table->string('provider_name', 50)->nullable();
            
            /**
             * Raw response dari provider (untuk debugging)
             */
            $table->json('provider_response')->nullable();
            
            /**
             * HTTP status code dari provider
             */
            $table->unsignedSmallInteger('provider_http_code')->nullable();
            
            // ==================== ERROR HANDLING ====================
            /**
             * Error code untuk categorization
             * Contoh: TIMEOUT, RATE_LIMIT, INVALID_NUMBER, QUOTA_EXCEEDED
             */
            $table->string('error_code', 50)->nullable()->index();
            
            /**
             * Human readable error message
             */
            $table->text('error_message')->nullable();
            
            /**
             * Apakah error bisa di-retry?
             * - true: Network error, timeout, rate limit
             * - false: Invalid number, blocked, permanent error
             */
            $table->boolean('is_retryable')->default(false);
            
            // ==================== RETRY TRACKING ====================
            /**
             * Jumlah retry yang sudah dilakukan
             */
            $table->unsignedTinyInteger('retry_count')->default(0);
            
            /**
             * Max retry allowed (bisa override per message)
             */
            $table->unsignedTinyInteger('max_retries')->default(3);
            
            /**
             * Kapan retry berikutnya boleh dilakukan
             * Null = boleh retry sekarang
             */
            $table->timestamp('retry_after')->nullable();
            
            /**
             * UUID job queue yang sedang memproses
             * Untuk detect orphan job
             */
            $table->string('processing_job_id', 64)->nullable();
            
            /**
             * Kapan job mulai memproses
             * Untuk detect stuck job
             */
            $table->timestamp('processing_started_at')->nullable();
            
            // ==================== QUOTA TRACKING ====================
            /**
             * Apakah kuota sudah dipotong untuk pesan ini?
             * true = kuota sudah dipotong, jangan potong lagi
             */
            $table->boolean('quota_consumed')->default(false);
            
            /**
             * ID record di quota_consumed_keys (cross-reference)
             */
            $table->string('quota_idempotency_key', 128)->nullable();
            
            /**
             * Biaya pesan ini (untuk tracking)
             */
            $table->unsignedInteger('message_cost')->default(1);
            
            // ==================== TIMESTAMPS ====================
            /**
             * Kapan pesan dijadwalkan kirim
             */
            $table->timestamp('scheduled_at')->nullable();
            
            /**
             * Kapan pesan mulai dikirim (status → sending)
             */
            $table->timestamp('sending_at')->nullable();
            
            /**
             * Kapan pesan sukses terkirim (status → sent)
             */
            $table->timestamp('sent_at')->nullable();
            
            /**
             * Kapan pesan delivered ke device
             */
            $table->timestamp('delivered_at')->nullable();
            
            /**
             * Kapan pesan dibaca
             */
            $table->timestamp('read_at')->nullable();
            
            /**
             * Kapan pesan gagal (final fail)
             */
            $table->timestamp('failed_at')->nullable();
            
            /**
             * Metadata tambahan (JSON)
             */
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes(); // Untuk audit, jangan hard delete
            
            // ==================== INDEXES ====================
            
            // Query by klien
            $table->index('klien_id');
            
            // Query by campaign
            $table->index('kampanye_id');
            
            // Find messages by phone (untuk resend protection)
            $table->index('phone_number');
            
            // Status queries
            $table->index('status');
            
            // Find retryable messages
            $table->index(['status', 'retry_count', 'retry_after']);
            
            // Find stuck processing messages
            $table->index(['status', 'processing_started_at']);
            
            // Timeline queries
            $table->index('sent_at');
            $table->index('created_at');
            
            // Composite: klien + status untuk dashboard
            $table->index(['klien_id', 'status']);
            
            // Composite: campaign progress
            $table->index(['kampanye_id', 'status']);
            
            // Composite: find pending for specific campaign
            $table->index(['kampanye_id', 'status', 'created_at']);
            
            // Foreign keys (tanpa constraint karena tabel mungkin belum ada)
            // Di production, tambahkan constraint sesuai kebutuhan
        });
        
        // ==================== MESSAGE SENDING LOCKS ====================
        /**
         * Tabel untuk distributed lock pada message sending
         * Mencegah race condition saat multiple worker
         */
        Schema::create('message_sending_locks', function (Blueprint $table) {
            $table->id();
            
            /**
             * Lock key = idempotency_key dari message_logs
             */
            $table->string('lock_key', 128)->unique();
            
            /**
             * Worker/Job ID yang hold lock
             */
            $table->string('holder_id', 64);
            
            /**
             * Kapan lock akan expire (auto-release jika worker mati)
             */
            $table->timestamp('expires_at');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Index untuk cleanup expired locks
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sending_locks');
        Schema::dropIfExists('message_logs');
    }
};
