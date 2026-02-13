<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanAuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * PlanAuditService
 * 
 * Service untuk mencatat semua perubahan pada Plan.
 * Setiap operasi mencatat:
 * - before/after JSON (old_values, new_values)
 * - actor (user_id yang melakukan perubahan)
 * - timestamp
 * - IP address & user agent
 * 
 * Actions yang dicatat:
 * - created: Paket baru dibuat
 * - updated: Paket diupdate (harga, limit, fitur)
 * - activated: Paket diaktifkan
 * - deactivated: Paket dinonaktifkan
 * - marked_popular: Paket ditandai sebagai populer
 * - unmarked_popular: Paket dihapus dari populer
 * 
 * @see SA Document: Modul Paket / Subscription Plan - Section 7
 */
class PlanAuditService
{
    // ==================== LOGGING METHODS ====================

    /**
     * Log plan creation
     * 
     * @param Plan $plan The created plan
     * @param int|null $actorId User ID yang melakukan aksi
     * @return PlanAuditLog
     */
    public function logCreated(Plan $plan, ?int $actorId = null): PlanAuditLog
    {
        return $this->log(
            $plan,
            PlanAuditLog::ACTION_CREATED,
            null, // No old values for creation
            $this->extractRelevantFields($plan),
            $actorId
        );
    }

    /**
     * Log plan update
     * 
     * @param Plan $plan The updated plan (after update)
     * @param array $oldValues Values before update
     * @param int|null $actorId User ID yang melakukan aksi
     * @return PlanAuditLog
     */
    public function logUpdated(Plan $plan, array $oldValues, ?int $actorId = null): PlanAuditLog
    {
        // Only log the fields that actually changed
        $newValues = $this->extractRelevantFields($plan);
        $changedOldValues = $this->getChangedFields($oldValues, $newValues);
        $changedNewValues = $this->getChangedFields($newValues, $oldValues, true);

        // Skip if no actual changes
        if (empty($changedOldValues) && empty($changedNewValues)) {
            return new PlanAuditLog(); // Return empty (not persisted)
        }

        return $this->log(
            $plan,
            PlanAuditLog::ACTION_UPDATED,
            $changedOldValues,
            $changedNewValues,
            $actorId
        );
    }

    /**
     * Log plan activation
     * 
     * @param Plan $plan
     * @param int|null $actorId
     * @return PlanAuditLog
     */
    public function logActivated(Plan $plan, ?int $actorId = null): PlanAuditLog
    {
        return $this->log(
            $plan,
            PlanAuditLog::ACTION_ACTIVATED,
            ['is_active' => false],
            ['is_active' => true],
            $actorId
        );
    }

    /**
     * Log plan deactivation
     * 
     * @param Plan $plan
     * @param int|null $actorId
     * @return PlanAuditLog
     */
    public function logDeactivated(Plan $plan, ?int $actorId = null): PlanAuditLog
    {
        return $this->log(
            $plan,
            PlanAuditLog::ACTION_DEACTIVATED,
            ['is_active' => true],
            ['is_active' => false],
            $actorId
        );
    }

    /**
     * Log plan marked as popular
     * 
     * @param Plan $plan
     * @param int|null $actorId
     * @return PlanAuditLog
     */
    public function logMarkedPopular(Plan $plan, ?int $actorId = null): PlanAuditLog
    {
        return $this->log(
            $plan,
            PlanAuditLog::ACTION_MARKED_POPULAR,
            ['is_popular' => false],
            ['is_popular' => true],
            $actorId
        );
    }

