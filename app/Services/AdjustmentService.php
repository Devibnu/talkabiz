<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAdjustment;
use App\Models\AdjustmentApproval;
use App\Models\AdjustmentCategory;
use App\Models\LedgerEntry;
use App\Services\AdjustmentAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Exception;

class AdjustmentService
{
    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    // ==================== MAIN ADJUSTMENT OPERATIONS ====================

    /**
     * Create new balance adjustment
     */
    public function createAdjustment(array $data): UserAdjustment
    {
        // Validate input data
        $this->validateAdjustmentData($data);

        // Get user and validate balance
        $user = User::findOrFail($data['user_id']);
        $category = AdjustmentCategory::getByCode($data['reason_code']);

        if (!$category) {
            throw new Exception("Invalid adjustment category: {$data['reason_code']}");
        }

        // Validate direction against category
        if (!$category->supportsDirection($data['direction'])) {
            throw new Exception("Category '{$category->name}' does not support {$data['direction']} direction");
        }

        // Calculate balances
        $currentBalance = $this->getUserCurrentBalance($user->id);
        $newBalance = $this->calculateNewBalance($currentBalance, $data['direction'], $data['amount']);

        // Validate balance constraints
        $this->validateBalanceConstraints($user, $currentBalance, $newBalance, $data);

        // Handle file upload
        $attachmentPath = null;
        if (isset($data['attachment']) && $data['attachment'] instanceof UploadedFile) {
            $attachmentPath = $this->handleAttachmentUpload($data['attachment']);
        }

        // Validate documentation requirements
        $this->validateDocumentationRequirements($category, $data, $attachmentPath);

        return DB::transaction(function () use ($data, $user, $category, $currentBalance, $newBalance, $attachmentPath) {
            // Create adjustment record
            $adjustment = UserAdjustment::create([
                'user_id' => $user->id,
                'direction' => $data['direction'],
                'amount' => $data['amount'],
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'reason_code' => $data['reason_code'],
                'reason_note' => $data['reason_note'],
                'attachment_path' => $attachmentPath,
                'supporting_data' => $data['supporting_data'] ?? [],
                'status' => $this->determineInitialStatus($category, $data['amount']),
                'created_by' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'request_metadata' => $this->gatherRequestMetadata(),
                'is_high_risk' => $this->isHighRiskAdjustment($data, $user),
            ]);

            // Audit logging
            AdjustmentAuditService::logAdjustmentCreated($adjustment, [
                'category_used' => $category->code,
                'auto_approval_limit' => $category->auto_approval_limit,
                'requires_manager_approval' => $category->requiresManagerApproval(),
                'attachment_uploaded' => !empty($attachmentPath)
            ]);

            // Monitor suspicious patterns
            AdjustmentAuditService::monitorSuspiciousPatterns($adjustment);

            // Verify security integrity
            AdjustmentAuditService::verifySecurityIntegrity($adjustment);

            // Create approval record if auto-approved
            if ($adjustment->status === 'auto_approved') {
                $approval = AdjustmentApproval::createAutoApproval(
                    $adjustment->id,
                    "Amount {$data['amount']} below threshold {$category->auto_approval_limit}",
                    ['category_auto_approval_limit' => $category->auto_approval_limit]
                );

                // Log auto-approval
                AdjustmentAuditService::logAdjustmentApproval($adjustment, $approval, [
                    'auto_approved' => true,
                    'threshold_amount' => $category->auto_approval_limit
                ]);

                // Process immediately if auto-approved
                $this->processApprovedAdjustment($adjustment);
            }

            // Log adjustment creation
            Log::info('Balance adjustment created', [
                'adjustment_id' => $adjustment->adjustment_id,
                'user_id' => $user->id,
                'direction' => $data['direction'],
                'amount' => $data['amount'],
                'reason_code' => $data['reason_code'],
                'status' => $adjustment->status,
                'created_by' => auth()->id()
            ]);

            return $adjustment;
        });
    }

