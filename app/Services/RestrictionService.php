<?php

namespace App\Services;

use App\Models\UserRestriction;
use App\Models\SuspensionHistory;
use App\Models\AbuseEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RestrictionService - User Restriction State Machine Manager
 * 
 * STATE MACHINE:
 * active → warned → throttled → paused → suspended
 *                        ↘ restored
 * 
 * RULES:
 * - Status tidak lompat ekstrem tanpa reason
 * - Semua transisi tercatat
 * - Bisa rollback oleh admin
 * 
 * @author Trust & Safety Lead
 */
class RestrictionService
{
    protected AbuseDetectionService $detectionService;

    public function __construct(AbuseDetectionService $detectionService)
    {
        $this->detectionService = $detectionService;
    }

    // ==================== STATUS MANAGEMENT ====================

    /**
     * Get current status for user
     */
    public function getStatus(int $klienId): array
    {
        $restriction = UserRestriction::where('klien_id', $klienId)->first();

        if (!$restriction) {
            return [
                'status' => UserRestriction::STATUS_ACTIVE,
                'can_send' => true,
                'can_create_campaign' => true,
                'throttle_multiplier' => 1.0,
                'reason' => null,
            ];
        }

        return [
            'status' => $restriction->status,
            'can_send' => $restriction->canSendMessages(),
            'can_create_campaign' => $restriction->can_create_campaign,
            'throttle_multiplier' => $restriction->getEffectiveThrottle(),
            'reason' => $restriction->status_reason,
            'expires_at' => $restriction->restriction_expires_at,
            'admin_override' => $restriction->admin_override,
        ];
    }

    // ==================== ADMIN ACTIONS ====================

    /**
     * Admin: Manually warn user
     */
    public function warnUser(int $klienId, string $reason, int $adminId): array
    {
        return $this->applyAdminAction($klienId, 'warn', $reason, $adminId);
    }

    /**
     * Admin: Manually throttle user
     */
    public function throttleUser(int $klienId, string $reason, int $adminId, int $hours = 24): array
    {
        return $this->applyAdminAction($klienId, 'throttle', $reason, $adminId, $hours);
    }

    /**
     * Admin: Manually pause user
     */
    public function pauseUser(int $klienId, string $reason, int $adminId, int $hours = 72): array
    {
        return $this->applyAdminAction($klienId, 'pause', $reason, $adminId, $hours);
    }

    /**
     * Admin: Manually suspend user
     */
    public function suspendUser(int $klienId, string $reason, int $adminId, int $hours = 168): array
    {
        return $this->applyAdminAction($klienId, 'suspend', $reason, $adminId, $hours);
    }

