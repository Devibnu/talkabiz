<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAdjustment;
use App\Models\AdjustmentApproval;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdjustmentAuditService
{
    // ==================== AUDIT LOGGING METHODS ====================

    /**
     * Log adjustment creation
     */
    public static function logAdjustmentCreated(UserAdjustment $adjustment, array $additionalData = []): void
    {
        $auditData = [
            'event' => 'adjustment_created',
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'created_by' => $adjustment->created_by,
            'direction' => $adjustment->direction,
            'amount' => $adjustment->amount,
            'reason_code' => $adjustment->reason_code,
            'reason_note' => $adjustment->reason_note,
            'balance_before' => $adjustment->balance_before,
            'balance_after' => $adjustment->balance_after,
            'status' => $adjustment->status,
            'requires_approval' => $adjustment->requires_approval,
            'is_high_risk' => $adjustment->is_high_risk,
            'ip_address' => $adjustment->ip_address,
            'user_agent' => $adjustment->user_agent,
            'security_hash' => $adjustment->security_hash,
            'timestamp' => $adjustment->created_at->toISOString(),
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('adjustment_created', $auditData);
        self::notifySecurityTeam($adjustment, 'created');
    }

    /**
     * Log approval action
     */
    public static function logAdjustmentApproval(
        UserAdjustment $adjustment, 
        AdjustmentApproval $approval, 
        array $additionalData = []
    ): void {
        $auditData = [
            'event' => 'adjustment_approved',
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'approver_id' => $approval->approver_id,
            'approval_action' => $approval->action,
            'approval_note' => $approval->approval_note,
            'auto_approval_reason' => $approval->auto_approval_reason,
            'amount' => $adjustment->amount,
            'direction' => $adjustment->direction,
            'reason_code' => $adjustment->reason_code,
            'previous_status' => 'pending_approval',
            'new_status' => $adjustment->status,
            'ip_address' => $approval->ip_address,
            'user_agent' => $approval->user_agent,
            'approval_timestamp' => $approval->created_at->toISOString(),
            'processing_duration_seconds' => $adjustment->created_at->diffInSeconds($approval->created_at),
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('adjustment_approved', $auditData);
        
        if ($approval->action === 'approve') {
            self::notifySecurityTeam($adjustment, 'approved', $approval->approver_id);
        }
    }

    /**
     * Log rejection action
     */
    public static function logAdjustmentRejection(
        UserAdjustment $adjustment, 
        AdjustmentApproval $approval, 
        array $additionalData = []
    ): void {
        $auditData = [
            'event' => 'adjustment_rejected',
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'rejector_id' => $approval->approver_id,
            'rejection_reason' => $approval->approval_note,
            'amount' => $adjustment->amount,
            'direction' => $adjustment->direction,
            'reason_code' => $adjustment->reason_code,
            'previous_status' => 'pending_approval',
            'new_status' => 'rejected',
            'ip_address' => $approval->ip_address,
            'user_agent' => $approval->user_agent,
            'rejection_timestamp' => $approval->created_at->toISOString(),
            'processing_duration_seconds' => $adjustment->created_at->diffInSeconds($approval->created_at),
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('adjustment_rejected', $auditData);
        self::notifySecurityTeam($adjustment, 'rejected', $approval->approver_id);
    }

    /**
     * Log adjustment processing to ledger
     */
    public static function logAdjustmentProcessed(
        UserAdjustment $adjustment, 
        int $ledgerEntryId, 
        array $balanceInfo = [], 
        array $additionalData = []
    ): void {
        $auditData = [
            'event' => 'adjustment_processed',
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'processed_by' => $adjustment->processed_by,
            'ledger_entry_id' => $ledgerEntryId,
            'amount' => $adjustment->amount,
            'direction' => $adjustment->direction,
            'reason_code' => $adjustment->reason_code,
            'previous_status' => $adjustment->getOriginal('status'),
            'new_status' => 'processed',
            'balance_before' => $balanceInfo['balance_before'] ?? $adjustment->balance_before,
            'balance_after' => $balanceInfo['balance_after'] ?? $adjustment->balance_after,
            'balance_change' => $adjustment->net_amount,
            'processed_timestamp' => $adjustment->processed_at->toISOString(),
            'total_processing_time_seconds' => $adjustment->created_at->diffInSeconds($adjustment->processed_at),
            'approval_to_processing_time_seconds' => $adjustment->approved_at ? 
                $adjustment->approved_at->diffInSeconds($adjustment->processed_at) : null,
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('adjustment_processed', $auditData);
        self::recordBalanceChange($adjustment, $balanceInfo);
        self::notifySecurityTeam($adjustment, 'processed');
    }

    /**
     * Log adjustment failure
     */
    public static function logAdjustmentFailure(
        UserAdjustment $adjustment, 
        string $failureReason, 
        array $additionalData = []
    ): void {
        $auditData = [
            'event' => 'adjustment_failed',
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'amount' => $adjustment->amount,
            'direction' => $adjustment->direction,
            'reason_code' => $adjustment->reason_code,
            'failure_reason' => $failureReason,
            'previous_status' => $adjustment->getOriginal('status'),
            'new_status' => 'failed',
            'retry_count' => $adjustment->retry_count,
            'failed_timestamp' => $adjustment->failed_at->toISOString(),
            'time_from_creation_seconds' => $adjustment->created_at->diffInSeconds($adjustment->failed_at),
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('adjustment_failed', $auditData);
        self::alertSecurityTeam($adjustment, 'Failed adjustment requires investigation', $additionalData);
    }

    /**
     * Log security violations
     */
    public static function logSecurityViolation(
        string $violationType, 
        UserAdjustment $adjustment = null, 
        array $violationData = []
    ): void {
        $auditData = [
            'event' => 'security_violation',
            'violation_type' => $violationType,
            'adjustment_id' => $adjustment?->adjustment_id,
            'user_id' => $adjustment?->user_id,
            'suspicious_user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
            'violation_data' => $violationData,
            'severity' => self::determineSeverity($violationType),
            'auto_blocked' => self::shouldAutoBlock($violationType),
            'session_id' => session()->getId()
        ];

        self::writeAuditLog('security_violation', $auditData);
        self::handleSecurityViolation($violationType, $auditData);
    }

    /**
     * Log unauthorized access attempts
     */
    public static function logUnauthorizedAccess(string $attemptType, array $attemptData = []): void
    {
        $auditData = [
            'event' => 'unauthorized_access',
            'attempt_type' => $attemptType,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'requested_url' => request()->fullUrl(),
            'request_method' => request()->method(),
            'timestamp' => now()->toISOString(),
            'attempt_data' => $attemptData,
            'session_id' => session()->getId()
        ];

        self::writeAuditLog('unauthorized_access', $auditData);
        
        // Auto-block after multiple unauthorized attempts
        self::checkUnauthorizedAttempts(auth()->id(), request()->ip());
    }

    /**
     * Log bulk operations
     */
    public static function logBulkOperation(
        string $operationType, 
        array $adjustmentIds, 
        array $results, 
        array $additionalData = []
    ): void {
        $auditData = [
            'event' => 'bulk_operation',
            'operation_type' => $operationType,
            'performer_id' => auth()->id(),
            'adjustment_ids' => $adjustmentIds,
            'total_count' => count($adjustmentIds),
            'successful_count' => count($results['successful'] ?? []),
            'failed_count' => count($results['failed'] ?? []),
            'success_rate' => count($adjustmentIds) > 0 ? 
                (count($results['successful'] ?? []) / count($adjustmentIds)) * 100 : 0,
            'results' => $results,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
            'additional_data' => $additionalData
        ];

        self::writeAuditLog('bulk_operation', $auditData);
        
        if (count($adjustmentIds) > 10) { // Large bulk operations
            self::alertSecurityTeam(null, 'Large bulk adjustment operation performed', $auditData);
        }
    }

    // ==================== SECURITY MONITORING ====================

    /**
     * Monitor suspicious patterns
     */
    public static function monitorSuspiciousPatterns(UserAdjustment $adjustment): void
    {
        $userId = $adjustment->user_id;
        $createdBy = $adjustment->created_by;
        $amount = $adjustment->amount;

        // Check for rapid succession adjustments
        $recentAdjustments = UserAdjustment::where('user_id', $userId)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentAdjustments >= 3) {
            self::logSecurityViolation('rapid_adjustments', $adjustment, [
                'recent_count' => $recentAdjustments,
                'time_window' => '5 minutes'
            ]);
        }

        // Check for large amount patterns
        $largeAmountThreshold = config('adjustment.large_amount_threshold', 1000000); // 1M
        if ($amount > $largeAmountThreshold) {
            $recentLargeAdjustments = UserAdjustment::where('created_by', $createdBy)
                ->where('amount', '>', $largeAmountThreshold)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($recentLargeAdjustments >= 3) {
                self::logSecurityViolation('repeated_large_amounts', $adjustment, [
                    'large_amount_count_24h' => $recentLargeAdjustments,
                    'amount_threshold' => $largeAmountThreshold
                ]);
            }
        }

        // Check for unusual time patterns (e.g., adjustments at 3 AM)
        $hour = now()->hour;
        if ($hour >= 2 && $hour <= 5) { // 2 AM - 5 AM
            self::logSecurityViolation('unusual_time_activity', $adjustment, [
                'hour' => $hour,
                'timestamp' => now()->toISOString()
            ]);
        }

        // Check for same-user self-adjustment patterns
        if ($userId === $createdBy && $amount > 100000) { // > 100k self-adjustment
            self::logSecurityViolation('self_adjustment_large_amount', $adjustment, [
                'amount' => $amount,
                'self_adjustment' => true
            ]);
        }
    }

    /**
     * Verify security hash integrity
     */
    public static function verifySecurityIntegrity(UserAdjustment $adjustment): bool
    {
        $isValid = $adjustment->verifySecurityHash();
        
        if (!$isValid) {
            self::logSecurityViolation('hash_verification_failed', $adjustment, [
                'stored_hash' => $adjustment->security_hash,
                'computed_hash' => $adjustment->generateSecurityHash(),
                'potential_tampering' => true
            ]);
        }

        return $isValid;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Write audit log to multiple destinations
     */
    protected static function writeAuditLog(string $eventType, array $data): void
    {
        // Write to Laravel log
        Log::channel('audit')->info($eventType, $data);

        // Write to dedicated database table (if exists)
        try {
            DB::table('audit_logs')->insert([
                'event_type' => $eventType,
                'event_data' => json_encode($data),
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } catch (\Exception $e) {
            // Fallback if audit_logs table doesn't exist
            Log::warning('Failed to write to audit_logs table', ['error' => $e->getMessage()]);
        }

        // Real-time monitoring (if configured)
        if (config('adjustment.real_time_monitoring', false)) {
            self::sendToMonitoringService($eventType, $data);
        }
    }

    /**
     * Record balance change for audit trail
     */
    protected static function recordBalanceChange(UserAdjustment $adjustment, array $balanceInfo): void
    {
        $changeData = [
            'adjustment_id' => $adjustment->adjustment_id,
            'user_id' => $adjustment->user_id,
            'change_type' => 'adjustment',
            'direction' => $adjustment->direction,
            'amount' => $adjustment->amount,
            'balance_before' => $balanceInfo['balance_before'] ?? $adjustment->balance_before,
            'balance_after' => $balanceInfo['balance_after'] ?? $adjustment->balance_after,
            'net_change' => $adjustment->net_amount,
            'processed_by' => $adjustment->processed_by,
            'processed_at' => $adjustment->processed_at,
            'reference_type' => 'user_adjustment',
            'reference_id' => $adjustment->id
        ];

        try {
            DB::table('balance_change_audit')->insert(array_merge($changeData, [
                'created_at' => now(),
                'updated_at' => now()
            ]));
        } catch (\Exception $e) {
            Log::warning('Failed to record balance change audit', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notify security team for important events
     */
    protected static function notifySecurityTeam(
        UserAdjustment $adjustment, 
        string $action, 
        int $actorId = null
    ): void {
        if (!config('adjustment.notify_security_team', true)) {
            return;
        }

        $shouldNotify = false;

        // Notify for high-risk adjustments
        if ($adjustment->is_high_risk) {
            $shouldNotify = true;
        }

        // Notify for large amounts
        if ($adjustment->amount > config('adjustment.security_notification_threshold', 500000)) {
            $shouldNotify = true;
        }

        // Notify for certain reason codes
        $highRiskReasons = ['fraud_recovery', 'manual_override', 'dispute_resolution'];
        if (in_array($adjustment->reason_code, $highRiskReasons)) {
            $shouldNotify = true;
        }

        if ($shouldNotify) {
            // Send notification (email, Slack, etc.)
            // Implementation depends on your notification system
            \App\Helpers\SecurityLog::info('Security team notification', [
                'adjustment_id' => $adjustment->adjustment_id,
                'action' => $action,
                'amount' => $adjustment->amount,
                'user_id' => $adjustment->user_id,
                'actor_id' => $actorId,
                'reason_code' => $adjustment->reason_code,
                'is_high_risk' => $adjustment->is_high_risk,
                'timestamp' => now()->toISOString()
            ]);
        }
    }

    /**
     * Alert security team for urgent issues
     */
    protected static function alertSecurityTeam(
        ?UserAdjustment $adjustment, 
        string $message, 
        array $data = []
    ): void {
        \App\Helpers\SecurityLog::alert($message, array_merge($data, [
            'adjustment_id' => $adjustment?->adjustment_id,
            'timestamp' => now()->toISOString(),
            'requires_immediate_attention' => true
        ]));
    }

    /**
     * Determine severity level for violations
     */
    protected static function determineSeverity(string $violationType): string
    {
        return match($violationType) {
            'hash_verification_failed', 'unauthorized_data_modification' => 'critical',
            'rapid_adjustments', 'repeated_large_amounts', 'self_adjustment_large_amount' => 'high',
            'unusual_time_activity', 'suspicious_ip_pattern' => 'medium',
            default => 'low'
        };
    }

    /**
     * Check if violation should trigger auto-block
     */
    protected static function shouldAutoBlock(string $violationType): bool
    {
        return in_array($violationType, [
            'hash_verification_failed',
            'unauthorized_data_modification'
        ]);
    }

    /**
     * Handle security violations
     */
    protected static function handleSecurityViolation(string $violationType, array $violationData): void
    {
        $severity = $violationData['severity'];
        
        if ($violationData['auto_blocked']) {
            // Implement auto-blocking logic
            self::blockSuspiciousUser($violationData['suspicious_user_id'], $violationType);
        }

        // Critical violations need immediate attention
        if ($severity === 'critical') {
            self::triggerCriticalAlert($violationType, $violationData);
        }
    }

    /**
     * Check for repeated unauthorized attempts
     */
    protected static function checkUnauthorizedAttempts(int $userId, string $ipAddress): void
    {
        $recentAttempts = collect(Log::channel('audit')->getHandler()->getBuffer())
            ->filter(function ($log) use ($userId, $ipAddress) {
                return str_contains($log, 'unauthorized_access') &&
                       str_contains($log, $userId) &&
                       str_contains($log, $ipAddress);
            })
            ->count();

        if ($recentAttempts >= 5) { // 5 attempts in short time
            self::logSecurityViolation('repeated_unauthorized_attempts', null, [
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'attempt_count' => $recentAttempts
            ]);
        }
    }

    /**
     * Block suspicious user (implement based on your auth system)
     */
    protected static function blockSuspiciousUser(int $userId, string $reason): void
    {
        // Implementation depends on your user management system
        // For example: disable user account, invalidate sessions, etc.
        \App\Helpers\SecurityLog::critical('User auto-blocked due to security violation', [
            'user_id' => $userId,
            'reason' => $reason,
            'timestamp' => now()->toISOString(),
            'action_required' => 'manual_review'
        ]);
    }

    /**
     * Trigger critical security alert
     */
    protected static function triggerCriticalAlert(string $violationType, array $violationData): void
    {
        // Send immediate notifications to security team
        // SMS, Slack, email, webhook, etc.
        \App\Helpers\SecurityLog::emergency('CRITICAL SECURITY VIOLATION', [
            'violation_type' => $violationType,
            'data' => $violationData,
            'timestamp' => now()->toISOString(),
            'immediate_action_required' => true
        ]);
    }

    /**
     * Send data to external monitoring service
     */
    protected static function sendToMonitoringService(string $eventType, array $data): void
    {
        // Implement integration with monitoring services like DataDog, New Relic, etc.
        // This is a placeholder for real-time monitoring integration
        try {
            // Example: HTTP POST to monitoring service
            // Http::post(config('monitoring.webhook_url'), [
            //     'event_type' => $eventType,
            //     'data' => $data,
            //     'timestamp' => now()->toISOString()
            // ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send data to monitoring service', ['error' => $e->getMessage()]);
        }
    }

    // ==================== QUERY & REPORTING METHODS ====================

    /**
     * Get audit trail for specific adjustment
     */
    public static function getAdjustmentAuditTrail(int $adjustmentId): array
    {
        try {
            $logs = DB::table('audit_logs')
                ->whereJsonContains('event_data->adjustment_id', $adjustmentId)
                ->orderBy('created_at')
                ->get();

            return $logs->map(function ($log) {
                return [
                    'event_type' => $log->event_type,
                    'event_data' => json_decode($log->event_data, true),
                    'timestamp' => $log->created_at,
                    'user_id' => $log->user_id,
                    'ip_address' => $log->ip_address
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::warning('Failed to retrieve audit trail', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get security violations summary
     */
    public static function getSecurityViolationsSummary(Carbon $fromDate, Carbon $toDate): array
    {
        try {
            $violations = DB::table('audit_logs')
                ->where('event_type', 'security_violation')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->get();

            $summary = $violations->groupBy(function ($violation) {
                $eventData = json_decode($violation->event_data, true);
                return $eventData['violation_type'] ?? 'unknown';
            })->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'latest_occurrence' => $group->max('created_at'),
                    'severity_distribution' => $group->groupBy(function ($item) {
                        $eventData = json_decode($item->event_data, true);
                        return $eventData['severity'] ?? 'unknown';
                    })->map->count()
                ];
            });

            return $summary->toArray();

        } catch (\Exception $e) {
            Log::warning('Failed to retrieve security violations summary', ['error' => $e->getMessage()]);
            return [];
        }
    }
}