    /**
     * Approve pending adjustment
     */
    public function approveAdjustment(int $adjustmentId, array $approvalData = []): UserAdjustment
    {
        $adjustment = UserAdjustment::findOrFail($adjustmentId);

        // Validate adjustment can be approved
        if (!$adjustment->can_be_approved) {
            throw new Exception("Adjustment {$adjustment->adjustment_id} cannot be approved. Current status: {$adjustment->status}");
        }

        // Validate approver permissions
        $this->validateApproverPermissions($adjustment);

        return DB::transaction(function () use ($adjustment, $approvalData) {
            // Mark as approved
            $adjustment->markAsApproved(auth()->id(), 'manually_approved');

            // Create approval record
            $approval = AdjustmentApproval::createApproval(
                $adjustment->id,
                'approve',
                auth()->id(),
                $approvalData['approval_note'] ?? null,
                $approvalData['metadata'] ?? []
            );

            // Audit logging
            AdjustmentAuditService::logAdjustmentApproval($adjustment, $approval, $approvalData);

            // Process the approved adjustment
            $this->processApprovedAdjustment($adjustment);

            Log::info('Adjustment approved and processed', [
                'adjustment_id' => $adjustment->adjustment_id,
                'approved_by' => auth()->id(),
                'approval_note' => $approvalData['approval_note'] ?? null
            ]);

            return $adjustment->fresh();
        });
    }

    /**
     * Reject pending adjustment
     */
    public function rejectAdjustment(int $adjustmentId, string $rejectionReason): UserAdjustment
    {
        $adjustment = UserAdjustment::findOrFail($adjustmentId);

        // Validate adjustment can be rejected
        if (!$adjustment->can_be_rejected) {
            throw new Exception("Adjustment {$adjustment->adjustment_id} cannot be rejected. Current status: {$adjustment->status}");
        }

        // Validate rejector permissions
        $this->validateApproverPermissions($adjustment);

        return DB::transaction(function () use ($adjustment, $rejectionReason) {
            $approval = $adjustment->markAsRejected(auth()->id(), $rejectionReason);

            // Audit logging
            AdjustmentAuditService::logAdjustmentRejection($adjustment, $adjustment->latestApproval, [
                'rejection_reason' => $rejectionReason
            ]);

            Log::info('Adjustment rejected', [
                'adjustment_id' => $adjustment->adjustment_id,
                'rejected_by' => auth()->id(),
                'rejection_reason' => $rejectionReason
            ]);

            return $adjustment->fresh();
        });
    }

