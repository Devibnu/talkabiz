<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-Level Protection Triggers for Immutable Ledger Tables.
 *
 * DEFENSE IN DEPTH:
 * - Model layer: ImmutableLedger trait (PHP)
 * - Database layer: MySQL triggers (SQL)
 *
 * Even if someone bypasses the Eloquent model layer (raw queries,
 * DB::table(), tinker, migration scripts), the database will REFUSE
 * UPDATE/DELETE on immutable financial records.
 *
 * Trigger targets:
 * 1. audit_logs       — ALWAYS immutable (no update, no delete)
 * 2. wallet_transactions — immutable after status = completed/failed
 * 3. payment_transactions — immutable after status = success/failed/expired/refunded
 *
 * NOTE: TaxReport & MonthlyClosing are NOT given DB triggers because
 * they need status transitions (draft → final, DRAFT → CLOSED).
 * Their protection is model-only via ImmutableLedger trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            // SQLite (testing) and other drivers: skip triggers
            return;
        }

        // ============================================================
        // 1. audit_logs — NO UPDATE, NO DELETE (absolute)
        // ============================================================
        DB::unprepared("
            DROP TRIGGER IF EXISTS prevent_audit_log_update;
            CREATE TRIGGER prevent_audit_log_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                -- Allow only archival (is_archived flag change)
                IF NOT (OLD.is_archived = 0 AND NEW.is_archived = 1) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = '[IMMUTABLE] audit_logs cannot be updated. Append-only.';
                END IF;
            END;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS prevent_audit_log_delete;
            CREATE TRIGGER prevent_audit_log_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = '[IMMUTABLE] audit_logs cannot be deleted. Append-only.';
            END;
        ");

        // ============================================================
        // 2. wallet_transactions — NO UPDATE/DELETE after completed/failed
        // ============================================================
        if (Schema::hasTable('wallet_transactions')) {
            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_wallet_trx_update;
                CREATE TRIGGER prevent_wallet_trx_update
                BEFORE UPDATE ON wallet_transactions
                FOR EACH ROW
                BEGIN
                    IF OLD.status IN ('completed', 'failed') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = '[IMMUTABLE] Completed/failed wallet_transactions cannot be updated.';
                    END IF;
                END;
            ");

            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_wallet_trx_delete;
                CREATE TRIGGER prevent_wallet_trx_delete
                BEFORE DELETE ON wallet_transactions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = '[IMMUTABLE] wallet_transactions cannot be deleted. Use reversal.';
                END;
            ");
        }

        // ============================================================
        // 3. payment_transactions — NO UPDATE/DELETE after terminal states
        // ============================================================
        if (Schema::hasTable('payment_transactions')) {
            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_payment_trx_update;
                CREATE TRIGGER prevent_payment_trx_update
                BEFORE UPDATE ON payment_transactions
                FOR EACH ROW
                BEGIN
                    IF OLD.status IN ('success', 'failed', 'expired', 'refunded') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = '[IMMUTABLE] Terminal payment_transactions cannot be updated.';
                    END IF;
                END;
            ");

            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_payment_trx_delete;
                CREATE TRIGGER prevent_payment_trx_delete
                BEFORE DELETE ON payment_transactions
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = '[IMMUTABLE] payment_transactions cannot be deleted. Use refund record.';
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_audit_log_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_wallet_trx_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_wallet_trx_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_payment_trx_update');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_payment_trx_delete');
    }
};
