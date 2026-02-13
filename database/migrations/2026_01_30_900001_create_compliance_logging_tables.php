<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Compliance & Legal Log Retention System
 * 
 * PRINSIP:
 * - Append-only (tidak overwrite)
 * - Immutable record (no hard delete)
 * - Timestamp + actor (user/admin/system)
 * - Reason / context jelas
 * - Correlation ID (campaign_id / transaction_id)
 * 
 * Tables:
 * - audit_logs: Main audit trail untuk semua aktivitas
 * - admin_action_logs: Khusus admin actions
 * - config_change_logs: Perubahan konfigurasi sistem
 * - access_logs: Akses ke data sensitif
 * - legal_archives: Long-term storage untuk log archived
 * - retention_policies: Kebijakan retensi per log type
 * 
 * @author Compliance & Legal Engineering Specialist
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== AUDIT LOGS ====================
        // Main audit trail - append-only, immutable
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('log_uuid')->unique();
            
            // Actor (WHO)
            $table->string('actor_type', 20);  // user, admin, system, webhook, cron
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email', 100)->nullable();
            $table->string('actor_ip', 45)->nullable();
            $table->string('actor_user_agent', 500)->nullable();
            
            // Target (WHAT)
            $table->string('entity_type', 50);  // message, campaign, transaction, user, etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_uuid', 36)->nullable();
            
            // Context (WHERE)
            $table->unsignedBigInteger('klien_id')->nullable();
            $table->string('correlation_id', 100)->nullable();  // For linking related logs
            $table->string('session_id', 100)->nullable();
            
            // Action (HOW)
            $table->string('action', 50);  // create, update, delete, send, receive, etc.
            $table->string('action_category', 30);  // core, trust_safety, billing, auth, config
            
            // Data (DETAIL)
            $table->json('old_values')->nullable();  // Before state (masked)
            $table->json('new_values')->nullable();  // After state (masked)
            $table->json('context')->nullable();  // Additional context
            $table->text('description')->nullable();
            
            // Status
            $table->enum('status', ['success', 'failed', 'pending'])->default('success');
            $table->string('failure_reason', 500)->nullable();
            
            // Compliance
            $table->string('data_classification', 20)->default('internal');  // public, internal, confidential, restricted
            $table->boolean('contains_pii')->default(false);
            $table->boolean('is_masked')->default(false);
            
            // Integrity (optional hash for tamper detection)
            $table->string('checksum', 64)->nullable();  // SHA-256 of critical fields
            $table->unsignedBigInteger('previous_log_id')->nullable();  // Chain for integrity
            
            // Retention
            $table->string('retention_category', 30)->default('standard');
            $table->date('retention_until')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            
            $table->timestamp('occurred_at');
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['actor_type', 'actor_id']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['klien_id', 'occurred_at']);
            $table->index(['action_category', 'occurred_at']);
            $table->index('correlation_id');
            $table->index(['is_archived', 'retention_until']);
            $table->index('occurred_at');
        });

        // ==================== ADMIN ACTION LOGS ====================
        // Khusus untuk admin actions (lebih strict)
        Schema::create('admin_action_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('log_uuid')->unique();
            
            // Admin info
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_email', 100);
            $table->string('admin_role', 50);
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            
            // Action
            $table->string('action', 100);
            $table->string('action_category', 30);  // user_management, billing, abuse, config, etc.
            
            // Target
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('target_klien_id')->nullable();
            
            // Details
            $table->json('action_params')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();  // Required for sensitive actions
            $table->text('notes')->nullable();
            
            // Status
            $table->enum('status', ['success', 'failed', 'pending'])->default('success');
            $table->text('error_message')->nullable();
            
            // Approval (for sensitive actions)
            $table->boolean('requires_approval')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Integrity
            $table->string('checksum', 64)->nullable();
            
            $table->timestamp('performed_at');
            $table->timestamps();
            
            $table->index(['admin_id', 'performed_at']);
            $table->index(['action_category', 'performed_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('target_klien_id');
        });

        // ==================== CONFIG CHANGE LOGS ====================
        // Track semua perubahan konfigurasi sistem
        Schema::create('config_change_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('log_uuid')->unique();
            
            // Who changed
            $table->string('changed_by_type', 20);  // admin, system, migration
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('changed_by_email', 100)->nullable();
            
            // What changed
            $table->string('config_group', 50);  // payment_gateway, rate_limit, abuse_rules, etc.
            $table->string('config_key', 100);
            $table->text('old_value')->nullable();  // Encrypted/masked
            $table->text('new_value');  // Encrypted/masked
            $table->string('value_type', 20)->default('string');  // string, json, boolean, integer
            
            // Context
            $table->unsignedBigInteger('klien_id')->nullable();  // If tenant-specific
            $table->text('reason')->nullable();
            $table->string('source', 50)->default('admin_panel');  // admin_panel, api, migration, env
            
            // Impact assessment
            $table->enum('impact_level', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->boolean('requires_restart')->default(false);
            $table->boolean('affects_billing')->default(false);
            
            // Approval
            $table->boolean('requires_approval')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Rollback info
            $table->boolean('is_rollback')->default(false);
            $table->unsignedBigInteger('rollback_of_id')->nullable();
            
            $table->timestamp('changed_at');
            $table->timestamps();
            
            $table->index(['config_group', 'changed_at']);
            $table->index(['changed_by_type', 'changed_by_id']);
            $table->index('klien_id');
        });

        // ==================== ACCESS LOGS ====================
        // Track akses ke data sensitif (untuk audit trail)
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('log_uuid')->unique();
            
            // Who accessed
            $table->string('accessor_type', 20);  // user, admin, api, system
            $table->unsignedBigInteger('accessor_id')->nullable();
            $table->string('accessor_email', 100)->nullable();
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();
            
            // What was accessed
            $table->string('resource_type', 50);  // audit_log, message_log, transaction, etc.
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('resource_description', 255)->nullable();
            
            // Access type
            $table->string('access_type', 20);  // view, export, download, search
            $table->string('access_scope', 50)->nullable();  // single, bulk, report
            
            // Context
            $table->unsignedBigInteger('klien_id')->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('query_params', 500)->nullable();  // Masked sensitive params
            
            // Data sensitivity
            $table->string('data_classification', 20);  // internal, confidential, restricted
            $table->boolean('contains_pii')->default(false);
            $table->unsignedInteger('records_accessed')->default(0);
            
            // Purpose (required for sensitive data)
            $table->text('purpose')->nullable();
            $table->string('justification_code', 50)->nullable();  // support_ticket, audit, etc.
            
            // Status
            $table->enum('status', ['allowed', 'denied', 'flagged'])->default('allowed');
            $table->string('denial_reason', 255)->nullable();
            
            $table->timestamp('accessed_at');
            $table->timestamps();
            
            $table->index(['accessor_type', 'accessor_id', 'accessed_at']);
            $table->index(['resource_type', 'accessed_at']);
            $table->index(['klien_id', 'accessed_at']);
            $table->index(['data_classification', 'accessed_at']);
        });

        // ==================== LEGAL ARCHIVES ====================
        // Long-term storage untuk log yang sudah di-archive
        Schema::create('legal_archives', function (Blueprint $table) {
            $table->id();
            $table->uuid('archive_uuid')->unique();
            
            // Source
            $table->string('source_table', 50);  // audit_logs, message_logs, etc.
            $table->unsignedBigInteger('source_id');
            $table->string('source_uuid', 36)->nullable();
            
            // Archive info
            $table->string('archive_category', 30);  // financial, messaging, abuse, system
            $table->string('retention_policy', 50);
            $table->date('original_date');
            $table->date('archived_date');
            $table->date('expires_at');  // When can be permanently deleted
            
            // Compressed data
            $table->longText('archived_data');  // JSON, compressed, possibly encrypted
            $table->boolean('is_compressed')->default(true);
            $table->boolean('is_encrypted')->default(false);
            $table->string('compression_type', 20)->default('gzip');
            $table->string('encryption_key_id', 50)->nullable();
            
            // Integrity
            $table->string('data_checksum', 64);  // SHA-256 of original data
            $table->string('archive_checksum', 64);  // SHA-256 of archived data
            $table->unsignedBigInteger('original_size');
            $table->unsignedBigInteger('archived_size');
            
            // Metadata
            $table->unsignedBigInteger('klien_id')->nullable();
            $table->unsignedInteger('record_count')->default(1);
            $table->json('metadata')->nullable();  // Index fields for searching
            
            // Status
            $table->enum('status', ['active', 'pending_deletion', 'deleted'])->default('active');
            $table->timestamp('deletion_requested_at')->nullable();
            $table->unsignedBigInteger('deletion_requested_by')->nullable();
            
            $table->timestamps();
            
            $table->index(['source_table', 'original_date']);
            $table->index(['archive_category', 'archived_date']);
            $table->index(['klien_id', 'archived_date']);
            $table->index(['expires_at', 'status']);
            $table->index('retention_policy');
        });

        // ==================== RETENTION POLICIES ====================
        // Configurable retention policies
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            
            // Target
            $table->string('log_type', 50);  // audit_logs, message_logs, transactions, etc.
            $table->string('log_category', 50)->nullable();  // Subcategory filter
            
            // Retention periods (in days)
            $table->unsignedInteger('hot_retention_days');  // Keep in main table
            $table->unsignedInteger('warm_retention_days');  // Keep in archive (queryable)
            $table->unsignedInteger('cold_retention_days');  // Keep in cold storage
            $table->unsignedInteger('total_retention_days');  // Total before deletion
            
            // Actions
            $table->boolean('auto_archive')->default(true);
            $table->boolean('auto_compress')->default(true);
            $table->boolean('auto_encrypt')->default(false);
            $table->boolean('auto_delete')->default(false);  // false = manual approval
            
            // Legal hold
            $table->boolean('can_be_deleted')->default(true);
            $table->text('legal_basis')->nullable();  // Why we keep this data
            
            // Priority & status
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['log_type', 'is_active']);
        });

        // ==================== SEED DEFAULT RETENTION POLICIES ====================
        $this->seedRetentionPolicies();
    }

    protected function seedRetentionPolicies(): void
    {
        $policies = [
            // ===== FINANCIAL LOGS (7-10 years) =====
            [
                'code' => 'financial_transactions',
                'name' => 'Financial Transactions',
                'description' => 'Payment transactions, invoices, refunds - required for tax & audit',
                'log_type' => 'transactions',
                'hot_retention_days' => 365,      // 1 year online
                'warm_retention_days' => 1825,    // 5 years archive
                'cold_retention_days' => 1460,    // 4 years cold
                'total_retention_days' => 3650,   // 10 years total
                'auto_archive' => true,
                'auto_delete' => false,
                'can_be_deleted' => false,
                'legal_basis' => 'Tax law requires 10 year retention for financial records',
                'priority' => 10,
            ],
            [
                'code' => 'payment_webhooks',
                'name' => 'Payment Gateway Webhooks',
                'description' => 'Midtrans & Xendit webhook callbacks',
                'log_type' => 'webhook_receipts',
                'log_category' => 'payment',
                'hot_retention_days' => 365,
                'warm_retention_days' => 1825,
                'cold_retention_days' => 1460,
                'total_retention_days' => 3650,
                'auto_archive' => true,
                'auto_delete' => false,
                'can_be_deleted' => false,
                'legal_basis' => 'Payment dispute resolution requires full webhook history',
                'priority' => 15,
            ],
            [
                'code' => 'quota_changes',
                'name' => 'Quota Changes',
                'description' => 'All quota reservations, consumptions, refunds',
                'log_type' => 'quota_transactions',
                'hot_retention_days' => 365,
                'warm_retention_days' => 1825,
                'cold_retention_days' => 365,
                'total_retention_days' => 2555,   // 7 years
                'auto_archive' => true,
                'auto_delete' => false,
                'legal_basis' => 'Billing dispute requires quota history',
                'priority' => 20,
            ],

            // ===== MESSAGE LOGS (12-24 months) =====
            [
                'code' => 'message_logs',
                'name' => 'Message Logs',
                'description' => 'WhatsApp message sending records',
                'log_type' => 'wa_message_logs',
                'hot_retention_days' => 90,       // 3 months online
                'warm_retention_days' => 275,     // 9 months archive
                'cold_retention_days' => 365,     // 1 year cold
                'total_retention_days' => 730,    // 2 years total
                'auto_archive' => true,
                'auto_compress' => true,
                'auto_delete' => true,
                'legal_basis' => 'BSP requirements and customer dispute resolution',
                'priority' => 30,
            ],
            [
                'code' => 'message_events',
                'name' => 'Delivery Reports',
                'description' => 'Message delivery status events (sent, delivered, read, failed)',
                'log_type' => 'message_events',
                'hot_retention_days' => 90,
                'warm_retention_days' => 275,
                'cold_retention_days' => 365,
                'total_retention_days' => 730,
                'auto_archive' => true,
                'auto_compress' => true,
                'auto_delete' => true,
                'legal_basis' => 'Delivery proof for customer disputes',
                'priority' => 35,
            ],
            [
                'code' => 'campaign_executions',
                'name' => 'Campaign Executions',
                'description' => 'Campaign start, progress, completion logs',
                'log_type' => 'campaign_logs',
                'hot_retention_days' => 180,
                'warm_retention_days' => 365,
                'cold_retention_days' => 180,
                'total_retention_days' => 730,
                'auto_archive' => true,
                'auto_delete' => true,
                'priority' => 40,
            ],

            // ===== TRUST & SAFETY (24-36 months) =====
            [
                'code' => 'abuse_events',
                'name' => 'Abuse Events',
                'description' => 'Detected abuse incidents',
                'log_type' => 'abuse_events',
                'hot_retention_days' => 365,
                'warm_retention_days' => 365,
                'cold_retention_days' => 365,
                'total_retention_days' => 1095,   // 3 years
                'auto_archive' => true,
                'auto_delete' => false,
                'can_be_deleted' => false,
                'legal_basis' => 'Trust & Safety investigation, BSP compliance',
                'priority' => 25,
            ],
            [
                'code' => 'risk_scores',
                'name' => 'Risk Score History',
                'description' => 'Anti-ban risk scoring events',
                'log_type' => 'risk_events',
                'hot_retention_days' => 180,
                'warm_retention_days' => 365,
                'cold_retention_days' => 365,
                'total_retention_days' => 910,    // 2.5 years
                'auto_archive' => true,
                'auto_delete' => true,
                'priority' => 45,
            ],
            [
                'code' => 'suspension_history',
                'name' => 'Suspension History',
                'description' => 'User suspension and restriction records',
                'log_type' => 'suspension_history',
                'hot_retention_days' => 365,
                'warm_retention_days' => 730,
                'cold_retention_days' => 365,
                'total_retention_days' => 1460,   // 4 years
                'auto_archive' => true,
                'auto_delete' => false,
                'can_be_deleted' => false,
                'legal_basis' => 'Legal defense for suspension disputes',
                'priority' => 22,
            ],
            [
                'code' => 'throttle_events',
                'name' => 'Throttle Events',
                'description' => 'Rate limiting events',
                'log_type' => 'throttle_events',
                'hot_retention_days' => 30,
                'warm_retention_days' => 60,
                'cold_retention_days' => 0,
                'total_retention_days' => 90,
                'auto_archive' => false,
                'auto_delete' => true,
                'priority' => 80,
            ],

            // ===== SYSTEM & ACCESS (30-90 days for debug, longer for audit) =====
            [
                'code' => 'audit_logs',
                'name' => 'Audit Logs',
                'description' => 'Main audit trail for all activities',
                'log_type' => 'audit_logs',
                'hot_retention_days' => 365,
                'warm_retention_days' => 730,
                'cold_retention_days' => 365,
                'total_retention_days' => 1460,   // 4 years
                'auto_archive' => true,
                'auto_delete' => false,
                'legal_basis' => 'Comprehensive audit trail for compliance',
                'priority' => 5,
            ],
            [
                'code' => 'admin_actions',
                'name' => 'Admin Action Logs',
                'description' => 'Administrative actions and changes',
                'log_type' => 'admin_action_logs',
                'hot_retention_days' => 365,
                'warm_retention_days' => 730,
                'cold_retention_days' => 730,
                'total_retention_days' => 1825,   // 5 years
                'auto_archive' => true,
                'auto_delete' => false,
                'can_be_deleted' => false,
                'legal_basis' => 'Admin accountability and audit compliance',
                'priority' => 8,
            ],
            [
                'code' => 'config_changes',
                'name' => 'Configuration Changes',
                'description' => 'System configuration change history',
                'log_type' => 'config_change_logs',
                'hot_retention_days' => 365,
                'warm_retention_days' => 365,
                'cold_retention_days' => 365,
                'total_retention_days' => 1095,   // 3 years
                'auto_archive' => true,
                'auto_delete' => true,
                'priority' => 50,
            ],
            [
                'code' => 'access_logs',
                'name' => 'Data Access Logs',
                'description' => 'Logs of access to sensitive data',
                'log_type' => 'access_logs',
                'hot_retention_days' => 180,
                'warm_retention_days' => 365,
                'cold_retention_days' => 365,
                'total_retention_days' => 910,
                'auto_archive' => true,
                'auto_delete' => true,
                'priority' => 55,
            ],
            [
                'code' => 'auth_logs',
                'name' => 'Authentication Logs',
                'description' => 'Login attempts and authentication events',
                'log_type' => 'auth_logs',
                'hot_retention_days' => 90,
                'warm_retention_days' => 275,
                'cold_retention_days' => 0,
                'total_retention_days' => 365,
                'auto_archive' => true,
                'auto_delete' => true,
                'priority' => 60,
            ],
            [
                'code' => 'debug_logs',
                'name' => 'Debug Logs',
                'description' => 'System debug and error logs',
                'log_type' => 'debug_logs',
                'hot_retention_days' => 30,
                'warm_retention_days' => 30,
                'cold_retention_days' => 0,
                'total_retention_days' => 60,
                'auto_archive' => false,
                'auto_delete' => true,
                'priority' => 100,
            ],
        ];

        $now = now();
        foreach ($policies as &$policy) {
            $policy['is_active'] = true;
            $policy['created_at'] = $now;
            $policy['updated_at'] = $now;
            
            // Ensure all fields have defaults
            $policy['log_category'] = $policy['log_category'] ?? null;
            $policy['auto_compress'] = $policy['auto_compress'] ?? true;
            $policy['auto_encrypt'] = $policy['auto_encrypt'] ?? false;
            $policy['can_be_deleted'] = $policy['can_be_deleted'] ?? true;
            $policy['legal_basis'] = $policy['legal_basis'] ?? null;
        }

        DB::table('retention_policies')->insert($policies);
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('legal_archives');
        Schema::dropIfExists('access_logs');
        Schema::dropIfExists('config_change_logs');
        Schema::dropIfExists('admin_action_logs');
        Schema::dropIfExists('audit_logs');
    }
};