    /**
     * Process approved adjustment to ledger
     */
    protected function processApprovedAdjustment(UserAdjustment $adjustment): void
    {
        try {
            // Verify adjustment is ready for processing
            if (!$adjustment->can_be_processed) {
                throw new Exception("Adjustment {$adjustment->adjustment_id} cannot be processed");
            }

            // Re-validate current balance (could have changed since creation)
            $currentBalance = $this->getUserCurrentBalance($adjustment->user_id);
            $newBalance = $this->calculateNewBalance($currentBalance, $adjustment->direction, $adjustment->amount);

            // Validate balance constraints again
            $this->validateBalanceConstraints(
                User::find($adjustment->user_id),
                $currentBalance,
                $newBalance,
                ['direction' => $adjustment->direction, 'amount' => $adjustment->amount]
            );

            // Create ledger entry (immutable transaction)
            $ledgerEntry = $this->ledgerService->createEntry([
                'user_id' => $adjustment->user_id,
                'type' => 'adjustment',
                'direction' => $adjustment->direction,
                'amount' => $adjustment->amount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'description' => $this->generateLedgerDescription($adjustment),
                'reference_type' => 'user_adjustment',
                'reference_id' => $adjustment->id,
                'metadata' => [
                    'adjustment_id' => $adjustment->adjustment_id,
                    'reason_code' => $adjustment->reason_code,
                    'reason_note' => $adjustment->reason_note,
                    'created_by' => $adjustment->created_by,
                    'approved_by' => $adjustment->approved_by
                ]
            ]);

            // Mark adjustment as processed
            $adjustment->markAsProcessed(
                auth()->id() ?: $adjustment->approved_by,
                $ledgerEntry->id,
                [
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance
                ]
            );

            // Audit logging
            AdjustmentAuditService::logAdjustmentProcessed(
                $adjustment, 
                $ledgerEntry->id, 
                [
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance
                ],
                [
                    'ledger_description' => $this->generateLedgerDescription($adjustment),
                    'processing_method' => 'automatic'
                ]
            );

            Log::info('Adjustment processed to ledger', [
                'adjustment_id' => $adjustment->adjustment_id,
                'ledger_entry_id' => $ledgerEntry->id,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance
            ]);

        } catch (Exception $e) {
            // Mark as failed and log error
            $adjustment->markAsFailed($e->getMessage());

            // Audit logging
            AdjustmentAuditService::logAdjustmentFailure($adjustment, $e->getMessage(), [
                'error_trace' => $e->getTraceAsString(),
                'processing_step' => 'ledger_creation'
            ]);

            Log::error('Failed to process adjustment', [
                'adjustment_id' => $adjustment->adjustment_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    // ==================== VALIDATION METHODS ====================

    /**
     * Validate adjustment data
     */
    protected function validateAdjustmentData(array $data): void
    {
        $required = ['user_id', 'direction', 'amount', 'reason_code', 'reason_note'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }

        if (!in_array($data['direction'], ['credit', 'debit'])) {
            throw new Exception("Direction must be 'credit' or 'debit'");
        }

        if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new Exception("Amount must be a positive number");
        }

        // Validate minimum amount
        $minAmount = config('adjustment.min_amount', 1.00);
        if ($data['amount'] < $minAmount) {
            throw new Exception("Amount must be at least Rp " . number_format($minAmount, 2));
        }

        // Validate maximum amount (safety check)
        $maxAmount = config('adjustment.max_amount', 10000000.00); // 10M
        if ($data['amount'] > $maxAmount) {
            throw new Exception("Amount cannot exceed Rp " . number_format($maxAmount, 2));
        }
    }

    /**
     * Validate balance constraints
     */
    protected function validateBalanceConstraints(User $user, float $currentBalance, float $newBalance, array $data): void
    {
        // For debit adjustments, ensure new balance won't go below minimum
        if ($data['direction'] === 'debit') {
            $minBalance = config('adjustment.min_balance', 0.00);
            
            if ($newBalance < $minBalance) {
                throw new Exception(
                    "Debit adjustment would result in balance below minimum. " .
                    "Current: Rp " . number_format($currentBalance, 2) . ", " .
                    "New: Rp " . number_format($newBalance, 2) . ", " .
                    "Minimum: Rp " . number_format($minBalance, 2)
                );
            }
        }

        // Daily limit check
        $dailyLimit = $this->getDailyAdjustmentLimit($user);
        $dailyUsed = $this->getDailyAdjustmentUsed($user->id);
        
        if ($dailyUsed + $data['amount'] > $dailyLimit) {
            throw new Exception(
                "Daily adjustment limit exceeded. " .
                "Limit: Rp " . number_format($dailyLimit, 2) . ", " .
                "Used: Rp " . number_format($dailyUsed, 2) . ", " .
                "Requested: Rp " . number_format($data['amount'], 2)
            );
        }
    }

    /**
     * Validate documentation requirements
     */
    protected function validateDocumentationRequirements(AdjustmentCategory $category, array $data, ?string $attachmentPath): void
    {
        if (!$category->requires_documentation) {
            return;
        }

        $requirements = $category->documentation_requirements ?? [];

        // Check for attachment requirement
        if (in_array('attachment', $requirements) && !$attachmentPath) {
            throw new Exception("Category '{$category->name}' requires file attachment");
        }

        // Check for detailed notes requirement
        if (in_array('detailed_notes', $requirements)) {
            $minLength = config('adjustment.min_note_length', 20);
            if (strlen($data['reason_note']) < $minLength) {
                throw new Exception("Category '{$category->name}' requires detailed notes (minimum {$minLength} characters)");
            }
        }

        // Check for supporting data requirement
        if (in_array('supporting_data', $requirements) && empty($data['supporting_data'])) {
            throw new Exception("Category '{$category->name}' requires supporting data");
        }
    }

    /**
     * Validate approver permissions
     */
    protected function validateApproverPermissions(UserAdjustment $adjustment): void
    {
        $user = auth()->user();

        // Cannot approve own adjustment
        if ($user->id === $adjustment->created_by) {
            throw new Exception("Cannot approve your own adjustment");
        }

        // Check role permissions
        if (!$user->hasRole(['owner', 'admin'])) {
            throw new Exception("Insufficient permissions to approve adjustments");
        }

        // High-risk adjustments need higher level approval
        if ($adjustment->is_high_risk && !$user->hasRole('owner')) {
            throw new Exception("High-risk adjustments require owner approval");
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get user's current balance from ledger
     */
    protected function getUserCurrentBalance(int $userId): float
    {
        return $this->ledgerService->getCurrentBalance($userId);
    }

    /**
     * Calculate new balance after adjustment
     */
    protected function calculateNewBalance(float $currentBalance, string $direction, float $amount): float
    {
        return $direction === 'credit' 
            ? $currentBalance + $amount 
            : $currentBalance - $amount;
    }

    /**
     * Determine initial status based on category and amount
     */
    protected function determineInitialStatus(AdjustmentCategory $category, float $amount): string
    {
        // High-risk categories always need approval
        if ($category->requiresManagerApproval()) {
            return 'pending_approval';
        }

        // Check auto-approval limit
        return $category->allowsAutoApproval($amount) ? 'auto_approved' : 'pending_approval';
    }

    /**
     * Check if adjustment is high risk
     */
    protected function isHighRiskAdjustment(array $data, User $user): bool
    {
        // Large amounts are high risk
        $highRiskThreshold = config('adjustment.high_risk_threshold', 500000.00); // 500k
        if ($data['amount'] > $highRiskThreshold) {
            return true;
        }

        // New users are high risk
        if ($user->created_at->diffInDays(now()) < 30) {
            return true;
        }

        // Users with recent failed adjustments
        $recentFailed = UserAdjustment::where('user_id', $user->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
            
        if ($recentFailed >= 3) {
            return true;
        }

        return false;
    }

    /**
     * Handle attachment upload
     */
    protected function handleAttachmentUpload(UploadedFile $file): string
    {
        // Validate file
        $maxSize = config('adjustment.max_attachment_size', 10240); // 10MB
        if ($file->getSize() > $maxSize * 1024) {
            throw new Exception("Attachment too large. Maximum size: {$maxSize}KB");
        }

        $allowedTypes = config('adjustment.allowed_attachment_types', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
        if (!in_array($file->getClientOriginalExtension(), $allowedTypes)) {
            throw new Exception("Invalid attachment type. Allowed: " . implode(', ', $allowedTypes));
        }

        // Store file
        $path = $file->store('adjustments/attachments/' . date('Y/m'), 'private');
        
        Log::info('Adjustment attachment uploaded', [
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'size' => $file->getSize()
        ]);

        return $path;
    }

    /**
     * Gather request metadata
     */
    protected function gatherRequestMetadata(): array
    {
        return [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
            'session_id' => session()->getId(),
            'request_id' => request()->header('X-Request-ID') ?: uniqid(),
            'forwarded_for' => request()->header('X-Forwarded-For'),
            'real_ip' => request()->header('X-Real-IP')
        ];
    }

    /**
     * Get daily adjustment limit for user
     */
    protected function getDailyAdjustmentLimit(User $user): float
    {
        // Default limits by user type/role
        if ($user->hasRole('premium')) {
            return config('adjustment.daily_limit.premium', 1000000.00); // 1M
        }
        
        return config('adjustment.daily_limit.standard', 500000.00); // 500k
    }

    /**
     * Get daily adjustment amount used
     */
    protected function getDailyAdjustmentUsed(int $userId): float
    {
        return UserAdjustment::where('user_id', $userId)
            ->whereDate('created_at', today())
            ->whereIn('status', ['processed', 'pending_approval', 'auto_approved', 'manually_approved'])
            ->sum('amount');
    }

    /**
     * Generate ledger description
     */
    protected function generateLedgerDescription(UserAdjustment $adjustment): string
    {
        $direction = ucfirst($adjustment->direction);
        $category = AdjustmentCategory::getByCode($adjustment->reason_code);
        $categoryName = $category ? $category->name : $adjustment->reason_code;
        
        return "{$direction} adjustment: {$categoryName} - {$adjustment->reason_note}";
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get adjustments for user
     */
    public function getUserAdjustments(int $userId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = UserAdjustment::forUser($userId)
            ->with(['creator', 'approver', 'processor', 'latestApproval'])
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (!empty($filters['status'])) {
            $query->status($filters['status']);
        }

        if (!empty($filters['direction'])) {
            $query->direction($filters['direction']);
        }

        if (!empty($filters['reason_code'])) {
            $query->reasonCode($filters['reason_code']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get pending adjustments for approval
     */
    public function getPendingApprovals(array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = UserAdjustment::pendingApproval()
            ->with(['user', 'creator'])
            ->orderBy('created_at');

        // High risk first
        $query->orderByDesc('is_high_risk');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get adjustment statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $days = $filters['days'] ?? 30;
        $stats = UserAdjustment::getStatistics($days);

        // Add additional metrics
        $stats['avg_processing_time'] = UserAdjustment::processed()
            ->recent($days)
            ->whereNotNull('processing_duration_minutes')
            ->avg('processing_duration_minutes');

        $stats['approval_rate'] = $stats['total_adjustments'] > 0 
            ? (($stats['total_adjustments'] - UserAdjustment::recent($days)->failed()->count()) / $stats['total_adjustments']) * 100
            : 0;

        return $stats;
    }

    /**
     * Export adjustments to CSV
     */
    public function exportToCsv(array $filters = []): string
    {
        // Implementation for CSV export
        // Similar to monthly closing export logic
        // Returns file path
    }
}