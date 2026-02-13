<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\ApprovalLog;
use App\Models\BusinessType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ApprovalService - Risk-Based Approval Management
 * 
 * PURPOSE:
 * - Manage klien approval workflow
 * - Approve/Reject/Suspend business profiles
 * - Audit trail for all approval actions
 * - Auto-set default status based on business type risk
 */
class ApprovalService
{
    /**
     * Approve a klien for message sending.
     * 
     * @param Klien $klien
     * @param int $adminId
     * @param string|null $reason
     * @param array $metadata
     * @return bool
     */
    public function approve(
        Klien $klien, 
        int $adminId, 
        ?string $reason = null,
        array $metadata = []
    ): bool {
        return DB::transaction(function () use ($klien, $adminId, $reason, $metadata) {
            $previousStatus = $klien->approval_status;

            // Update klien approval status
            $klien->update([
                'approval_status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'approval_notes' => $reason,
            ]);

            // Create audit log
            ApprovalLog::createLog(
                $klien,
                ApprovalLog::ACTION_APPROVE,
                'approved',
                $adminId,
                ApprovalLog::ACTOR_OWNER,
                $reason,
                $metadata
            );

            Log::info('Klien approved for message sending', [
                'klien_id' => $klien->id,
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'previous_status' => $previousStatus,
                'approved_by' => $adminId,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Reject a klien application.
     * 
     * @param Klien $klien
     * @param int $adminId
     * @param string $reason Required for rejection
     * @param array $metadata
     * @return bool
     */
    public function reject(
        Klien $klien, 
        int $adminId, 
        string $reason,
        array $metadata = []
    ): bool {
        if (empty($reason)) {
            throw new \InvalidArgumentException('Rejection reason is required');
        }

        return DB::transaction(function () use ($klien, $adminId, $reason, $metadata) {
            $previousStatus = $klien->approval_status;

            // Update klien approval status
            $klien->update([
                'approval_status' => 'rejected',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'approval_notes' => $reason,
                'status' => 'nonaktif', // Also deactivate the klien
            ]);

            // Create audit log
            ApprovalLog::createLog(
                $klien,
                ApprovalLog::ACTION_REJECT,
                'rejected',
                $adminId,
                ApprovalLog::ACTOR_OWNER,
                $reason,
                $metadata
            );

            Log::warning('Klien application rejected', [
                'klien_id' => $klien->id,
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'previous_status' => $previousStatus,
                'rejected_by' => $adminId,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Suspend a klien (temporary block).
     * 
     * @param Klien $klien
     * @param int $adminId
     * @param string $reason Required for suspension
     * @param array $metadata
     * @return bool
     */
    public function suspend(
        Klien $klien, 
        int $adminId, 
        string $reason,
        array $metadata = []
    ): bool {
        if (empty($reason)) {
            throw new \InvalidArgumentException('Suspension reason is required');
        }

        return DB::transaction(function () use ($klien, $adminId, $reason, $metadata) {
            $previousStatus = $klien->approval_status;

            // Update klien approval status
            $klien->update([
                'approval_status' => 'suspended',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'approval_notes' => $reason,
            ]);

            // Create audit log
            ApprovalLog::createLog(
                $klien,
                ApprovalLog::ACTION_SUSPEND,
                'suspended',
                $adminId,
                ApprovalLog::ACTOR_OWNER,
                $reason,
                $metadata
            );

            Log::warning('Klien suspended', [
                'klien_id' => $klien->id,
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'previous_status' => $previousStatus,
                'suspended_by' => $adminId,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Reactivate a suspended klien.
     * 
     * @param Klien $klien
     * @param int $adminId
     * @param string|null $reason
     * @param array $metadata
     * @return bool
     */
    public function reactivate(
        Klien $klien, 
        int $adminId, 
        ?string $reason = null,
        array $metadata = []
    ): bool {
        return DB::transaction(function () use ($klien, $adminId, $reason, $metadata) {
            $previousStatus = $klien->approval_status;

            // Update klien approval status
            $klien->update([
                'approval_status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now(),
                'approval_notes' => $reason,
                'status' => 'aktif',
            ]);

            // Create audit log
            ApprovalLog::createLog(
                $klien,
                ApprovalLog::ACTION_REACTIVATE,
                'approved',
                $adminId,
                ApprovalLog::ACTOR_OWNER,
                $reason,
                $metadata
            );

            Log::info('Klien reactivated', [
                'klien_id' => $klien->id,
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'previous_status' => $previousStatus,
                'reactivated_by' => $adminId,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Get approval history for a klien.
     * 
     * @param Klien $klien
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getApprovalHistory(Klien $klien, int $limit = 50)
    {
        return ApprovalLog::getHistory($klien->id, $limit);
    }

    /**
     * Get kliens pending approval.
     * 
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingApprovals(int $limit = 100)
    {
        return Klien::where('approval_status', 'pending')
            ->where('status', 'aktif')
            ->with('user:id,name,email')
            ->orderBy('tanggal_bergabung', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get default approval status based on business type risk level.
     * 
     * @param string $businessTypeCode
     * @return string pending|approved
     */
    public function getDefaultApprovalStatus(string $businessTypeCode): string
    {
        $businessType = BusinessType::where('code', $businessTypeCode)->first();

        if (!$businessType) {
            // Default to pending for unknown business types (safe)
            return 'pending';
        }

        // High risk requires manual approval
        if ($businessType->risk_level === 'high') {
            return 'pending';
        }

        // Low and medium risk auto-approved
        return 'approved';
    }

    /**
     * Check if klien can send messages.
     * 
     * @param Klien $klien
     * @return array [allowed: bool, reason: string|null]
     */
    public function canSendMessages(Klien $klien): array
    {
        // Check approval status
        if ($klien->approval_status !== 'approved') {
            return [
                'allowed' => false,
                'reason' => $this->getBlockReasonMessage($klien->approval_status),
                'status' => $klien->approval_status,
            ];
        }

        // Check klien active status
        if ($klien->status !== 'aktif') {
            return [
                'allowed' => false,
                'reason' => 'Business profile is not active',
                'status' => $klien->status,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'status' => 'approved',
        ];
    }

    /**
     * Get user-friendly block reason message.
     * 
     * @param string $approvalStatus
     * @return string
     */
    protected function getBlockReasonMessage(string $approvalStatus): string
    {
        return match($approvalStatus) {
            'pending' => 'Your business profile is pending approval. Please wait for owner verification.',
            'rejected' => 'Your business application has been rejected. Please contact support for more information.',
            'suspended' => 'Your account has been temporarily suspended. Please contact support to resolve this issue.',
            default => 'Your account is not approved for message sending.',
        };
    }

    /**
     * Get approval statistics for dashboard.
     * 
     * @param string|null $period
     * @return array
     */
    public function getApprovalStatistics(?string $period = '30d'): array
    {
        $query = Klien::query();

        // Status distribution
        $statusDistribution = Klien::groupBy('approval_status')
            ->selectRaw('approval_status, count(*) as count')
            ->pluck('count', 'approval_status')
            ->toArray();

        // Recent logs statistics
        $logStats = ApprovalLog::getStatistics($period);

        // Pending count by business type
        $pendingByType = Klien::where('approval_status', 'pending')
            ->groupBy('tipe_bisnis')
            ->selectRaw('tipe_bisnis, count(*) as count')
            ->pluck('count', 'tipe_bisnis')
            ->toArray();

        return [
            'status_distribution' => $statusDistribution,
            'recent_actions' => $logStats,
            'pending_by_business_type' => $pendingByType,
            'total_pending' => $statusDistribution['pending'] ?? 0,
            'total_approved' => $statusDistribution['approved'] ?? 0,
            'total_rejected' => $statusDistribution['rejected'] ?? 0,
            'total_suspended' => $statusDistribution['suspended'] ?? 0,
        ];
    }

    /**
     * Bulk approve multiple kliens.
     * 
     * @param array $klienIds
     * @param int $adminId
     * @param string|null $reason
     * @return int Count of approved kliens
     */
    public function bulkApprove(array $klienIds, int $adminId, ?string $reason = null): int
    {
        $count = 0;

        foreach ($klienIds as $klienId) {
            $klien = Klien::find($klienId);
            if ($klien && $klien->approval_status === 'pending') {
                $this->approve($klien, $adminId, $reason);
                $count++;
            }
        }

        return $count;
    }
}
