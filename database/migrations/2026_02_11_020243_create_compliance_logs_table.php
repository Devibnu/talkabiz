<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Compliance & Legal Log - Enterprise Grade
     * 
     * IMMUTABLE TABLE - Append Only, No Update, No Delete
     * Hash-chained for tamper evidence.
     * Designed for legal proceedings and regulatory compliance.
     */
    public function up(): void
    {
        Schema::create('compliance_logs', function (Blueprint $table) {
            // Primary key - ordered ULID for chronological ordering
            $table->id();
            $table->char('log_ulid', 26)->unique()->comment('ULID for globally unique, time-ordered ID');

            // Hash chain for tamper evidence
            $table->string('record_hash', 64)->comment('SHA-256 hash of this record');
            $table->string('previous_hash', 64)->nullable()->comment('Hash of previous record for chain integrity');
            $table->unsignedBigInteger('sequence_number')->comment('Monotonic sequence for ordering guarantee');

            // Module & Action Classification
            $table->string('module', 50)->index()->comment('wallet, billing, risk, abuse, approval, complaint');
            $table->string('action', 100)->index()->comment('Specific action: topup, deduct, approve, suspend, etc.');
            $table->enum('severity', ['info', 'warning', 'critical', 'legal'])->default('info')->index();
            $table->enum('outcome', ['success', 'failure', 'partial', 'denied'])->default('success');

            // Actor Information (who performed the action)
            $table->string('actor_type', 30)->comment('user, admin, system, webhook, cron');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 255)->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->string('actor_role', 50)->nullable();
            $table->string('actor_ip', 45)->nullable();
            $table->text('actor_user_agent')->nullable();
            $table->string('actor_session_id', 128)->nullable();

            // Target Information (what was acted upon)
            $table->string('target_type', 100)->nullable()->comment('klien, wallet, complaint, abuse_score, etc.');
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->string('target_label', 255)->nullable()->comment('Human-readable target identifier');

            // Klien context (if action relates to a klien)
            $table->unsignedBigInteger('klien_id')->nullable()->index();

            // Description & Context
            $table->text('description')->comment('Human-readable description of the action');
            $table->json('before_state')->nullable()->comment('State before the action');
            $table->json('after_state')->nullable()->comment('State after the action');
            $table->json('context')->nullable()->comment('Module-specific structured context');
            $table->json('evidence')->nullable()->comment('Supporting evidence (amounts, IDs, etc.)');

            // Financial fields (for wallet/billing module)
            $table->decimal('amount', 15, 2)->nullable()->comment('Financial amount if applicable');
            $table->string('currency', 3)->nullable()->default('IDR');

            // Correlation & Tracing
            $table->string('correlation_id', 64)->nullable()->index()->comment('Links related compliance events');
            $table->string('request_id', 64)->nullable()->comment('HTTP request ID for tracing');
            $table->string('idempotency_key', 128)->nullable()->unique()->comment('Prevents duplicate log entries');

            // Legal & Compliance metadata
            $table->string('legal_basis', 100)->nullable()->comment('Legal provision: OJK, UU ITE, GDPR, company_policy');
            $table->string('regulation_ref', 100)->nullable()->comment('Specific regulation reference');
            $table->date('retention_until')->nullable()->comment('Minimum retention date');
            $table->boolean('is_sensitive')->default(false)->comment('Contains sensitive/PII data');
            $table->boolean('is_financial')->default(false)->index()->comment('Financial transaction log');

            // Timestamp - use occurred_at as the authoritative time
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('created_at')->useCurrent();

            // Composite indexes for common queries
            $table->index(['module', 'action', 'occurred_at']);
            $table->index(['klien_id', 'module', 'occurred_at']);
            $table->index(['actor_id', 'actor_type', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
            $table->index(['is_financial', 'occurred_at']);
            $table->index(['target_type', 'target_id']);
        });

        // Create immutability triggers (prevent UPDATE and DELETE)
        DB::unprepared("
            CREATE TRIGGER compliance_logs_no_update 
            BEFORE UPDATE ON compliance_logs 
            FOR EACH ROW 
            BEGIN
                SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'COMPLIANCE VIOLATION: compliance_logs records are immutable and cannot be updated';
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER compliance_logs_no_delete 
            BEFORE DELETE ON compliance_logs 
            FOR EACH ROW 
            BEGIN
                SIGNAL SQLSTATE '45000' 
                SET MESSAGE_TEXT = 'COMPLIANCE VIOLATION: compliance_logs records are immutable and cannot be deleted';
            END;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop triggers first
        DB::unprepared("DROP TRIGGER IF EXISTS compliance_logs_no_update");
        DB::unprepared("DROP TRIGGER IF EXISTS compliance_logs_no_delete");

        Schema::dropIfExists('compliance_logs');
    }
};