    /**
     * Log plan unmarked from popular
     * 
     * @param Plan $plan
     * @param int|null $actorId
     * @return PlanAuditLog
     */
    public function logUnmarkedPopular(Plan $plan, ?int $actorId = null): PlanAuditLog
    {
        return $this->log(
            $plan,
            PlanAuditLog::ACTION_UNMARKED_POPULAR,
            ['is_popular' => true],
            ['is_popular' => false],
            $actorId
        );
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get audit logs for a specific plan
     * 
     * @param Plan|int $plan Plan model or ID
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLogsForPlan($plan, int $limit = 50)
    {
        $planId = $plan instanceof Plan ? $plan->id : $plan;

        return PlanAuditLog::where('plan_id', $planId)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs by actor (user)
     * 
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLogsByActor(int $userId, int $limit = 50)
    {
        return PlanAuditLog::where('user_id', $userId)
            ->with('plan:id,code,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent audit logs
     * 
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRecentLogs(int $limit = 50)
    {
        return PlanAuditLog::with(['plan:id,code,name', 'user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs by action type
     * 
     * @param string $action
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLogsByAction(string $action, int $limit = 50)
    {
        return PlanAuditLog::where('action', $action)
            ->with(['plan:id,code,name', 'user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs within date range
     * 
     * @param string|\DateTimeInterface $startDate
     * @param string|\DateTimeInterface $endDate
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLogsByDateRange($startDate, $endDate, int $limit = 100)
    {
        return PlanAuditLog::whereBetween('created_at', [$startDate, $endDate])
            ->with(['plan:id,code,name', 'user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    // ==================== CORE LOGGING METHOD ====================

    /**
     * Core method to create audit log entry
     * 
     * @param Plan $plan
     * @param string $action
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $actorId
     * @return PlanAuditLog
     */
    protected function log(
        Plan $plan,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?int $actorId = null
    ): PlanAuditLog {
        return PlanAuditLog::create([
            'plan_id' => $plan->id,
            'user_id' => $actorId ?? $this->getCurrentUserId(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'created_at' => now(),
        ]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Extract relevant fields from plan for audit
     * Excludes timestamps and computed fields
     * 
     * @param Plan $plan
     * @return array
     */
    protected function extractRelevantFields(Plan $plan): array
    {
        $data = $plan->toArray();

        // Remove fields that shouldn't be in audit log
        unset(
            $data['created_at'],
            $data['updated_at'],
            $data['deleted_at']
        );

        return $data;
    }

    /**
     * Get only the fields that changed between old and new values
     * 
     * @param array $source The source array to filter
     * @param array $compare The array to compare against
     * @param bool $inverse If true, returns fields in source that differ from compare
     * @return array
     */
    protected function getChangedFields(array $source, array $compare, bool $inverse = false): array
    {
        $changed = [];

        foreach ($source as $key => $value) {
            // Skip timestamp fields
            if (in_array($key, ['created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $compareValue = $compare[$key] ?? null;

            // Handle array comparison (e.g., features)
            if (is_array($value) && is_array($compareValue)) {
                if ($value != $compareValue) {
                    $changed[$key] = $value;
                }
                continue;
            }

            // Handle scalar comparison
            if ($value !== $compareValue) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    /**
     * Get current authenticated user ID
     * 
     * @return int|null
     */
    protected function getCurrentUserId(): ?int
    {
        return Auth::id();
    }

    /**
     * Get client IP address
     * 
     * @return string|null
     */
    protected function getIpAddress(): ?string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }

        return request()->ip();
    }

    /**
     * Get client user agent (truncated to 500 chars)
     * 
     * @return string|null
     */
    protected function getUserAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }

        $userAgent = request()->userAgent();
        
        return $userAgent ? substr($userAgent, 0, 500) : null;
    }

    // ==================== STATISTICS ====================

    /**
     * Get audit statistics for a plan
     * 
     * @param Plan|int $plan
     * @return array
     */
    public function getStatsForPlan($plan): array
    {
        $planId = $plan instanceof Plan ? $plan->id : $plan;

        $logs = PlanAuditLog::where('plan_id', $planId)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        return [
            'total_changes' => array_sum($logs),
            'by_action' => $logs,
            'last_modified' => PlanAuditLog::where('plan_id', $planId)
                ->latest('created_at')
                ->value('created_at'),
        ];
    }

    /**
     * Get overall audit statistics
     * 
     * @return array
     */
    public function getOverallStats(): array
    {
        $logs = PlanAuditLog::selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        return [
            'total_logs' => array_sum($logs),
            'by_action' => $logs,
            'plans_modified' => PlanAuditLog::distinct('plan_id')->count('plan_id'),
            'unique_actors' => PlanAuditLog::distinct('user_id')->count('user_id'),
        ];
    }
}