    /**
     * Admin: Lift restriction (restore)
     */
    public function liftRestriction(int $klienId, string $reason, int $adminId): array
    {
        return DB::transaction(function () use ($klienId, $reason, $adminId) {
            $restriction = UserRestriction::getOrCreate($klienId);
            $statusBefore = $restriction->status;

            if ($restriction->status === UserRestriction::STATUS_ACTIVE) {
                return [
                    'success' => false,
                    'message' => 'User is already active',
                ];
            }

            // Force transition to restored
            $restriction->transitionTo(
                UserRestriction::STATUS_RESTORED,
                "Admin lifted: {$reason}",
                true  // Force
            );

            $restriction->update([
                'restriction_expires_at' => null,
            ]);

            // Resolve pending suspensions
            SuspensionHistory::where('klien_id', $klienId)
                ->pending()
                ->get()
                ->each(fn($h) => $h->resolve(
                    SuspensionHistory::RESOLUTION_ADMIN_LIFTED,
                    $adminId,
                    $reason
                ));

            Log::info('Admin lifted restriction', [
                'klien_id' => $klienId,
                'admin_id' => $adminId,
                'from' => $statusBefore,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => 'Restriction lifted',
                'status_before' => $statusBefore,
                'status_after' => $restriction->status,
            ];
        });
    }

    /**
     * Admin: Set whitelist (bypass restrictions)
     */
    public function whitelist(int $klienId, string $reason, int $adminId, ?int $hours = null): array
    {
        $restriction = UserRestriction::getOrCreate($klienId);
        $restriction->setOverride('whitelist', $adminId, $reason, $hours);

        Log::info('User whitelisted', [
            'klien_id' => $klienId,
            'admin_id' => $adminId,
            'hours' => $hours,
        ]);

        return [
            'success' => true,
            'message' => 'User whitelisted',
            'expires_at' => $restriction->override_expires_at,
        ];
    }

    /**
     * Admin: Set blacklist (permanent suspend)
     */
    public function blacklist(int $klienId, string $reason, int $adminId): array
    {
        return DB::transaction(function () use ($klienId, $reason, $adminId) {
            $restriction = UserRestriction::getOrCreate($klienId);
            $statusBefore = $restriction->status;

            // Force suspend
            $restriction->transitionTo(
                UserRestriction::STATUS_SUSPENDED,
                "Admin blacklisted: {$reason}",
                true
            );

            $restriction->setOverride('blacklist', $adminId, $reason, null);  // No expiry

            // Create suspension record
            SuspensionHistory::createRecord(
                $klienId,
                'suspend',
                'critical',
                $statusBefore,
                UserRestriction::STATUS_SUSPENDED,
                ['admin_blacklist' => true],
                "Admin blacklist: {$reason}",
                null,  // No expiry
                null,
                false  // Manual
            );

            Log::warning('User blacklisted', [
                'klien_id' => $klienId,
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => 'User blacklisted',
            ];
        });
    }

    /**
     * Admin: Clear override
     */
    public function clearOverride(int $klienId, int $adminId): array
    {
        $restriction = UserRestriction::where('klien_id', $klienId)->first();
        
        if (!$restriction || !$restriction->admin_override) {
            return [
                'success' => false,
                'message' => 'No override to clear',
            ];
        }

        $restriction->clearOverride();

        Log::info('Admin override cleared', [
            'klien_id' => $klienId,
            'admin_id' => $adminId,
        ]);

        return [
            'success' => true,
            'message' => 'Override cleared',
        ];
    }

    // ==================== INTERNAL HELPERS ====================

    /**
     * Apply admin action
     */
    protected function applyAdminAction(
        int $klienId,
        string $action,
        string $reason,
        int $adminId,
        ?int $hours = null
    ): array {
        return DB::transaction(function () use ($klienId, $action, $reason, $adminId, $hours) {
            $restriction = UserRestriction::getOrCreate($klienId);
            $statusBefore = $restriction->status;

            $newStatus = match ($action) {
                'warn' => UserRestriction::STATUS_WARNED,
                'throttle' => UserRestriction::STATUS_THROTTLED,
                'pause' => UserRestriction::STATUS_PAUSED,
                'suspend' => UserRestriction::STATUS_SUSPENDED,
                default => null,
            };

            if (!$newStatus) {
                return ['success' => false, 'message' => 'Invalid action'];
            }

            // Admin can force transitions
            $restriction->transitionTo($newStatus, "Admin action: {$reason}", true);

            if ($hours) {
                $restriction->update(['restriction_expires_at' => now()->addHours($hours)]);
            }

            // Record in history
            SuspensionHistory::createRecord(
                $klienId,
                $action,
                $this->actionToSeverity($action),
                $statusBefore,
                $newStatus,
                ['admin_action' => true, 'admin_id' => $adminId],
                "Admin {$action}: {$reason}",
                $hours,
                null,
                false  // Manual
            );

            Log::info('Admin action applied', [
                'klien_id' => $klienId,
                'action' => $action,
                'admin_id' => $adminId,
                'hours' => $hours,
            ]);

            return [
                'success' => true,
                'message' => ucfirst($action) . ' applied',
                'status_before' => $statusBefore,
                'status_after' => $newStatus,
                'expires_at' => $restriction->restriction_expires_at,
            ];
        });
    }

    protected function actionToSeverity(string $action): string
    {
        return match ($action) {
            'warn' => 'low',
            'throttle' => 'medium',
            'pause' => 'high',
            'suspend' => 'critical',
            default => 'low',
        };
    }

    // ==================== HISTORY ====================

    /**
     * Get suspension history for user
     */
    public function getHistory(int $klienId, int $limit = 20): array
    {
        return SuspensionHistory::where('klien_id', $klienId)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn($h) => [
                'id' => $h->id,
                'uuid' => $h->suspension_uuid,
                'action' => $h->action_type,
                'severity' => $h->severity,
                'status_before' => $h->status_before,
                'status_after' => $h->status_after,
                'reason' => $h->reason,
                'started_at' => $h->started_at,
                'expires_at' => $h->expires_at,
                'ended_at' => $h->ended_at,
                'resolution' => $h->resolution,
                'is_auto' => $h->is_auto,
            ])
            ->toArray();
    }

    /**
     * Get abuse events for user
     */
    public function getAbuseEvents(int $klienId, int $days = 30): array
    {
        return AbuseEvent::where('klien_id', $klienId)
            ->recent($days)
            ->orderByDesc('detected_at')
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'uuid' => $e->event_uuid,
                'rule' => $e->rule_code,
                'severity' => $e->severity,
                'points' => $e->abuse_points,
                'description' => $e->description,
                'action_taken' => $e->action_taken,
                'detected_at' => $e->detected_at,
                'reviewed' => $e->admin_reviewed,
            ])
            ->toArray();
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * Process expired restrictions
     */
    public function processExpiredRestrictions(): int
    {
        return $this->detectionService->processRecovery();
    }

    /**
     * Apply daily decay to all users
     */
    public function applyDailyDecay(): int
    {
        return $this->detectionService->applyDailyDecay();
    }
}
