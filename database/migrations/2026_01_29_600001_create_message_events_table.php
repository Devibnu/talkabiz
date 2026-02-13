<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Message Events (Append-Only Audit Log)
 * 
 * Table ini menyimpan SEMUA webhook event yang diterima.
 * APPEND-ONLY: Tidak pernah update/delete, hanya insert.
 * 
 * TUJUAN:
 * =======
 * 1. AUDIT TRAIL - Rekam semua event untuk compliance
 * 2. DISPUTE PROOF - Bukti legal jika ada sengketa
 * 3. DEBUG - Analisis masalah dengan provider
 * 4. SLA TRACKING - Ukur delivery time, read time
 * 5. ANALYTICS - Aggregate stats
 * 
 * MENGAPA APPEND-ONLY?
 * ====================
 * - Data immutable = tidak bisa dipalsukan
 * - Event sequence terjaga
 * - Cocok untuk audit & legal
 * 
 * INDEXING STRATEGY:
 * ==================
 * - provider_message_id: Lookup utama dari webhook
 * - message_log_id: FK ke message_logs
 * - event_type + created_at: Untuk analytics
 * - klien_id + created_at: Per-client reporting
 * 
 * @author Senior Software Architect
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== MESSAGE EVENTS (APPEND-ONLY) ====================
        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            
            // Reference ke message
            $table->unsignedBigInteger('message_log_id')->nullable()->index();
            $table->unsignedBigInteger('klien_id')->nullable()->index();
            
            // Provider identifiers
            $table->string('provider_message_id', 100)->index()
                  ->comment('Message ID dari WABA/BSP');
            $table->string('provider_name', 50)->default('waba')
                  ->comment('gupshup, twilio, meta, dll');
            
            // Event details
            $table->string('event_type', 30)
                  ->comment('sent, delivered, read, failed, rejected, expired');
            $table->string('event_id', 100)->nullable()->index()
                  ->comment('Unique event ID dari provider (untuk idempotency)');
            $table->timestamp('event_timestamp')
                  ->comment('Timestamp dari provider, bukan waktu terima');
            
            // Status before/after (for audit)
            $table->string('status_before', 30)->nullable()
                  ->comment('Status MessageLog sebelum event');
            $table->string('status_after', 30)->nullable()
                  ->comment('Status MessageLog setelah event');
            $table->boolean('status_changed')->default(false)
                  ->comment('Apakah event ini mengubah status');
            
            // Error details (for failed/rejected)
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('error_category', 30)->nullable()
                  ->comment('retryable, permanent, unknown');
            
            // Recipient info
            $table->string('phone_number', 20)->nullable()
                  ->comment('Nomor penerima');
            
            // Raw payload for forensics
            $table->json('raw_payload')->nullable()
                  ->comment('Full webhook payload untuk dispute/debug');
            $table->string('webhook_signature', 200)->nullable()
                  ->comment('Signature untuk validasi');
            
            // Processing info
            $table->boolean('is_duplicate')->default(false)
                  ->comment('True jika event sudah pernah diproses');
            $table->boolean('is_out_of_order')->default(false)
                  ->comment('True jika event datang tidak berurutan');
            $table->string('process_result', 30)->default('processed')
                  ->comment('processed, ignored, error');
            $table->string('process_note', 200)->nullable();
            
            // Timing metrics
            $table->integer('delivery_time_seconds')->nullable()
                  ->comment('Waktu dari sent -> delivered');
            $table->integer('read_time_seconds')->nullable()
                  ->comment('Waktu dari delivered -> read');
            
            // Metadata
            $table->json('metadata')->nullable();
            
            // Timestamps
            $table->timestamp('received_at')->useCurrent()
                  ->comment('Waktu webhook diterima server');
            $table->timestamp('processed_at')->nullable()
                  ->comment('Waktu selesai diproses');
            $table->timestamps();
            
            // Composite indexes
            $table->index(['klien_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['message_log_id', 'event_type']);
            $table->index(['provider_message_id', 'event_type']);
            
            // Unique constraint untuk idempotency
            $table->unique(['provider_message_id', 'event_id'], 'unique_event');
        });

        // ==================== WEBHOOK RECEIPTS (RAW LOG) ====================
        // Untuk compliance: simpan SEMUA request masuk, bahkan yang gagal parse
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            
            $table->string('provider', 50)->default('waba');
            $table->string('endpoint', 100)
                  ->comment('Endpoint yang menerima webhook');
            
            // Request details
            $table->string('http_method', 10)->default('POST');
            $table->json('headers')->nullable();
            $table->longText('raw_body')
                  ->comment('Raw request body tanpa parsing');
            $table->string('content_type', 100)->nullable();
            $table->string('signature', 200)->nullable();
            $table->boolean('signature_valid')->nullable();
            
            // Processing
            $table->boolean('parsed_successfully')->default(false);
            $table->string('parse_error', 500)->nullable();
            $table->unsignedBigInteger('message_event_id')->nullable()
                  ->comment('FK ke message_events jika berhasil diproses');
            
            // Response
            $table->integer('response_code')->default(200);
            $table->string('response_message', 200)->nullable();
            
            // Source IP for security audit
            $table->string('source_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
            
            // Indexes
            $table->index('received_at');
            $table->index('provider');
            $table->index(['signature_valid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
        Schema::dropIfExists('message_events');
    }
};
