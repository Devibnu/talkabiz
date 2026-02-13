<?php

namespace App\Models\Traits;

use RuntimeException;

/**
 * ImmutableLedger — Append-Only Protection for Financial Models
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Tidak boleh UPDATE record yang sudah ada
 * ❌ Tidak boleh DELETE record
 * ✅ Hanya INSERT diperbolehkan
 * ✅ Koreksi via record baru (adjustment / reversal)
 *
 * Trait ini WAJIB digunakan pada:
 * - WalletTransaction
 * - PaymentTransaction
 * - TaxReport (saat status = final)
 * - MonthlyClosing (saat status = CLOSED)
 * - AuditLog (sudah punya protection sendiri, tapi bisa juga pakai ini)
 *
 * CARA KERJA:
 * 1. Model berbasis trait ini akan throw RuntimeException jika:
 *    - Mencoba update() row yang sudah final/completed
 *    - Mencoba delete() row apapun
 * 2. Untuk model yang perlu status transition (draft → final):
 *    - Override `isImmutable(): bool` di model
 *    - Return false saat masih boleh diubah (draft)
 *    - Return true setelah final
 * 3. Untuk model yang 100% immutable sejak insert:
 *    - Jangan override, default = always immutable
 */
trait ImmutableLedger
{
    /**
     * Boot the trait — register model event protection.
     */
    public static function bootImmutableLedger(): void
    {
        // Guard UPDATE
        static::updating(function ($model) {
            if ($model->isLedgerImmutable()) {
                throw new RuntimeException(
                    sprintf(
                        '[IMMUTABLE LEDGER] %s #%s tidak boleh di-UPDATE. '
                        . 'Gunakan reversal/adjustment record baru.',
                        class_basename($model),
                        $model->getKey()
                    )
                );
            }
        });

        // Guard DELETE (termasuk soft-delete)
        static::deleting(function ($model) {
            throw new RuntimeException(
                sprintf(
                    '[IMMUTABLE LEDGER] %s #%s tidak boleh di-DELETE. '
                    . 'Data finansial bersifat append-only.',
                    class_basename($model),
                    $model->getKey()
                )
            );
        });
    }

    /**
     * Determine apakah record ini sudah immutable.
     *
     * Override di model untuk status-based immutability:
     * - WalletTransaction: immutable jika status = completed
     * - TaxReport: immutable jika status = final
     * - MonthlyClosing: immutable jika status = CLOSED
     * - PaymentTransaction: immutable jika status = success
     *
     * Default: SELALU immutable (semua row).
     */
    public function isLedgerImmutable(): bool
    {
        return true;
    }

    /**
     * Get alasan kenapa record ini immutable.
     */
    public function getImmutableReasonAttribute(): string
    {
        if ($this->isLedgerImmutable()) {
            return sprintf(
                '%s #%s is immutable (append-only ledger). Use reversal/adjustment instead.',
                class_basename($this),
                $this->getKey()
            );
        }

        return 'Record is currently mutable (draft/pending state).';
    }

    /**
     * Force-update HANYA untuk keperluan system internal tertentu.
     * Bypass model protection, langsung ke DB.
     *
     * HANYA boleh digunakan untuk:
     * - Archive marking
     * - System-level status migration
     *
     * @param array $attributes
     * @return int affected rows
     */
    protected function forceSystemUpdate(array $attributes): int
    {
        return static::query()
            ->where($this->getKeyName(), $this->getKey())
            ->update($attributes);
    }
}
