<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Canonicalize audit_logs table to unified schema.
 *
 * Problem: Two prior migrations (Feb 01 & Feb 08) create audit_logs
 * with incompatible schemas. The AuditLog model uses the richer Feb 08+
 * schema. This migration ALTERs the existing table to ensure ALL
 * required columns exist, regardless of which migration ran first.
 *
 * This migration is ADDITIVE only — it never drops columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // If table doesn't exist at all, create from scratch with canonical schema
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();

                // UUID & linking
                $table->string('log_uuid', 64)->unique()->nullable();
                $table->unsignedBigInteger('previous_log_id')->nullable();

                // Actor (who)
                $table->string('actor_type', 30)->default('system')->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('actor_email', 255)->nullable();
                $table->string('actor_ip', 45)->nullable();
                $table->text('actor_user_agent')->nullable();

                // Entity (what)
                $table->string('entity_type', 100)->index();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_uuid', 64)->nullable();

                // Multi-tenant
                $table->unsignedBigInteger('klien_id')->nullable()->index();

                // Correlation
                $table->string('correlation_id', 64)->nullable()->index();
                $table->string('session_id', 64)->nullable();

                // Action
                $table->string('action', 100)->index();
                $table->string('action_category', 50)->nullable()->index();
                $table->text('description')->nullable();

                // Data (before/after)
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('context')->nullable();

                // Status
                $table->string('status', 20)->default('success');
                $table->text('failure_reason')->nullable();

                // Classification
                $table->string('data_classification', 20)->default('internal');
                $table->boolean('contains_pii')->default(false);
                $table->boolean('is_masked')->default(true);

                // Retention & archival
                $table->string('retention_category', 30)->default('standard');
                $table->date('retention_until')->nullable();
                $table->boolean('is_archived')->default(false);
                $table->timestamp('archived_at')->nullable();

                // Integrity
                $table->string('checksum', 64)->nullable();

                // Timestamp
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                // Composite indexes
                $table->index(['entity_type', 'entity_id']);
                $table->index(['actor_type', 'actor_id']);
                $table->index(['action_category', 'occurred_at']);
                $table->index(['occurred_at']);
                $table->index(['is_archived', 'retention_until']);
            });

            return;
        }

        // Table exists — add missing columns (ADDITIVE only)
        Schema::table('audit_logs', function (Blueprint $table) {
            $columns = Schema::getColumnListing('audit_logs');

            if (!in_array('log_uuid', $columns)) {
                $table->string('log_uuid', 64)->nullable()->after('id');
            }
            if (!in_array('previous_log_id', $columns)) {
                $table->unsignedBigInteger('previous_log_id')->nullable()->after('log_uuid');
            }
            if (!in_array('actor_type', $columns)) {
                $table->string('actor_type', 30)->default('system')->after('previous_log_id');
            }
            if (!in_array('actor_id', $columns) && !Schema::hasColumn('audit_logs', 'actor_id')) {
                $table->unsignedBigInteger('actor_id')->nullable();
            }
            if (!in_array('actor_email', $columns)) {
                $table->string('actor_email', 255)->nullable();
            }
            if (!in_array('actor_ip', $columns)) {
                $table->string('actor_ip', 45)->nullable();
            }
            if (!in_array('actor_user_agent', $columns)) {
                $table->text('actor_user_agent')->nullable();
            }
            if (!in_array('entity_type', $columns)) {
                $table->string('entity_type', 100)->default('');
            }
            if (!in_array('entity_id', $columns)) {
                $table->unsignedBigInteger('entity_id')->nullable();
            }
            if (!in_array('entity_uuid', $columns)) {
                $table->string('entity_uuid', 64)->nullable();
            }
            if (!in_array('klien_id', $columns)) {
                $table->unsignedBigInteger('klien_id')->nullable();
            }
            if (!in_array('correlation_id', $columns)) {
                $table->string('correlation_id', 64)->nullable();
            }
            if (!in_array('session_id', $columns)) {
                $table->string('session_id', 64)->nullable();
            }
            if (!in_array('action', $columns)) {
                $table->string('action', 100)->default('');
            }
            if (!in_array('action_category', $columns)) {
                $table->string('action_category', 50)->nullable();
            }
            if (!in_array('description', $columns)) {
                $table->text('description')->nullable();
            }
            if (!in_array('old_values', $columns)) {
                $table->json('old_values')->nullable();
            }
            if (!in_array('new_values', $columns)) {
                $table->json('new_values')->nullable();
            }
            if (!in_array('context', $columns)) {
                $table->json('context')->nullable();
            }
            if (!in_array('status', $columns)) {
                $table->string('status', 20)->default('success');
            }
            if (!in_array('failure_reason', $columns)) {
                $table->text('failure_reason')->nullable();
            }
            if (!in_array('data_classification', $columns)) {
                $table->string('data_classification', 20)->default('internal');
            }
            if (!in_array('contains_pii', $columns)) {
                $table->boolean('contains_pii')->default(false);
            }
            if (!in_array('is_masked', $columns)) {
                $table->boolean('is_masked')->default(true);
            }
            if (!in_array('retention_category', $columns)) {
                $table->string('retention_category', 30)->default('standard');
            }
            if (!in_array('retention_until', $columns)) {
                $table->date('retention_until')->nullable();
            }
            if (!in_array('is_archived', $columns)) {
                $table->boolean('is_archived')->default(false);
            }
            if (!in_array('archived_at', $columns)) {
                $table->timestamp('archived_at')->nullable();
            }
            if (!in_array('checksum', $columns)) {
                $table->string('checksum', 64)->nullable();
            }
            if (!in_array('occurred_at', $columns)) {
                $table->timestamp('occurred_at')->nullable();
            }
        });

        // Ensure unique index on log_uuid if it has values
        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('actor_type', 'audit_logs_actor_type_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('entity_type', 'audit_logs_entity_type_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('action', 'audit_logs_action_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('occurred_at', 'audit_logs_occurred_at_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('action_category', 'audit_logs_action_category_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }
    }

    public function down(): void
    {
        // NEVER drop audit_logs — immutable by design.
        // This migration is additive only.
    }
};
