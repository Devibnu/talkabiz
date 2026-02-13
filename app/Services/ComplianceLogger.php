<?php

namespace App\Services;

use App\Models\ComplianceLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ComplianceLogger - Enterprise Compliance & Legal Logging Service
 * 
 * Append-only, actor-aware, context-aware logging service
 * for all critical business operations.
 * 
 * Features:
 * - Hash-chained records for tamper evidence
 * - Auto-detection of actor (user, admin, system)
 * - Module-specific convenience methods
 * - Correlation ID for linking related events
 * - Financial amount tracking
 * - Legal basis tagging
 * - Idempotency protection
 * 
 * NEVER updates or deletes records.
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class ComplianceLogger
{
    protected ?string $correlationId = null;
    protected ?string $requestId = null;

    public function __construct()
    {
        // Generate request ID per HTTP request (or CLI execution)
        $this->requestId = Str::uuid()->toString();
    }

    // ==================== CORRELATION ====================

    /**
     * Set correlation ID for linking related compliance events
     */
    public function setCorrelationId(string $correlationId): self
    {
        $this->correlationId = $correlationId;
        return $this;
    }

    /**
     * Generate and set a new correlation ID
     */
    public function newCorrelation(): string
    {
        $this->correlationId = 'cpl_' . Str::ulid();
        return $this->correlationId;
    }

    /**
     * Get current correlation ID
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    // ==================== CORE LOG METHOD ====================

    /**
     * Create an immutable compliance log entry.
     * 
     * This is the single entry point for all compliance logging.
     * Records are append-only and hash-chained.
     */
    public function log(
        string $module,
        string $action,
        string $description,
        array $options = []
    ): ?ComplianceLog {
        try {
            $actor = $this->detectActor($options);

            // Idempotency check
            if (!empty($options['idempotency_key'])) {
                $existing = ComplianceLog::where('idempotency_key', $options['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $data = [
                // Module & Action
                'module' => $module,
                'action' => $action,
                'severity' => $options['severity'] ?? ComplianceLog::SEVERITY_INFO,
                'outcome' => $options['outcome'] ?? 'success',

                // Actor
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'actor_name' => $actor['name'],
                'actor_email' => $actor['email'],
                'actor_role' => $actor['role'],
                'actor_ip' => $actor['ip'],
                'actor_user_agent' => $actor['user_agent'],
                'actor_session_id' => $actor['session_id'],

                // Target
                'target_type' => $options['target_type'] ?? null,
                'target_id' => $options['target_id'] ?? null,
                'target_label' => $options['target_label'] ?? null,

                // Klien context
                'klien_id' => $options['klien_id'] ?? null,

                // Description & Data
                'description' => $description,
                'before_state' => $options['before_state'] ?? null,
                'after_state' => $options['after_state'] ?? null,
                'context' => $options['context'] ?? null,
                'evidence' => $options['evidence'] ?? null,

                // Financial
                'amount' => $options['amount'] ?? null,
                'currency' => $options['currency'] ?? 'IDR',

                // Correlation
                'correlation_id' => $options['correlation_id'] ?? $this->correlationId,
                'request_id' => $this->requestId,
                'idempotency_key' => $options['idempotency_key'] ?? null,

                // Legal
                'legal_basis' => $options['legal_basis'] ?? null,
                'regulation_ref' => $options['regulation_ref'] ?? null,
                'retention_until' => $options['retention_until'] ?? null,
                'is_sensitive' => $options['is_sensitive'] ?? false,
                'is_financial' => $options['is_financial'] ?? false,

                // Timestamps
                'occurred_at' => $options['occurred_at'] ?? now(),
            ];

            return ComplianceLog::create($data);

        } catch (\Exception $e) {
            // Compliance logging should NEVER crash the host operation.
            // Fail silently but log the error to system log.
            Log::critical('COMPLIANCE LOGGER FAILURE: Unable to write compliance log', [
                'module' => $module,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    // ==================== WALLET MODULE ====================

    /**
     * Log wallet top-up operation
     */
    public function logWalletTopup(
        int $userId,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        string $source,
        ?int $klienId = null,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_WALLET,
            ComplianceLog::ACTION_WALLET_TOPUP,
            "Wallet top-up: Rp " . number_format($amount, 0, ',', '.') . " via {$source}",
            array_merge([
                'severity' => ComplianceLog::SEVERITY_INFO,
                'target_type' => 'wallet',
                'target_id' => $userId,
                'target_label' => "User #{$userId}",
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'before_state' => ['balance' => $balanceBefore],
                'after_state' => ['balance' => $balanceAfter],
                'evidence' => [
                    'source' => $source,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
                'regulation_ref' => 'POJK-77/2016',
            ], $extra)
        );
    }

    /**
     * Log wallet deduction
     */
    public function logWalletDeduct(
        int $userId,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        string $referenceType,
        int $referenceId,
        ?int $klienId = null,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_WALLET,
            ComplianceLog::ACTION_WALLET_DEDUCT,
            "Wallet deduction: Rp " . number_format($amount, 0, ',', '.') . " for {$referenceType} #{$referenceId}",
            array_merge([
                'severity' => ComplianceLog::SEVERITY_INFO,
                'target_type' => 'wallet',
                'target_id' => $userId,
                'target_label' => "User #{$userId}",
                'klien_id' => $klienId,
                'amount' => -$amount,
                'is_financial' => true,
                'before_state' => ['balance' => $balanceBefore],
                'after_state' => ['balance' => $balanceAfter],
                'evidence' => [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
            ], $extra)
        );
    }

    /**
     * Log wallet creation
     */
    public function logWalletCreate(int $userId, int $walletId, ?int $klienId = null): ?ComplianceLog
    {
        return $this->log(
            ComplianceLog::MODULE_WALLET,
            ComplianceLog::ACTION_WALLET_CREATE,
            "Wallet created for User #{$userId}",
            [
                'severity' => ComplianceLog::SEVERITY_INFO,
                'target_type' => 'wallet',
                'target_id' => $walletId,
                'target_label' => "Wallet #{$walletId} for User #{$userId}",
                'klien_id' => $klienId,
                'is_financial' => true,
                'evidence' => ['user_id' => $userId, 'wallet_id' => $walletId],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
            ]
        );
    }

    /**
     * Log top-up confirmation (admin action)
     */
    public function logTopupConfirm(
        int $transaksiId,
        int $confirmedBy,
        float $amount,
        float $balanceBefore,
        float $balanceAfter,
        ?int $klienId = null,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_WALLET,
            ComplianceLog::ACTION_WALLET_CONFIRM_TOPUP,
            "Top-up confirmed: Transaksi #{$transaksiId}, Rp " . number_format($amount, 0, ',', '.'),
            array_merge([
                'severity' => ComplianceLog::SEVERITY_WARNING,
                'target_type' => 'transaksi_saldo',
                'target_id' => $transaksiId,
                'target_label' => "Transaksi #{$transaksiId}",
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'before_state' => ['balance' => $balanceBefore, 'status' => 'pending'],
                'after_state' => ['balance' => $balanceAfter, 'status' => 'paid'],
                'evidence' => [
                    'transaksi_id' => $transaksiId,
                    'confirmed_by' => $confirmedBy,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
                'regulation_ref' => 'POJK-77/2016',
            ], $extra)
        );
    }

    /**
     * Log top-up rejection (admin action)
     */
    public function logTopupReject(
        int $transaksiId,
        int $rejectedBy,
        float $amount,
        string $reason,
        ?int $klienId = null
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_WALLET,
            ComplianceLog::ACTION_WALLET_REJECT_TOPUP,
            "Top-up rejected: Transaksi #{$transaksiId}, Rp " . number_format($amount, 0, ',', '.') . " — {$reason}",
            [
                'severity' => ComplianceLog::SEVERITY_WARNING,
                'target_type' => 'transaksi_saldo',
                'target_id' => $transaksiId,
                'target_label' => "Transaksi #{$transaksiId}",
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'before_state' => ['status' => 'pending'],
                'after_state' => ['status' => 'rejected'],
                'evidence' => [
                    'transaksi_id' => $transaksiId,
                    'rejected_by' => $rejectedBy,
                    'amount' => $amount,
                    'reason' => $reason,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
            ]
        );
    }

    // ==================== BILLING MODULE ====================

    /**
     * Log billing top-up request
     */
    public function logBillingTopupRequest(
        int $klienId,
        float $amount,
        string $method,
        string $transactionCode,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_BILLING,
            ComplianceLog::ACTION_BILLING_TOPUP_REQUEST,
            "Top-up request: Rp " . number_format($amount, 0, ',', '.') . " via {$method} [{$transactionCode}]",
            array_merge([
                'severity' => ComplianceLog::SEVERITY_INFO,
                'target_type' => 'transaksi_saldo',
                'target_label' => $transactionCode,
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'evidence' => [
                    'transaction_code' => $transactionCode,
                    'method' => $method,
                    'amount' => $amount,
                    'klien_id' => $klienId,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
            ], $extra)
        );
    }

    /**
     * Log payment gateway transaction
     */
    public function logPaymentGateway(
        string $gateway,
        int $klienId,
        float $amount,
        string $transactionCode,
        string $outcome = 'success',
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_BILLING,
            ComplianceLog::ACTION_BILLING_PAYMENT_GATEWAY,
            "Payment gateway ({$gateway}): Rp " . number_format($amount, 0, ',', '.') . " [{$transactionCode}]",
            array_merge([
                'severity' => $outcome === 'success' ? ComplianceLog::SEVERITY_INFO : ComplianceLog::SEVERITY_WARNING,
                'outcome' => $outcome,
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'evidence' => [
                    'gateway' => $gateway,
                    'transaction_code' => $transactionCode,
                    'amount' => $amount,
                ],
                'legal_basis' => ComplianceLog::LEGAL_OJK,
            ], $extra)
        );
    }

    /**
     * Log quick top-up (super admin action)
     */
    public function logQuickTopup(
        int $klienId,
        float $amount,
        int $adminId,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_BILLING,
            ComplianceLog::ACTION_BILLING_QUICK_TOPUP,
            "Quick top-up by admin: Rp " . number_format($amount, 0, ',', '.') . " for Klien #{$klienId}",
            array_merge([
                'severity' => ComplianceLog::SEVERITY_CRITICAL,
                'target_type' => 'klien',
                'target_id' => $klienId,
                'klien_id' => $klienId,
                'amount' => $amount,
                'is_financial' => true,
                'evidence' => [
                    'admin_id' => $adminId,
                    'klien_id' => $klienId,
                    'amount' => $amount,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ], $extra)
        );
    }

    // ==================== APPROVAL MODULE ====================

    /**
     * Log klien approval action
     */
    public function logApproval(
        string $action,
        int $klienId,
        string $klienName,
        string $reason,
        ?string $previousStatus = null,
        ?string $newStatus = null
    ): ?ComplianceLog {
        $actionLabel = match ($action) {
            'approve' => ComplianceLog::ACTION_KLIEN_APPROVE,
            'reject' => ComplianceLog::ACTION_KLIEN_REJECT,
            'suspend' => ComplianceLog::ACTION_KLIEN_SUSPEND,
            'reactivate' => ComplianceLog::ACTION_KLIEN_REACTIVATE,
            default => "approval.{$action}",
        };

        $severity = match ($action) {
            'approve', 'reactivate' => ComplianceLog::SEVERITY_WARNING,
            'reject', 'suspend' => ComplianceLog::SEVERITY_CRITICAL,
            default => ComplianceLog::SEVERITY_INFO,
        };

        return $this->log(
            ComplianceLog::MODULE_APPROVAL,
            $actionLabel,
            "Klien {$action}: {$klienName} (#{$klienId}) — {$reason}",
            [
                'severity' => $severity,
                'target_type' => 'klien',
                'target_id' => $klienId,
                'target_label' => $klienName,
                'klien_id' => $klienId,
                'before_state' => $previousStatus ? ['status' => $previousStatus] : null,
                'after_state' => $newStatus ? ['status' => $newStatus] : null,
                'evidence' => [
                    'action' => $action,
                    'klien_id' => $klienId,
                    'klien_name' => $klienName,
                    'reason' => $reason,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ]
        );
    }

    // ==================== ABUSE MODULE ====================

    /**
     * Log abuse event recording
     */
    public function logAbuseEvent(
        int $klienId,
        string $eventType,
        float $scoreImpact,
        float $previousScore,
        float $newScore,
        string $detectionSource = 'system',
        array $extra = []
    ): ?ComplianceLog {
        $severity = $scoreImpact >= 50 ? ComplianceLog::SEVERITY_CRITICAL
            : ($scoreImpact >= 20 ? ComplianceLog::SEVERITY_WARNING : ComplianceLog::SEVERITY_INFO);

        return $this->log(
            ComplianceLog::MODULE_ABUSE,
            ComplianceLog::ACTION_ABUSE_EVENT,
            "Abuse event [{$eventType}] for Klien #{$klienId}: score impact +{$scoreImpact} ({$previousScore}→{$newScore})",
            array_merge([
                'severity' => $severity,
                'target_type' => 'klien',
                'target_id' => $klienId,
                'klien_id' => $klienId,
                'before_state' => ['abuse_score' => $previousScore],
                'after_state' => ['abuse_score' => $newScore],
                'evidence' => [
                    'event_type' => $eventType,
                    'score_impact' => $scoreImpact,
                    'previous_score' => $previousScore,
                    'new_score' => $newScore,
                    'detection_source' => $detectionSource,
                ],
                'legal_basis' => ComplianceLog::LEGAL_UU_ITE,
            ], $extra)
        );
    }

    /**
     * Log abuse score reset (admin action)
     */
    public function logAbuseScoreReset(
        int $klienId,
        float $previousScore,
        ?string $reason = null
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_ABUSE,
            ComplianceLog::ACTION_ABUSE_SCORE_RESET,
            "Abuse score reset for Klien #{$klienId}: {$previousScore} → 0" . ($reason ? " — {$reason}" : ''),
            [
                'severity' => ComplianceLog::SEVERITY_CRITICAL,
                'target_type' => 'abuse_score',
                'target_id' => $klienId,
                'klien_id' => $klienId,
                'before_state' => ['abuse_score' => $previousScore],
                'after_state' => ['abuse_score' => 0],
                'evidence' => [
                    'klien_id' => $klienId,
                    'previous_score' => $previousScore,
                    'reason' => $reason,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ]
        );
    }

    /**
     * Log recipient complaint recording
     */
    public function logComplaintRecorded(
        int $klienId,
        string $recipientPhone,
        string $complaintType,
        string $severity,
        float $scoreImpact,
        int $complaintId,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_ABUSE,
            ComplianceLog::ACTION_ABUSE_COMPLAINT,
            "Complaint recorded: [{$complaintType}] from {$recipientPhone} against Klien #{$klienId} (severity: {$severity})",
            array_merge([
                'severity' => $severity === 'critical' ? ComplianceLog::SEVERITY_CRITICAL
                    : ($severity === 'high' ? ComplianceLog::SEVERITY_WARNING : ComplianceLog::SEVERITY_INFO),
                'target_type' => 'recipient_complaint',
                'target_id' => $complaintId,
                'klien_id' => $klienId,
                'evidence' => [
                    'complaint_id' => $complaintId,
                    'recipient_phone' => $recipientPhone,
                    'complaint_type' => $complaintType,
                    'severity' => $severity,
                    'score_impact' => $scoreImpact,
                ],
                'is_sensitive' => true,
                'legal_basis' => ComplianceLog::LEGAL_UU_ITE,
            ], $extra)
        );
    }

    // ==================== COMPLAINT MONITOR MODULE ====================

    /**
     * Log klien suspension via complaint monitor
     */
    public function logComplaintSuspension(
        int $complaintId,
        int $klienId,
        string $klienName,
        int $suspensionDays,
        string $reason,
        string $suspendedUntil
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_COMPLAINT,
            ComplianceLog::ACTION_COMPLAINT_SUSPEND,
            "Klien suspended via complaint: {$klienName} (#{$klienId}) for {$suspensionDays} days until {$suspendedUntil} — {$reason}",
            [
                'severity' => ComplianceLog::SEVERITY_CRITICAL,
                'target_type' => 'klien',
                'target_id' => $klienId,
                'target_label' => $klienName,
                'klien_id' => $klienId,
                'before_state' => ['status' => 'active'],
                'after_state' => ['status' => 'temp_suspended', 'suspended_until' => $suspendedUntil],
                'evidence' => [
                    'complaint_id' => $complaintId,
                    'suspension_days' => $suspensionDays,
                    'reason' => $reason,
                    'suspended_until' => $suspendedUntil,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ]
        );
    }

    /**
     * Log recipient blocking via complaint monitor
     */
    public function logRecipientBlock(
        int $complaintId,
        string $recipientPhone,
        string $reason,
        ?int $klienId = null
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_COMPLAINT,
            ComplianceLog::ACTION_COMPLAINT_BLOCK,
            "Recipient blocked: {$recipientPhone} — {$reason}",
            [
                'severity' => ComplianceLog::SEVERITY_WARNING,
                'target_type' => 'recipient',
                'target_label' => $recipientPhone,
                'klien_id' => $klienId,
                'is_sensitive' => true,
                'evidence' => [
                    'complaint_id' => $complaintId,
                    'recipient_phone' => $recipientPhone,
                    'reason' => $reason,
                ],
                'legal_basis' => ComplianceLog::LEGAL_UU_ITE,
            ]
        );
    }

    /**
     * Log complaint dismissal (false positive)
     */
    public function logComplaintDismiss(
        int $complaintId,
        string $reason,
        ?float $scoreReduction = null,
        ?int $klienId = null
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_COMPLAINT,
            ComplianceLog::ACTION_COMPLAINT_DISMISS,
            "Complaint #{$complaintId} dismissed: {$reason}" . ($scoreReduction ? " (score reduced by {$scoreReduction})" : ''),
            [
                'severity' => ComplianceLog::SEVERITY_WARNING,
                'target_type' => 'recipient_complaint',
                'target_id' => $complaintId,
                'klien_id' => $klienId,
                'evidence' => [
                    'complaint_id' => $complaintId,
                    'reason' => $reason,
                    'score_reduction' => $scoreReduction,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ]
        );
    }

    /**
     * Log bulk action from complaint monitor
     */
    public function logComplaintBulkAction(
        string $action,
        array $complaintIds,
        int $successCount,
        ?string $reason = null,
        array $extra = []
    ): ?ComplianceLog {
        return $this->log(
            ComplianceLog::MODULE_COMPLAINT,
            ComplianceLog::ACTION_COMPLAINT_BULK,
            "Bulk {$action}: {$successCount}/" . count($complaintIds) . " complaints processed" . ($reason ? " — {$reason}" : ''),
            array_merge([
                'severity' => $action === 'suspend_klien' ? ComplianceLog::SEVERITY_CRITICAL : ComplianceLog::SEVERITY_WARNING,
                'evidence' => [
                    'action' => $action,
                    'complaint_ids' => $complaintIds,
                    'total' => count($complaintIds),
                    'success_count' => $successCount,
                    'reason' => $reason,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ], $extra)
        );
    }

    /**
     * Log complaint data export
     */
    public function logComplaintExport(int $count, array $filters = []): ?ComplianceLog
    {
        return $this->log(
            ComplianceLog::MODULE_COMPLAINT,
            ComplianceLog::ACTION_COMPLAINT_EXPORT,
            "Complaint data exported: {$count} records",
            [
                'severity' => ComplianceLog::SEVERITY_WARNING,
                'is_sensitive' => true,
                'evidence' => [
                    'record_count' => $count,
                    'filters' => $filters,
                ],
                'legal_basis' => ComplianceLog::LEGAL_COMPANY_POLICY,
            ]
        );
    }

    // ==================== ACTOR DETECTION ====================

    /**
     * Detect current actor from auth context, request, or explicit override
     */
    protected function detectActor(array $options = []): array
    {
        $user = Auth::user();
        $request = request();

        // Explicit override
        if (!empty($options['actor_type'])) {
            return [
                'type' => $options['actor_type'],
                'id' => $options['actor_id'] ?? null,
                'name' => $options['actor_name'] ?? null,
                'email' => $options['actor_email'] ?? null,
                'role' => $options['actor_role'] ?? null,
                'ip' => $options['actor_ip'] ?? ($request ? $request->ip() : null),
                'user_agent' => $request ? $request->userAgent() : null,
                'session_id' => $request && $request->hasSession() ? $request->session()->getId() : null,
            ];
        }

        // Authenticated user
        if ($user) {
            $role = $user->role ?? 'user';
            $actorType = in_array($role, ['superadmin', 'admin', 'owner']) 
                ? ComplianceLog::ACTOR_ADMIN 
                : ComplianceLog::ACTOR_USER;

            return [
                'type' => $actorType,
                'id' => $user->id,
                'name' => $user->name ?? $user->email,
                'email' => $user->email,
                'role' => $role,
                'ip' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->userAgent() : null,
                'session_id' => $request && $request->hasSession() ? $request->session()->getId() : null,
            ];
        }

        // System/CLI context
        return [
            'type' => app()->runningInConsole() ? ComplianceLog::ACTOR_CRON : ComplianceLog::ACTOR_SYSTEM,
            'id' => null,
            'name' => app()->runningInConsole() ? 'artisan' : 'system',
            'email' => null,
            'role' => 'system',
            'ip' => $request ? $request->ip() : '127.0.0.1',
            'user_agent' => $request ? $request->userAgent() : 'CLI',
            'session_id' => null,
        ];
    }

    // ==================== CHAIN VERIFICATION ====================

    /**
     * Verify the integrity of the compliance log chain
     */
    public function verifyChain(int $limit = 100): array
    {
        return ComplianceLog::verifyChain(null, $limit);
    }

    // ==================== QUERY HELPERS ====================

    /**
     * Get financial compliance logs for a klien within a date range
     */
    public function getFinancialLogs(int $klienId, string $from, string $to): \Illuminate\Support\Collection
    {
        return ComplianceLog::forKlien($klienId)
            ->financial()
            ->dateRange($from, $to)
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Get all critical/legal severity logs
     */
    public function getCriticalLogs(int $days = 30): \Illuminate\Support\Collection
    {
        return ComplianceLog::critical()
            ->recent($days)
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * Get logs by correlation ID (linked events)
     */
    public function getCorrelatedLogs(string $correlationId): \Illuminate\Support\Collection
    {
        return ComplianceLog::byCorrelation($correlationId)
            ->orderBy('occurred_at')
            ->get();
    }
}
