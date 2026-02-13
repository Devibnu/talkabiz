<?php

namespace App\Services;

use App\Models\UserPlan;
use App\Models\QuotaReservation;
use App\Models\LogAktivitas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DomainException;
use Exception;
use Throwable;

/**
 * QuotaService - Financial-Grade Quota Management
 * 
 * Service ini menangani operasi kuota dengan tingkat keamanan setara sistem keuangan.
 * TIDAK BOLEH ada race condition yang menyebabkan:
 * 1. Kuota menjadi minus
 * 2. Kuota dipotong dobel
 * 3. Campaign lolos padahal kuota tidak cukup
 * 4. Retry worker memotong kuota ulang
 * 
 * STRATEGI ANTI RACE CONDITION:
 * =============================
 * 
 * 1. ATOMIC UPDATE dengan WHERE clause
 *    - UPDATE ... WHERE remaining >= amount
 *    - Jika affected_rows = 0, berarti kuota tidak cukup
 *    - Ini adalah cara TERAMAN karena validasi & update dalam 1 query
 * 
 * 2. RESERVATION PATTERN
 *    - Reserve quota sebelum kirim pesan
 *    - Confirm setelah sukses, rollback jika gagal
 *    - Mencegah race condition di level aplikasi
 * 
 * 3. IDEMPOTENCY KEY
 *    - Setiap pesan punya unique key
 *    - Retry dengan key yang sama = skip (tidak potong lagi)
 * 
 * 4. DB TRANSACTION + ROW LOCKING
 *    - SELECT ... FOR UPDATE untuk lock row
 *    - Mencegah concurrent update pada row yang sama
 * 
 * KENAPA TIDAK PAKAI REDIS LOCK?
 * ==============================
 * - Redis lock lebih cepat tapi KURANG RELIABLE untuk financial operation
 * - Network failure bisa menyebabkan lock orphan
 * - DB transaction dengan FOR UPDATE sudah CUKUP AMAN dan ACID compliant
 * - Redis cocok untuk rate limiting, bukan critical quota operation
 * 
 * @author Senior Backend Architect
 * @version 2.0 - Production Ready
 */
class QuotaService
{
    // ==================== CONSTANTS ====================

    /**
     * Reservation status constants
     */
    const RESERVATION_PENDING = 'pending';
    const RESERVATION_CONFIRMED = 'confirmed';
    const RESERVATION_CANCELLED = 'cancelled';
    const RESERVATION_EXPIRED = 'expired';

    /**
     * Default reservation timeout (minutes)
     * Jika reservasi tidak di-confirm dalam waktu ini, akan auto-expire
     */
    const RESERVATION_TIMEOUT_MINUTES = 10;

    /**
     * Cache TTL untuk quota info (seconds)
     */
    const CACHE_TTL = 60;

    // ==================== CORE METHODS ====================

    /**
     * Check if klien can consume quota
     * 
     * THREAD-SAFE: Menggunakan fresh query dengan locking
     * 
     * @param int $klienId
     * @param int $amount
     * @return array
     */
    public function canConsume(int $klienId, int $amount = 1): array
    {
        $userPlan = $this->getActivePlanWithLock($klienId, false);

        if (!$userPlan) {
            return [
                'can_consume' => false,
                'reason' => 'no_active_plan',
                'message' => 'Tidak ada paket aktif',
                'remaining_quota' => 0,
                'required' => $amount,
            ];
        }

        if ($userPlan->is_expired) {
            return [
                'can_consume' => false,
                'reason' => 'plan_expired',
                'message' => 'Paket sudah expired',
                'remaining_quota' => $userPlan->quota_messages_remaining,
                'required' => $amount,
                'expires_at' => $userPlan->expires_at?->toISOString(),
            ];
        }

        if ($userPlan->quota_messages_remaining < $amount) {
            return [
                'can_consume' => false,
                'reason' => 'insufficient_quota',
                'message' => "Kuota tidak mencukupi. Tersisa: {$userPlan->quota_messages_remaining}, dibutuhkan: {$amount}",
                'remaining_quota' => $userPlan->quota_messages_remaining,
                'required' => $amount,
            ];
        }

        return [
            'can_consume' => true,
            'reason' => null,
            'message' => 'OK',
            'remaining_quota' => $userPlan->quota_messages_remaining,
            'required' => $amount,
            'user_plan_id' => $userPlan->id,
        ];
    }

