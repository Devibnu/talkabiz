<?php

namespace App\Services;

use App\Models\WhatsappConnection;
use App\Models\WhatsappWarmup;
use App\Models\WhatsappWarmupLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * WarmupService - WhatsApp Number Warm-up Manager
 * 
 * Mengelola proses warmup nomor WhatsApp baru untuk menghindari BAN.
 * 
 * FLOW:
 * =====
 * 1. Enable warmup saat nomor baru terhubung
 * 2. Batasi pengiriman sesuai daily_limit
 * 3. Track delivery rate & fail rate
 * 4. Auto-pause jika safety threshold terlampaui
 * 5. Auto-progress ke hari berikutnya
 * 6. Complete warmup setelah semua hari selesai
 * 
 * INTEGRATION:
 * ============
 * - WaBlastService memanggil canSend() sebelum kirim
 * - WaBlastService memanggil recordSend() setelah kirim
 * - Daily cron memanggil processDailyReset()
 */
class WarmupService
{
    // Log channel
    const LOG_CHANNEL = 'wa-blast';

    // ==================== WARMUP LIFECYCLE ====================

    /**
     * Enable warmup for a connection
     * 
     * @param WhatsappConnection $connection
     * @param string $strategy default|aggressive|conservative
     * @return WhatsappWarmup
     */
    public function enableWarmup(
        WhatsappConnection $connection,
        string $strategy = 'default'
    ): WhatsappWarmup {
        // Check if warmup already exists
        $existing = WhatsappWarmup::where('connection_id', $connection->id)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->first();

        if ($existing) {
            // Resume if paused
            if ($existing->status === WhatsappWarmup::STATUS_PAUSED) {
                return $this->resumeWarmup($existing);
            }
            return $existing;
        }

        DB::beginTransaction();
        try {
            // Create warmup record
            $warmup = WhatsappWarmup::createForConnection($connection, $strategy);

            // Update connection flags
            $connection->update([
                'warmup_enabled' => true,
                'warmup_active' => true,
                'warmup_daily_limit' => $warmup->daily_limit,
                'warmup_sent_today' => 0,
                'warmup_current_date' => Carbon::today(),
            ]);

            // Log event
            WhatsappWarmupLog::log(
                $warmup,
                WhatsappWarmupLog::EVENT_STARTED,
                null,
                "Warmup started with {$strategy} strategy"
            );

            Log::channel(self::LOG_CHANNEL)->info('Warmup enabled', [
                'connection_id' => $connection->id,
                'strategy' => $strategy,
                'daily_limits' => $warmup->daily_limits,
            ]);

            DB::commit();
            return $warmup;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Disable warmup for a connection
     */
    public function disableWarmup(
        WhatsappConnection $connection,
        ?int $actorId = null,
        string $reason = 'Manually disabled'
    ): bool {
        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->first();

        if (!$warmup) {
            return false;
        }

        DB::beginTransaction();
        try {
            $warmup->update([
                'enabled' => false,
                'status' => WhatsappWarmup::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $connection->update([
                'warmup_enabled' => false,
                'warmup_active' => false,
                'warmup_daily_limit' => 0,
            ]);

            WhatsappWarmupLog::log(
                $warmup,
                WhatsappWarmupLog::EVENT_COMPLETED,
                $actorId,
                $reason
            );

            Log::channel(self::LOG_CHANNEL)->info('Warmup disabled', [
                'connection_id' => $connection->id,
                'reason' => $reason,
            ]);

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ==================== PAUSE/RESUME ====================

    /**
     * Pause warmup (manual or auto)
     */
    public function pauseWarmup(
        WhatsappWarmup $warmup,
        string $reason,
        ?int $actorId = null,
        bool $isAuto = false
    ): WhatsappWarmup {
        if ($warmup->status !== WhatsappWarmup::STATUS_ACTIVE) {
            return $warmup;
        }

        DB::beginTransaction();
        try {
            $warmup->update([
                'status' => WhatsappWarmup::STATUS_PAUSED,
                'pause_reason' => $reason,
                'paused_at' => now(),
                'paused_by' => $actorId,
            ]);

            $warmup->connection->update([
                'warmup_active' => false,
            ]);

            WhatsappWarmupLog::log(
                $warmup,
                $isAuto ? WhatsappWarmupLog::EVENT_PAUSED_AUTO : WhatsappWarmupLog::EVENT_PAUSED_MANUAL,
                $actorId,
                $reason
            );

            Log::channel(self::LOG_CHANNEL)->warning('Warmup paused', [
                'connection_id' => $warmup->connection_id,
                'reason' => $reason,
                'is_auto' => $isAuto,
            ]);

            DB::commit();
            return $warmup->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Resume warmup
     */
    public function resumeWarmup(
        WhatsappWarmup $warmup,
        ?int $actorId = null
    ): WhatsappWarmup {
        if ($warmup->status !== WhatsappWarmup::STATUS_PAUSED) {
            return $warmup;
        }

        DB::beginTransaction();
        try {
            // Reset daily stats for fresh start
            $warmup->update([
                'status' => WhatsappWarmup::STATUS_ACTIVE,
                'pause_reason' => null,
                'paused_at' => null,
                'paused_by' => null,
                'sent_today' => 0,
                'delivered_today' => 0,
                'failed_today' => 0,
                'current_date' => Carbon::today(),
            ]);

            $warmup->connection->update([
                'warmup_active' => true,
                'warmup_sent_today' => 0,
                'warmup_current_date' => Carbon::today(),
            ]);

            WhatsappWarmupLog::log(
                $warmup,
                WhatsappWarmupLog::EVENT_RESUMED,
                $actorId,
                'Warmup resumed'
            );

            Log::channel(self::LOG_CHANNEL)->info('Warmup resumed', [
                'connection_id' => $warmup->connection_id,
                'current_day' => $warmup->current_day,
            ]);

            DB::commit();
            return $warmup->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ==================== PRE-SEND VALIDATION ====================

    /**
     * Check if can send message (for WA Blast integration)
     * 
     * Returns:
     * - can_send: bool
     * - remaining: int (remaining quota for today)
     * - reason: string (if cannot send)
     * - warmup: WhatsappWarmup|null
     */
    public function canSend(WhatsappConnection $connection, int $count = 1): array
    {
        // Check if warmup is enabled
        if (!$connection->warmup_enabled) {
            return [
                'can_send' => true,
                'remaining' => PHP_INT_MAX,
                'reason' => null,
                'warmup' => null,
                'warmup_active' => false,
            ];
        }

        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->where('status', WhatsappWarmup::STATUS_ACTIVE)
            ->first();

        if (!$warmup) {
            // Warmup enabled but no active warmup - use connection flags
            if (!$connection->warmup_active) {
                return [
                    'can_send' => false,
                    'remaining' => 0,
                    'reason' => 'Warmup is paused',
                    'warmup' => null,
                    'warmup_active' => true,
                ];
            }

            // Use denormalized values from connection
            $remaining = max(0, $connection->warmup_daily_limit - $connection->warmup_sent_today);
            
            return [
                'can_send' => $remaining >= $count,
                'remaining' => $remaining,
                'reason' => $remaining < $count ? 'Daily warmup limit reached' : null,
                'warmup' => null,
                'warmup_active' => true,
            ];
        }

        // Check daily reset
        if ($warmup->needsDailyReset()) {
            $this->processDailyResetForWarmup($warmup);
            $warmup->refresh();
        }

        $remaining = $warmup->remaining_today;

        if ($remaining < $count) {
            // Log limit reached
            if ($remaining === 0 && $warmup->sent_today > 0) {
                WhatsappWarmupLog::log(
                    $warmup,
                    WhatsappWarmupLog::EVENT_LIMIT_REACHED
                );
            }

            return [
                'can_send' => false,
                'remaining' => $remaining,
                'reason' => 'Daily warmup limit reached. Limit: ' . $warmup->daily_limit,
                'warmup' => $warmup,
                'warmup_active' => true,
            ];
        }

        return [
            'can_send' => true,
            'remaining' => $remaining,
            'reason' => null,
            'warmup' => $warmup,
            'warmup_active' => true,
        ];
    }

    /**
     * Get warmup status for a connection
     */
    public function getWarmupStatus(WhatsappConnection $connection): array
    {
        if (!$connection->warmup_enabled) {
            return [
                'enabled' => false,
                'active' => false,
                'warmup' => null,
            ];
        }

        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->first();

        if (!$warmup) {
            return [
                'enabled' => true,
                'active' => false,
                'warmup' => null,
            ];
        }

        return [
            'enabled' => true,
            'active' => $warmup->status === WhatsappWarmup::STATUS_ACTIVE,
            'warmup' => [
                'id' => $warmup->id,
                'status' => $warmup->status,
                'status_label' => $warmup->status_label,
                'current_day' => $warmup->current_day,
                'total_days' => $warmup->total_days,
                'daily_limit' => $warmup->daily_limit,
                'sent_today' => $warmup->sent_today,
                'remaining_today' => $warmup->remaining_today,
                'delivery_rate_today' => $warmup->delivery_rate_today,
                'progress_percent' => $warmup->progress_percent,
                'started_at' => $warmup->started_at?->toDateTimeString(),
                'pause_reason' => $warmup->pause_reason,
            ],
        ];
    }

    // ==================== SEND RECORDING ====================

    /**
     * Record a sent message (call after successful send)
     */
    public function recordSend(
        WhatsappConnection $connection,
        int $count = 1,
        int $deliveredCount = 0,
        int $failedCount = 0
    ): void {
        if (!$connection->warmup_enabled) {
            return;
        }

        DB::beginTransaction();
        try {
            // Update connection denormalized fields
            $connection->increment('warmup_sent_today', $count);

            // Update warmup record
            $warmup = WhatsappWarmup::where('connection_id', $connection->id)
                ->where('status', WhatsappWarmup::STATUS_ACTIVE)
                ->first();

            if ($warmup) {
                $warmup->increment('sent_today', $count);
                $warmup->increment('total_sent', $count);

                if ($deliveredCount > 0) {
                    $warmup->increment('delivered_today', $deliveredCount);
                    $warmup->increment('total_delivered', $deliveredCount);
                }

                if ($failedCount > 0) {
                    $warmup->increment('failed_today', $failedCount);
                    $warmup->increment('total_failed', $failedCount);
                }

                // Check safety rules
                $warmup->refresh();
                $safetyCheck = $warmup->shouldAutoPause();

                if ($safetyCheck['should_pause']) {
                    $reasons = collect($safetyCheck['reasons'])->pluck('message')->join('; ');
                    $this->pauseWarmup($warmup, $reasons, null, true);
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::channel(self::LOG_CHANNEL)->error('Failed to record warmup send', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record delivery status update (from webhook)
     */
    public function recordDeliveryStatus(
        WhatsappConnection $connection,
        string $status // delivered, read, failed
    ): void {
        if (!$connection->warmup_enabled) {
            return;
        }

        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->where('status', WhatsappWarmup::STATUS_ACTIVE)
            ->first();

        if (!$warmup) {
            return;
        }

        try {
            if (in_array($status, ['delivered', 'read'])) {
                $warmup->increment('delivered_today');
                $warmup->increment('total_delivered');
            } elseif ($status === 'failed') {
                $warmup->increment('failed_today');
                $warmup->increment('total_failed');

                // Check safety after fail
                $warmup->refresh();
                $safetyCheck = $warmup->shouldAutoPause();

                if ($safetyCheck['should_pause']) {
                    $reasons = collect($safetyCheck['reasons'])->pluck('message')->join('; ');
                    $this->pauseWarmup($warmup, $reasons, null, true);
                }
            }
        } catch (Exception $e) {
            Log::channel(self::LOG_CHANNEL)->error('Failed to record delivery status', [
                'connection_id' => $connection->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== DAILY PROCESSING ====================

    /**
     * Process daily reset for all warmups (call from scheduler)
     */
    public function processDailyReset(): array
    {
        $results = [
            'processed' => 0,
            'progressed' => 0,
            'completed' => 0,
            'resumed' => 0,
            'errors' => [],
        ];

        $warmups = WhatsappWarmup::where('enabled', true)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->get();

        foreach ($warmups as $warmup) {
            try {
                $result = $this->processDailyResetForWarmup($warmup);
                $results['processed']++;

                if ($result['progressed']) {
                    $results['progressed']++;
                }
                if ($result['completed']) {
                    $results['completed']++;
                }
                if ($result['resumed']) {
                    $results['resumed']++;
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'warmup_id' => $warmup->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::channel(self::LOG_CHANNEL)->info('Daily warmup reset completed', $results);

        return $results;
    }

    /**
     * Process daily reset for single warmup
     */
    private function processDailyResetForWarmup(WhatsappWarmup $warmup): array
    {
        $result = [
            'progressed' => false,
            'completed' => false,
            'resumed' => false,
        ];

        // Check if same day
        if ($warmup->current_date && $warmup->current_date->isToday()) {
            return $result;
        }

        DB::beginTransaction();
        try {
            // Log end of day stats
            WhatsappWarmupLog::log(
                $warmup,
                WhatsappWarmupLog::EVENT_DAY_COMPLETED
            );

            // Check if should progress to next day
            $shouldProgress = $warmup->shouldProgressDay();

            if ($shouldProgress) {
                $newDay = $warmup->current_day + 1;
                $totalDays = $warmup->total_days;

                // Check if warmup complete
                if ($newDay > $totalDays) {
                    $this->completeWarmup($warmup);
                    $result['completed'] = true;
                } else {
                    // Progress to next day
                    $warmup->update([
                        'current_day' => $newDay,
                        'sent_today' => 0,
                        'delivered_today' => 0,
                        'failed_today' => 0,
                        'current_date' => Carbon::today(),
                    ]);

                    // Update connection
                    $warmup->connection->update([
                        'warmup_daily_limit' => $warmup->fresh()->daily_limit,
                        'warmup_sent_today' => 0,
                        'warmup_current_date' => Carbon::today(),
                    ]);

                    WhatsappWarmupLog::log(
                        $warmup->fresh(),
                        WhatsappWarmupLog::EVENT_DAY_PROGRESSED,
                        null,
                        "Progressed to day {$newDay}"
                    );

                    $result['progressed'] = true;
                }
            } else {
                // Stay on same day, just reset counters
                $warmup->update([
                    'sent_today' => 0,
                    'delivered_today' => 0,
                    'failed_today' => 0,
                    'current_date' => Carbon::today(),
                ]);

                $warmup->connection->update([
                    'warmup_sent_today' => 0,
                    'warmup_current_date' => Carbon::today(),
                ]);
            }

            // Check if paused warmup can auto-resume
            if ($warmup->status === WhatsappWarmup::STATUS_PAUSED && $warmup->canAutoResume()) {
                $this->resumeWarmup($warmup);
                $result['resumed'] = true;
            }

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Complete warmup
     */
    private function completeWarmup(WhatsappWarmup $warmup): void
    {
        $warmup->update([
            'status' => WhatsappWarmup::STATUS_COMPLETED,
            'enabled' => false,
            'completed_at' => now(),
        ]);

        $warmup->connection->update([
            'warmup_enabled' => false,
            'warmup_active' => false,
            'warmup_daily_limit' => 0,
        ]);

        WhatsappWarmupLog::log(
            $warmup,
            WhatsappWarmupLog::EVENT_COMPLETED,
            null,
            'Warmup completed successfully'
        );

        Log::channel(self::LOG_CHANNEL)->info('Warmup completed', [
            'connection_id' => $warmup->connection_id,
            'total_days' => $warmup->total_days,
            'total_sent' => $warmup->total_sent,
            'overall_delivery_rate' => $warmup->overall_delivery_rate,
        ]);
    }

    // ==================== OWNER ACTIONS ====================

    /**
     * Force stop all warmups for a connection (owner action)
     */
    public function forceStopWarmup(
        WhatsappConnection $connection,
        int $actorId,
        string $reason = 'Force stopped by owner'
    ): bool {
        $warmup = WhatsappWarmup::where('connection_id', $connection->id)
            ->whereIn('status', [WhatsappWarmup::STATUS_ACTIVE, WhatsappWarmup::STATUS_PAUSED])
            ->first();

        if (!$warmup) {
            return false;
        }

        DB::beginTransaction();
        try {
            $warmup->update([
                'status' => WhatsappWarmup::STATUS_FAILED,
                'enabled' => false,
                'pause_reason' => $reason,
                'paused_at' => now(),
                'paused_by' => $actorId,
            ]);

            $connection->update([
                'warmup_enabled' => false,
                'warmup_active' => false,
            ]);

            WhatsappWarmupLog::log(
                $warmup,
                WhatsappWarmupLog::EVENT_FAILED,
                $actorId,
                $reason
            );

            Log::channel(self::LOG_CHANNEL)->warning('Warmup force stopped', [
                'connection_id' => $connection->id,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get warmup history for a connection
     */
    public function getWarmupHistory(WhatsappConnection $connection): array
    {
        $warmups = WhatsappWarmup::where('connection_id', $connection->id)
            ->orderByDesc('created_at')
            ->get();

        return $warmups->map(function ($warmup) {
            return [
                'id' => $warmup->id,
                'status' => $warmup->status,
                'status_label' => $warmup->status_label,
                'current_day' => $warmup->current_day,
                'total_days' => $warmup->total_days,
                'total_sent' => $warmup->total_sent,
                'total_delivered' => $warmup->total_delivered,
                'overall_delivery_rate' => $warmup->overall_delivery_rate,
                'started_at' => $warmup->started_at?->toDateTimeString(),
                'completed_at' => $warmup->completed_at?->toDateTimeString(),
            ];
        })->toArray();
    }

    /**
     * Get warmup logs
     */
    public function getWarmupLogs(WhatsappWarmup $warmup, int $limit = 50): array
    {
        $logs = WhatsappWarmupLog::where('warmup_id', $warmup->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $logs->map(function ($log) {
            return [
                'event' => $log->event,
                'event_label' => $log->event_label,
                'event_color' => $log->event_color,
                'day_number' => $log->day_number,
                'daily_limit' => $log->daily_limit,
                'sent_count' => $log->sent_count,
                'delivery_rate' => $log->delivery_rate,
                'reason' => $log->reason,
                'created_at' => $log->created_at->toDateTimeString(),
            ];
        })->toArray();
    }
}
