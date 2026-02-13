<?php

namespace App\Services;

use App\Models\DisputeRequest;
use App\Models\DisputeEvent;
use App\Models\CreditTransaction;
use App\Models\DompetSaldo;
use App\Models\TransaksiSaldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DisputeService
 * 
 * Service untuk mengelola dispute/sengketa.
 * 
 * PRINCIPLES:
 * - Semua dispute melalui investigasi Owner
 * - Full audit trail
 * - Credit balance sebagai opsi kompensasi
 * - Data integrity & compliance
 */
class DisputeService
{
    /**
     * Submit dispute (by Client)
     */
    public function submit(array $data, int $klienId): DisputeRequest
    {
        return DB::transaction(function () use ($data, $klienId) {
            $dispute = DisputeRequest::create([
                'klien_id' => $klienId,
                'invoice_id' => $data['invoice_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'type' => $data['type'],
                'priority' => $data['priority'] ?? DisputeRequest::PRIORITY_MEDIUM,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'evidence' => $data['evidence'] ?? null,
                'disputed_amount' => $data['disputed_amount'] ?? null,
                'currency' => $data['currency'] ?? 'IDR',
                'status' => DisputeRequest::STATUS_SUBMITTED,
            ]);

            DisputeEvent::logSubmitted($dispute, DisputeEvent::ACTOR_CLIENT, $klienId);

            Log::info('Dispute submitted', [
                'dispute_id' => $dispute->id,
                'dispute_number' => $dispute->dispute_number,
                'type' => $dispute->type,
                'klien_id' => $klienId,
            ]);

            return $dispute;
        });
    }

    /**
     * Acknowledge dispute (by Owner)
     */
    public function acknowledge(DisputeRequest $dispute, int $ownerId): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId) {
            if ($dispute->status !== DisputeRequest::STATUS_SUBMITTED) {
                throw new \InvalidArgumentException('Dispute harus berstatus SUBMITTED untuk di-acknowledge');
            }

            $oldStatus = $dispute->status;
            $dispute->status = DisputeRequest::STATUS_ACKNOWLEDGED;
            $dispute->acknowledged_at = now();
            $dispute->save();

            DisputeEvent::logStatusChange(
                $dispute,
                $oldStatus,
                DisputeRequest::STATUS_ACKNOWLEDGED,
                DisputeEvent::ACTOR_OWNER,
                $ownerId,
                'Dispute diterima untuk ditindaklanjuti'
            );

            return $dispute;
        });
    }

    /**
     * Assign dispute to an agent (by Owner)
     */
    public function assign(DisputeRequest $dispute, ?int $assigneeId, int $ownerId): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $assigneeId, $ownerId) {
            $oldAssignee = $dispute->assigned_to;
            $dispute->assigned_to = $assigneeId;
            $dispute->save();

            DisputeEvent::logAssignment($dispute, $oldAssignee, $assigneeId, $ownerId);

            return $dispute;
        });
    }

    /**
     * Start investigation (by Owner)
     */
    public function startInvestigation(DisputeRequest $dispute, int $ownerId): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId) {
            if (!in_array($dispute->status, [DisputeRequest::STATUS_SUBMITTED, DisputeRequest::STATUS_ACKNOWLEDGED])) {
                throw new \InvalidArgumentException('Dispute tidak dalam status yang bisa diinvestigasi');
            }

            $oldStatus = $dispute->status;
            $dispute->status = DisputeRequest::STATUS_INVESTIGATING;
            $dispute->save();

            DisputeEvent::logStatusChange(
                $dispute,
                $oldStatus,
                DisputeRequest::STATUS_INVESTIGATING,
                DisputeEvent::ACTOR_OWNER,
                $ownerId,
                'Investigasi dimulai'
            );

            return $dispute;
        });
    }

    /**
     * Request additional info from client (by Owner)
     */
    public function requestInfo(DisputeRequest $dispute, int $ownerId, string $infoRequired): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId, $infoRequired) {
            $oldStatus = $dispute->status;
            $dispute->status = DisputeRequest::STATUS_PENDING_INFO;
            $dispute->save();

            DisputeEvent::logInfoRequested($dispute, $ownerId, $infoRequired);

            return $dispute;
        });
    }

    /**
     * Provide additional info (by Client)
     */
    public function provideInfo(DisputeRequest $dispute, int $klienId, string $info, ?array $evidence = null): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $klienId, $info, $evidence) {
            if ($dispute->status !== DisputeRequest::STATUS_PENDING_INFO) {
                throw new \InvalidArgumentException('Dispute tidak dalam status PENDING_INFO');
            }

            if ($dispute->klien_id !== $klienId) {
                throw new \InvalidArgumentException('Tidak diizinkan untuk dispute ini');
            }

            // Update evidence if provided
            if ($evidence) {
                $existingEvidence = $dispute->evidence ?? [];
                $dispute->evidence = array_merge($existingEvidence, $evidence);
            }

            // Move back to investigating
            $dispute->status = DisputeRequest::STATUS_INVESTIGATING;
            $dispute->save();

            DisputeEvent::logInfoReceived($dispute, $klienId, $info);

            return $dispute;
        });
    }

    /**
     * Escalate dispute (by Owner)
     */
    public function escalate(DisputeRequest $dispute, int $ownerId, string $reason): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId, $reason) {
            $oldStatus = $dispute->status;
            $dispute->status = DisputeRequest::STATUS_ESCALATED;
            $dispute->save();

            DisputeEvent::logEscalation($dispute, $ownerId, $reason);

            return $dispute;
        });
    }

    /**
     * Resolve dispute (by Owner)
     */
    public function resolve(
        DisputeRequest $dispute,
        int $ownerId,
        string $resolutionStatus,
        string $resolutionType,
        ?int $resolvedAmount = null,
        ?string $description = null
    ): DisputeRequest {
        return DB::transaction(function () use (
            $dispute, $ownerId, $resolutionStatus, $resolutionType, $resolvedAmount, $description
        ) {
            $validStatuses = [
                DisputeRequest::STATUS_RESOLVED_FAVOR_CLIENT,
                DisputeRequest::STATUS_RESOLVED_FAVOR_OWNER,
                DisputeRequest::STATUS_RESOLVED_PARTIAL,
            ];

            if (!in_array($resolutionStatus, $validStatuses)) {
                throw new \InvalidArgumentException('Invalid resolution status');
            }

            $oldStatus = $dispute->status;
            $dispute->status = $resolutionStatus;
            $dispute->resolution_type = $resolutionType;
            $dispute->resolution_description = $description;
            $dispute->resolved_amount = $resolvedAmount;
            $dispute->resolved_by = $ownerId;
            $dispute->resolved_at = now();

            // Set impact analysis
            $dispute->impact_analysis = $this->calculateImpact($dispute, $resolvedAmount);
            $dispute->save();

            DisputeEvent::logResolution($dispute, $ownerId, $resolutionType, $resolvedAmount);

            // Process compensation if applicable
            if ($resolvedAmount && $resolvedAmount > 0) {
                $this->processCompensation($dispute, $ownerId, $resolvedAmount);
            }

            Log::info('Dispute resolved', [
                'dispute_id' => $dispute->id,
                'status' => $resolutionStatus,
                'resolution_type' => $resolutionType,
                'resolved_amount' => $resolvedAmount,
            ]);

            return $dispute;
        });
    }

    /**
     * Reject dispute (by Owner)
     */
    public function reject(DisputeRequest $dispute, int $ownerId, string $reason): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId, $reason) {
            $oldStatus = $dispute->status;
            $dispute->status = DisputeRequest::STATUS_REJECTED;
            $dispute->resolution_type = DisputeRequest::RESOLUTION_NO_ACTION;
            $dispute->resolution_description = $reason;
            $dispute->resolved_by = $ownerId;
            $dispute->resolved_at = now();
            $dispute->save();

            DisputeEvent::logStatusChange(
                $dispute,
                $oldStatus,
                DisputeRequest::STATUS_REJECTED,
                DisputeEvent::ACTOR_OWNER,
                $ownerId,
                "Dispute ditolak: {$reason}"
            );

            return $dispute;
        });
    }

    /**
     * Close dispute (by Owner)
     */
    public function close(DisputeRequest $dispute, int $ownerId): DisputeRequest
    {
        return DB::transaction(function () use ($dispute, $ownerId) {
            $resolutionStatuses = [
                DisputeRequest::STATUS_RESOLVED_FAVOR_CLIENT,
                DisputeRequest::STATUS_RESOLVED_FAVOR_OWNER,
                DisputeRequest::STATUS_RESOLVED_PARTIAL,
                DisputeRequest::STATUS_REJECTED,
            ];

            if (!in_array($dispute->status, $resolutionStatuses)) {
                throw new \InvalidArgumentException('Dispute harus sudah resolved untuk bisa di-close');
            }

            $dispute->status = DisputeRequest::STATUS_CLOSED;
            $dispute->closed_at = now();
            $dispute->save();

            DisputeEvent::logStatusChange(
                $dispute,
                $dispute->getOriginal('status'),
                DisputeRequest::STATUS_CLOSED,
                DisputeEvent::ACTOR_OWNER,
                $ownerId,
                'Dispute ditutup'
            );

            return $dispute;
        });
    }

    /**
     * Add note to dispute
     */
    public function addNote(
        DisputeRequest $dispute,
        string $actorType,
        ?int $actorId,
        string $note
    ): DisputeEvent {
        return DisputeEvent::logNote($dispute, $actorType, $actorId, $note);
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Process compensation (credit to balance)
     */
    private function processCompensation(
        DisputeRequest $dispute,
        int $ownerId,
        int $amount
    ): ?CreditTransaction {
        $dompet = DompetSaldo::where('klien_id', $dispute->klien_id)->first();

        if (!$dompet) {
            $dompet = DompetSaldo::create([
                'klien_id' => $dispute->klien_id,
                'saldo_tersedia' => 0,
                'saldo_tertahan' => 0,
                'batas_warning' => 100000,
                'batas_minimum' => 0,
                'total_topup' => 0,
                'total_terpakai' => 0,
            ]);
        }

        // Create credit transaction
        $creditTx = CreditTransaction::createFromDispute($dispute, $dompet, $amount, $ownerId);

        // Update dompet balance
        $dompet->saldo_tersedia += $amount;
        $dompet->save();

        // Also record in TransaksiSaldo
        TransaksiSaldo::create([
            'dompet_id' => $dompet->id,
            'klien_id' => $dispute->klien_id,
            'jenis' => 'masuk',
            'nominal' => $amount,
            'saldo_sebelum' => $creditTx->balance_before,
            'saldo_sesudah' => $creditTx->balance_after,
            'keterangan' => "Kompensasi dari dispute #{$dispute->dispute_number}",
            'referensi_id' => $dispute->id,
            'referensi_tipe' => DisputeRequest::class,
        ]);

        return $creditTx;
    }

    /**
     * Calculate impact analysis
     */
    private function calculateImpact(DisputeRequest $dispute, ?int $resolvedAmount): array
    {
        return [
            'disputed_amount' => $dispute->disputed_amount,
            'resolved_amount' => $resolvedAmount,
            'impact_percentage' => $dispute->disputed_amount > 0
                ? round(($resolvedAmount ?? 0) / $dispute->disputed_amount * 100, 2)
                : 0,
            'resolution_date' => now()->toIso8601String(),
            'days_to_resolve' => $dispute->created_at->diffInDays(now()),
        ];
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get dispute list with filters
     */
    public function getList(array $filters = [], int $perPage = 15): array
    {
        $query = DisputeRequest::with(['klien', 'invoice', 'assignee']);

        if (!empty($filters['klien_id'])) {
            $query->forKlien($filters['klien_id']);
        }

        if (!empty($filters['type'])) {
            $query->byType($filters['type']);
        }

        if (!empty($filters['priority'])) {
            $query->byPriority($filters['priority']);
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'open') {
                $query->open();
            } elseif ($filters['status'] === 'closed') {
                $query->closed();
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (!empty($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned') {
                $query->unassigned();
            } else {
                $query->where('assigned_to', $filters['assigned_to']);
            }
        }

        if (!empty($filters['needs_attention'])) {
            $query->needsAttention();
        }

        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortField, $sortDir);

        $paginated = $query->paginate($perPage);

        return [
            'data' => collect($paginated->items())->map(fn($d) => $this->formatDispute($d))->toArray(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * Get dispute detail with timeline
     */
    public function getDetail(DisputeRequest $dispute): array
    {
        $dispute->load(['klien', 'invoice', 'subscription', 'assignee', 'resolver', 'events']);

        return [
            'dispute' => $this->formatDispute($dispute),
            'timeline' => $dispute->events->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->event_type,
                'type_label' => $e->event_type_label,
                'old_value' => $e->old_value,
                'new_value' => $e->new_value,
                'comment' => $e->comment,
                'actor_type' => $e->actor_type,
                'actor_name' => $e->actor_name,
                'metadata' => $e->metadata,
                'created_at' => $e->created_at->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Format dispute for API response
     */
    private function formatDispute(DisputeRequest $dispute): array
    {
        return [
            'id' => $dispute->id,
            'dispute_number' => $dispute->dispute_number,
            'klien_id' => $dispute->klien_id,
            'klien_name' => $dispute->klien->nama ?? null,
            'invoice_id' => $dispute->invoice_id,
            'invoice_number' => $dispute->invoice->invoice_number ?? null,
            'type' => $dispute->type,
            'type_label' => $dispute->type_label,
            'priority' => $dispute->priority,
            'priority_label' => $dispute->priority_label,
            'subject' => $dispute->subject,
            'description' => $dispute->description,
            'evidence' => $dispute->evidence,
            'disputed_amount' => $dispute->disputed_amount,
            'resolved_amount' => $dispute->resolved_amount,
            'currency' => $dispute->currency,
            'status' => $dispute->status,
            'status_label' => $dispute->status_label,
            'is_open' => $dispute->is_open,
            'resolution_type' => $dispute->resolution_type,
            'resolution_label' => $dispute->resolution_label,
            'resolution_description' => $dispute->resolution_description,
            'assigned_to' => $dispute->assigned_to,
            'assignee_name' => $dispute->assignee->name ?? null,
            'resolved_by' => $dispute->resolved_by,
            'resolver_name' => $dispute->resolver->name ?? null,
            'acknowledged_at' => $dispute->acknowledged_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'closed_at' => $dispute->closed_at?->toIso8601String(),
            'impact_analysis' => $dispute->impact_analysis,
            'created_at' => $dispute->created_at->toIso8601String(),
            'updated_at' => $dispute->updated_at->toIso8601String(),
        ];
    }

    /**
     * Get dashboard statistics
     */
    public function getStats(): array
    {
        return [
            'open_count' => DisputeRequest::open()->count(),
            'needs_attention' => DisputeRequest::needsAttention()->count(),
            'unassigned' => DisputeRequest::open()->unassigned()->count(),
            'by_type' => DisputeRequest::open()
                ->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'by_priority' => DisputeRequest::open()
                ->selectRaw('priority, count(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray(),
            'by_status' => DisputeRequest::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'avg_resolution_days' => DisputeRequest::closed()
                ->whereNotNull('resolved_at')
                ->selectRaw('AVG(DATEDIFF(resolved_at, created_at)) as avg_days')
                ->value('avg_days') ?? 0,
        ];
    }
}