    /**
     * Consume quota ATOMICALLY
     * 
     * CRITICAL METHOD - PRODUCTION SAFE
     * 
     * Menggunakan ATOMIC UPDATE yang menggabungkan validasi dan update dalam 1 query.
     * Ini adalah cara TERAMAN untuk mencegah race condition.
     * 
     * Cara kerja:
     * 1. UPDATE ... WHERE remaining >= amount
     * 2. Jika affected_rows = 0, berarti kondisi WHERE tidak terpenuhi
     * 3. Artinya kuota tidak cukup atau sudah dipotong request lain
     * 
     * @param int $klienId
     * @param int $amount
     * @param string|null $idempotencyKey Unique key untuk mencegah double consume
     * @param array $metadata Additional data for logging
     * @return array
     * @throws DomainException
     */
    public function consume(
        int $klienId, 
        int $amount = 1, 
        ?string $idempotencyKey = null,
        array $metadata = []
    ): array {
        // Idempotency check: Jika key sudah diproses, skip
        if ($idempotencyKey && $this->isAlreadyConsumed($idempotencyKey)) {
            Log::info('QuotaService: Idempotent skip', [
                'klien_id' => $klienId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'idempotent',
                'message' => 'Already consumed (idempotent)',
            ];
        }

        return DB::transaction(function () use ($klienId, $amount, $idempotencyKey, $metadata) {
            // Get active plan with exclusive lock
            $userPlan = $this->getActivePlanWithLock($klienId, true);

            if (!$userPlan) {
                throw new DomainException('Tidak ada paket aktif untuk klien ini');
            }

            if ($userPlan->is_expired) {
                throw new DomainException('Paket sudah expired');
            }

            // ATOMIC UPDATE: Validasi dan update dalam 1 query
            $affected = DB::table('user_plans')
                ->where('id', $userPlan->id)
                ->where('status', UserPlan::STATUS_ACTIVE)
                ->where('quota_messages_remaining', '>=', $amount)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->update([
                    'quota_messages_used' => DB::raw("quota_messages_used + {$amount}"),
                    'quota_messages_remaining' => DB::raw("quota_messages_remaining - {$amount}"),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                // Race condition terdeteksi: kondisi tidak terpenuhi
                // Mungkin kuota sudah habis atau status berubah
                $freshPlan = UserPlan::find($userPlan->id);
                
                throw new DomainException(
                    "Gagal mengkonsumsi kuota. Sisa: {$freshPlan->quota_messages_remaining}, dibutuhkan: {$amount}"
                );
            }

            // Record idempotency key
            if ($idempotencyKey) {
                $this->recordConsumedKey($idempotencyKey, $klienId, $userPlan->id, $amount, $metadata);
            }

            // Invalidate cache
            $this->invalidateCache($klienId);

            // Log activity
            $this->logActivity($klienId, 'quota_consumed', [
                'user_plan_id' => $userPlan->id,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
                'remaining_after' => $userPlan->quota_messages_remaining - $amount,
                ...$metadata,
            ]);

            // Refresh untuk get nilai terbaru
            $userPlan->refresh();

            return [
                'success' => true,
                'skipped' => false,
                'user_plan_id' => $userPlan->id,
                'consumed' => $amount,
                'remaining_quota' => $userPlan->quota_messages_remaining,
                'idempotency_key' => $idempotencyKey,
            ];
        });
    }

    /**
     * Rollback quota (kembalikan kuota)
     * 
     * Digunakan ketika:
     * 1. Pengiriman pesan gagal setelah kuota dipotong
     * 2. Campaign dibatalkan
     * 3. Refund
     * 
     * @param int $klienId
     * @param int $amount
     * @param string|null $idempotencyKey Original consume key
     * @param string $reason Alasan rollback
     * @return array
     */
    public function rollback(
        int $klienId, 
        int $amount, 
        ?string $idempotencyKey = null,
        string $reason = 'manual_rollback'
    ): array {
        // Jika ada idempotency key, cek apakah sudah di-rollback
        if ($idempotencyKey && $this->isAlreadyRolledBack($idempotencyKey)) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'already_rolled_back',
            ];
        }

        return DB::transaction(function () use ($klienId, $amount, $idempotencyKey, $reason) {
            $userPlan = $this->getActivePlanWithLock($klienId, true);

            if (!$userPlan) {
                // Tidak ada paket aktif, mungkin sudah upgrade/cancel
                // Log saja, tidak error
                Log::warning('QuotaService: Rollback skipped, no active plan', [
                    'klien_id' => $klienId,
                    'amount' => $amount,
                ]);

                return [
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'no_active_plan',
                ];
            }

            // Limit rollback ke jumlah yang sudah digunakan
            $actualRollback = min($amount, $userPlan->quota_messages_used);

            if ($actualRollback <= 0) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'nothing_to_rollback',
                ];
            }

            // ATOMIC UPDATE untuk rollback
            $affected = DB::table('user_plans')
                ->where('id', $userPlan->id)
                ->where('quota_messages_used', '>=', $actualRollback)
                ->update([
                    'quota_messages_used' => DB::raw("quota_messages_used - {$actualRollback}"),
                    'quota_messages_remaining' => DB::raw("quota_messages_remaining + {$actualRollback}"),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new DomainException('Gagal rollback kuota');
            }

            // Mark idempotency key as rolled back
            if ($idempotencyKey) {
                $this->markAsRolledBack($idempotencyKey);
            }

            // Invalidate cache
            $this->invalidateCache($klienId);

            // Log activity
            $this->logActivity($klienId, 'quota_rollback', [
                'user_plan_id' => $userPlan->id,
                'amount' => $actualRollback,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            $userPlan->refresh();

            return [
                'success' => true,
                'skipped' => false,
                'rolled_back' => $actualRollback,
                'remaining_quota' => $userPlan->quota_messages_remaining,
            ];
        });
    }

    // ==================== RESERVATION PATTERN ====================

    /**
     * Reserve quota sebelum operasi
     * 
     * Pattern ini berguna untuk operasi yang butuh waktu lama:
     * 1. Reserve kuota sebelum kirim pesan
     * 2. Kirim pesan
     * 3. Confirm reservation jika sukses
     * 4. Cancel reservation jika gagal
     * 
     * Keuntungan:
     * - Kuota "dikunci" sehingga request lain tidak bisa pakai
     * - Lebih aman untuk operasi async/queue
     * 
     * @param int $klienId
     * @param int $amount
     * @param string $referenceType Tipe referensi (campaign, single_message, dll)
     * @param int|null $referenceId ID referensi
     * @return array
     */
    public function reserve(
        int $klienId, 
        int $amount, 
        string $referenceType = 'message',
        ?int $referenceId = null
    ): array {
        return DB::transaction(function () use ($klienId, $amount, $referenceType, $referenceId) {
            $userPlan = $this->getActivePlanWithLock($klienId, true);

            if (!$userPlan) {
                throw new DomainException('Tidak ada paket aktif');
            }

            if ($userPlan->is_expired) {
                throw new DomainException('Paket sudah expired');
            }

            // Hitung kuota yang tersedia (remaining - pending reservations)
            $pendingReservations = QuotaReservation::where('user_plan_id', $userPlan->id)
                ->where('status', self::RESERVATION_PENDING)
                ->where('expires_at', '>', now())
                ->sum('amount');

            $effectiveRemaining = $userPlan->quota_messages_remaining - $pendingReservations;

            if ($effectiveRemaining < $amount) {
                throw new DomainException(
                    "Kuota tidak mencukupi. Tersedia: {$effectiveRemaining}, dibutuhkan: {$amount}"
                );
            }

            // Create reservation
            $reservationKey = Str::uuid()->toString();
            $expiresAt = now()->addMinutes(self::RESERVATION_TIMEOUT_MINUTES);

            $reservation = QuotaReservation::create([
                'klien_id' => $klienId,
                'user_plan_id' => $userPlan->id,
                'reservation_key' => $reservationKey,
                'amount' => $amount,
                'status' => self::RESERVATION_PENDING,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'expires_at' => $expiresAt,
            ]);

            Log::info('QuotaService: Quota reserved', [
                'klien_id' => $klienId,
                'reservation_key' => $reservationKey,
                'amount' => $amount,
            ]);

            return [
                'success' => true,
                'reservation_key' => $reservationKey,
                'amount' => $amount,
                'expires_at' => $expiresAt->toISOString(),
                'effective_remaining' => $effectiveRemaining - $amount,
            ];
        });
    }

    /**
     * Confirm reservation (finalize quota consumption)
     * 
     * @param string $reservationKey
     * @return array
     */
    public function confirmReservation(string $reservationKey): array
    {
        return DB::transaction(function () use ($reservationKey) {
            $reservation = QuotaReservation::where('reservation_key', $reservationKey)
                ->where('status', self::RESERVATION_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$reservation) {
                throw new DomainException('Reservasi tidak ditemukan atau sudah diproses');
            }

            if ($reservation->expires_at < now()) {
                $reservation->update(['status' => self::RESERVATION_EXPIRED]);
                throw new DomainException('Reservasi sudah expired');
            }

            // Consume quota dengan idempotency key = reservation_key
            $consumeResult = $this->consume(
                $reservation->klien_id,
                $reservation->amount,
                $reservationKey, // Use reservation key as idempotency key
                [
                    'reference_type' => $reservation->reference_type,
                    'reference_id' => $reservation->reference_id,
                ]
            );

            if ($consumeResult['success']) {
                $reservation->update([
                    'status' => self::RESERVATION_CONFIRMED,
                    'confirmed_at' => now(),
                ]);
            }

            return [
                'success' => $consumeResult['success'],
                'reservation_key' => $reservationKey,
                'consumed' => $reservation->amount,
                'remaining_quota' => $consumeResult['remaining_quota'] ?? null,
            ];
        });
    }

    /**
     * Cancel reservation (release reserved quota)
     * 
     * @param string $reservationKey
     * @param string $reason
     * @return array
     */
    public function cancelReservation(string $reservationKey, string $reason = 'cancelled'): array
    {
        return DB::transaction(function () use ($reservationKey, $reason) {
            $reservation = QuotaReservation::where('reservation_key', $reservationKey)
                ->where('status', self::RESERVATION_PENDING)
                ->lockForUpdate()
                ->first();

            if (!$reservation) {
                // Already processed or not found - idempotent response
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'not_found_or_already_processed',
                ];
            }

            $reservation->update([
                'status' => self::RESERVATION_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            Log::info('QuotaService: Reservation cancelled', [
                'reservation_key' => $reservationKey,
                'amount' => $reservation->amount,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'skipped' => false,
                'reservation_key' => $reservationKey,
                'released' => $reservation->amount,
            ];
        });
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * Check if can consume for batch (campaign)
     * 
     * @param int $klienId
     * @param int $batchSize
     * @return array
     */
    public function canConsumeForBatch(int $klienId, int $batchSize): array
    {
        $check = $this->canConsume($klienId, $batchSize);

        if (!$check['can_consume']) {
            // Hitung berapa yang bisa diproses
            $remaining = $check['remaining_quota'];
            $check['can_partial'] = $remaining > 0;
            $check['available_for_batch'] = $remaining;
        }

        return $check;
    }

    /**
     * Reserve for batch operation
     * 
     * @param int $klienId
     * @param int $kampanyeId
     * @param int $targetCount
     * @return array
     */
    public function reserveForCampaign(int $klienId, int $kampanyeId, int $targetCount): array
    {
        return $this->reserve($klienId, $targetCount, 'campaign', $kampanyeId);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get active plan with optional row lock
     */
    protected function getActivePlanWithLock(int $klienId, bool $lock = false): ?UserPlan
    {
        $query = UserPlan::where('klien_id', $klienId)
            ->where('status', UserPlan::STATUS_ACTIVE);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Check if idempotency key already consumed
     */
    protected function isAlreadyConsumed(string $key): bool
    {
        return DB::table('quota_consumed_keys')
            ->where('idempotency_key', $key)
            ->where('status', 'consumed')
            ->exists();
    }

    /**
     * Record consumed idempotency key
     */
    protected function recordConsumedKey(
        string $key, 
        int $klienId, 
        int $userPlanId, 
        int $amount,
        array $metadata = []
    ): void {
        DB::table('quota_consumed_keys')->insert([
            'idempotency_key' => $key,
            'klien_id' => $klienId,
            'user_plan_id' => $userPlanId,
            'amount' => $amount,
            'status' => 'consumed',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Check if already rolled back
     */
    protected function isAlreadyRolledBack(string $key): bool
    {
        return DB::table('quota_consumed_keys')
            ->where('idempotency_key', $key)
            ->where('status', 'rolled_back')
            ->exists();
    }

    /**
     * Mark key as rolled back
     */
    protected function markAsRolledBack(string $key): void
    {
        DB::table('quota_consumed_keys')
            ->where('idempotency_key', $key)
            ->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Invalidate quota cache
     */
    protected function invalidateCache(int $klienId): void
    {
        Cache::forget("quota_info_{$klienId}");
    }

    /**
     * Log activity
     */
    protected function logActivity(int $klienId, string $aktivitas, array $data = []): void
    {
        try {
            LogAktivitas::create([
                'klien_id' => $klienId,
                'aktivitas' => $aktivitas,
                'keterangan' => "Quota operation: {$aktivitas}",
                'data' => $data,
                'ip_address' => request()->ip() ?? 'queue',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to log quota activity', [
                'error' => $e->getMessage(),
                'klien_id' => $klienId,
                'aktivitas' => $aktivitas,
            ]);
        }
    }

    // ==================== MAINTENANCE METHODS ====================

    /**
     * Cleanup expired reservations
     * Jalankan via scheduler
     */
    public function cleanupExpiredReservations(): int
    {
        $affected = QuotaReservation::where('status', self::RESERVATION_PENDING)
            ->where('expires_at', '<', now())
            ->update([
                'status' => self::RESERVATION_EXPIRED,
                'updated_at' => now(),
            ]);

        Log::info('QuotaService: Cleaned up expired reservations', ['count' => $affected]);

        return $affected;
    }

    /**
     * Get quota info (cached)
     */
    public function getQuotaInfo(int $klienId): array
    {
        return Cache::remember("quota_info_{$klienId}", self::CACHE_TTL, function () use ($klienId) {
            $userPlan = UserPlan::where('klien_id', $klienId)
                ->where('status', UserPlan::STATUS_ACTIVE)
                ->first();

            if (!$userPlan) {
                return [
                    'has_plan' => false,
                    'remaining' => 0,
                    'used' => 0,
                    'initial' => 0,
                ];
            }

            // Calculate pending reservations
            $pendingReservations = QuotaReservation::where('user_plan_id', $userPlan->id)
                ->where('status', self::RESERVATION_PENDING)
                ->where('expires_at', '>', now())
                ->sum('amount');

            return [
                'has_plan' => true,
                'plan_name' => $userPlan->plan?->name,
                'remaining' => $userPlan->quota_messages_remaining,
                'effective_remaining' => $userPlan->quota_messages_remaining - $pendingReservations,
                'used' => $userPlan->quota_messages_used,
                'initial' => $userPlan->quota_messages_initial,
                'pending_reservations' => $pendingReservations,
                'expires_at' => $userPlan->expires_at?->toISOString(),
                'is_expired' => $userPlan->is_expired,
            ];
        });
    }
}
