<?php

namespace App\Services;

use App\Models\RefundRequest;
use App\Models\RefundEvent;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\DompetSaldo;
use App\Models\TransaksiSaldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RefundService
 * 
 * Service untuk mengelola refund request.
 * 
 * PRINCIPLES:
 * - Invoice tetap SSOT
 * - Semua refund melalui approval Owner
 * - Tidak ada auto refund
 * - Full audit trail
 * - Credit balance sebagai opsi utama
 */
class RefundService
{
    /**
     * Submit refund request (by Client)
     */
    public function submitRequest(array $data, int $klienId): RefundRequest
    {
        return DB::transaction(function () use ($data, $klienId) {
            $invoice = Invoice::findOrFail($data['invoice_id']);

            // Validate invoice is refundable
            if ($invoice->status !== Invoice::STATUS_PAID) {
                throw new \InvalidArgumentException('Invoice harus berstatus PAID untuk bisa direfund');
            }

            if ($invoice->klien_id !== $klienId) {
                throw new \InvalidArgumentException('Invoice bukan milik klien ini');
            }

            // Check for existing pending refund on same invoice
            $existingPending = RefundRequest::forInvoice($invoice->id)
                ->pending()
                ->exists();

            if ($existingPending) {
                throw new \InvalidArgumentException('Sudah ada refund request pending untuk invoice ini');
            }

            // Validate requested amount
            $maxRefundable = $this->calculateMaxRefundable($invoice);
            $requestedAmount = $data['requested_amount'] ?? $invoice->total;

            if ($requestedAmount > $maxRefundable) {
                throw new \InvalidArgumentException(
                    "Jumlah maksimum yang bisa direfund adalah Rp " . number_format($maxRefundable)
                );
            }

            // Create refund request
            $refund = RefundRequest::create([
                'invoice_id' => $invoice->id,
                'klien_id' => $klienId,
                'subscription_id' => $invoice->invoiceable_id && $invoice->invoiceable_type === 'App\\Models\\Subscription' 
                    ? $invoice->invoiceable_id 
                    : null,
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'requested_amount' => $requestedAmount,
                'currency' => $invoice->currency,
                'refund_method' => $data['refund_method'] ?? RefundRequest::METHOD_CREDIT_BALANCE,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'bank_account_name' => $data['bank_account_name'] ?? null,
                'status' => RefundRequest::STATUS_PENDING,
                'invoice_snapshot' => $invoice->toArray(),
            ]);

            // Log event
            RefundEvent::logCreated($refund, RefundEvent::ACTOR_CLIENT, $klienId);

            Log::info('Refund request submitted', [
                'refund_id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'invoice_id' => $invoice->id,
                'klien_id' => $klienId,
                'requested_amount' => $requestedAmount,
            ]);

            return $refund;
        });
    }

    /**
     * Review refund request (by Owner)
     */
    public function startReview(RefundRequest $refund, int $ownerId): RefundRequest
    {
        return DB::transaction(function () use ($refund, $ownerId) {
            if ($refund->status !== RefundRequest::STATUS_PENDING) {
                throw new \InvalidArgumentException('Refund harus berstatus PENDING untuk bisa direview');
            }

            $oldStatus = $refund->status;
            $refund->status = RefundRequest::STATUS_UNDER_REVIEW;
            $refund->reviewed_by = $ownerId;
            $refund->save();

            RefundEvent::logStatusChange(
                $refund,
                $oldStatus,
                RefundRequest::STATUS_UNDER_REVIEW,
                RefundEvent::ACTOR_OWNER,
                $ownerId,
                'Owner mulai mereview refund request'
            );

            return $refund;
        });
    }

    /**
     * Approve refund request (by Owner)
     */
    public function approve(
        RefundRequest $refund,
        int $ownerId,
        ?int $approvedAmount = null,
        ?string $notes = null
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $ownerId, $approvedAmount, $notes) {
            if (!in_array($refund->status, [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_UNDER_REVIEW])) {
                throw new \InvalidArgumentException('Refund harus berstatus PENDING atau UNDER_REVIEW untuk bisa diapprove');
            }

            $approvedAmount = $approvedAmount ?? $refund->requested_amount;

            // Validate approved amount
            $maxRefundable = $this->calculateMaxRefundable($refund->invoice);
            if ($approvedAmount > $maxRefundable) {
                throw new \InvalidArgumentException(
                    "Jumlah maksimum yang bisa direfund adalah Rp " . number_format($maxRefundable)
                );
            }

            $oldStatus = $refund->status;
            $refund->status = RefundRequest::STATUS_APPROVED;
            $refund->approved_amount = $approvedAmount;
            $refund->reviewed_by = $ownerId;
            $refund->reviewed_at = now();
            $refund->review_notes = $notes;
            $refund->save();

            RefundEvent::logApproval($refund, $ownerId, $approvedAmount, $notes);

            Log::info('Refund request approved', [
                'refund_id' => $refund->id,
                'approved_amount' => $approvedAmount,
                'owner_id' => $ownerId,
            ]);

            return $refund;
        });
    }

    /**
     * Reject refund request (by Owner)
     */
    public function reject(
        RefundRequest $refund,
        int $ownerId,
        string $reason
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $ownerId, $reason) {
            if (!in_array($refund->status, [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_UNDER_REVIEW])) {
                throw new \InvalidArgumentException('Refund harus berstatus PENDING atau UNDER_REVIEW untuk bisa direject');
            }

            $oldStatus = $refund->status;
            $refund->status = RefundRequest::STATUS_REJECTED;
            $refund->reviewed_by = $ownerId;
            $refund->reviewed_at = now();
            $refund->rejection_reason = $reason;
            $refund->save();

            RefundEvent::logRejection($refund, $ownerId, $reason);

            Log::info('Refund request rejected', [
                'refund_id' => $refund->id,
                'reason' => $reason,
                'owner_id' => $ownerId,
            ]);

            return $refund;
        });
    }

    /**
     * Process approved refund (by Owner)
     * This actually transfers the money/credit
     */
    public function process(RefundRequest $refund, int $ownerId): RefundRequest
    {
        return DB::transaction(function () use ($refund, $ownerId) {
            if ($refund->status !== RefundRequest::STATUS_APPROVED) {
                throw new \InvalidArgumentException('Refund harus berstatus APPROVED untuk bisa diproses');
            }

            $refund->status = RefundRequest::STATUS_PROCESSING;
            $refund->processed_by = $ownerId;
            $refund->save();

            RefundEvent::logStatusChange(
                $refund,
                RefundRequest::STATUS_APPROVED,
                RefundRequest::STATUS_PROCESSING,
                RefundEvent::ACTOR_OWNER,
                $ownerId,
                'Proses refund dimulai'
            );

            // Process based on refund method
            $transactionReference = match($refund->refund_method) {
                RefundRequest::METHOD_CREDIT_BALANCE => $this->processCreditBalance($refund, $ownerId),
                RefundRequest::METHOD_BANK_TRANSFER => $this->processBankTransfer($refund, $ownerId),
                RefundRequest::METHOD_ORIGINAL_METHOD => $this->processOriginalMethod($refund, $ownerId),
                default => throw new \InvalidArgumentException("Unknown refund method: {$refund->refund_method}"),
            };

            // Mark as completed
            $refund->status = RefundRequest::STATUS_COMPLETED;
            $refund->processed_at = now();
            $refund->transaction_reference = $transactionReference;
            $refund->save();

            // Update invoice status if fully refunded
            $this->updateInvoiceStatus($refund);

            RefundEvent::logCompletion($refund, $ownerId, $transactionReference);

            Log::info('Refund processed', [
                'refund_id' => $refund->id,
                'method' => $refund->refund_method,
                'amount' => $refund->approved_amount,
                'transaction_reference' => $transactionReference,
            ]);

            return $refund;
        });
    }

    /**
     * Cancel refund request (by Client, only if pending)
     */
    public function cancel(RefundRequest $refund, int $klienId, ?string $reason = null): RefundRequest
    {
        return DB::transaction(function () use ($refund, $klienId, $reason) {
            if ($refund->status !== RefundRequest::STATUS_PENDING) {
                throw new \InvalidArgumentException('Hanya refund dengan status PENDING yang bisa dibatalkan');
            }

            if ($refund->klien_id !== $klienId) {
                throw new \InvalidArgumentException('Tidak diizinkan membatalkan refund ini');
            }

            $refund->status = RefundRequest::STATUS_CANCELLED;
            $refund->save();

            RefundEvent::logCancellation($refund, RefundEvent::ACTOR_CLIENT, $klienId, $reason);

            Log::info('Refund request cancelled by client', [
                'refund_id' => $refund->id,
                'klien_id' => $klienId,
            ]);

            return $refund;
        });
    }

    /**
     * Add note to refund (by Owner)
     */
    public function addNote(RefundRequest $refund, int $ownerId, string $note): RefundEvent
    {
        return RefundEvent::logNote($refund, RefundEvent::ACTOR_OWNER, $ownerId, $note);
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Process refund to credit balance
     */
    private function processCreditBalance(RefundRequest $refund, int $ownerId): string
    {
        $dompet = DompetSaldo::where('klien_id', $refund->klien_id)->first();

        if (!$dompet) {
            // Create dompet if not exists
            $dompet = DompetSaldo::create([
                'klien_id' => $refund->klien_id,
                'saldo_tersedia' => 0,
                'saldo_tertahan' => 0,
                'batas_warning' => 100000,
                'batas_minimum' => 0,
                'total_topup' => 0,
                'total_terpakai' => 0,
            ]);
        }

        // Create credit transaction
        $creditTx = CreditTransaction::createFromRefund($refund, $dompet, $ownerId);

        // Update dompet balance
        $dompet->saldo_tersedia += $refund->approved_amount;
        $dompet->save();

        // Also record in TransaksiSaldo for consistency
        TransaksiSaldo::create([
            'dompet_id' => $dompet->id,
            'klien_id' => $refund->klien_id,
            'jenis' => 'masuk',
            'nominal' => $refund->approved_amount,
            'saldo_sebelum' => $creditTx->balance_before,
            'saldo_sesudah' => $creditTx->balance_after,
            'keterangan' => 'Refund dari invoice #' . ($refund->invoice->invoice_number ?? $refund->invoice_id),
            'referensi_id' => $refund->id,
            'referensi_tipe' => RefundRequest::class,
        ]);

        return $creditTx->transaction_number;
    }

    /**
     * Process refund via bank transfer
     * Note: This is a placeholder - actual bank transfer requires integration
     */
    private function processBankTransfer(RefundRequest $refund, int $ownerId): string
    {
        // In a real implementation, this would integrate with payment gateway
        // For now, we just generate a reference and log it
        $reference = 'TRF-' . now()->format('YmdHis') . '-' . $refund->id;

        Log::info('Bank transfer refund initiated', [
            'refund_id' => $refund->id,
            'bank_name' => $refund->bank_name,
            'account_number' => $refund->bank_account_number,
            'account_name' => $refund->bank_account_name,
            'amount' => $refund->approved_amount,
            'reference' => $reference,
        ]);

        return $reference;
    }

    /**
     * Process refund to original payment method
     * Note: This is a placeholder - requires payment gateway integration
     */
    private function processOriginalMethod(RefundRequest $refund, int $ownerId): string
    {
        $invoice = $refund->invoice;
        $reference = 'OPM-' . now()->format('YmdHis') . '-' . $refund->id;

        Log::info('Original method refund initiated', [
            'refund_id' => $refund->id,
            'original_method' => $invoice->payment_method,
            'original_channel' => $invoice->payment_channel,
            'amount' => $refund->approved_amount,
            'reference' => $reference,
        ]);

        return $reference;
    }

    /**
     * Calculate maximum refundable amount for an invoice
     */
    public function calculateMaxRefundable(Invoice $invoice): int
    {
        $totalPaid = $invoice->total;

        // Subtract already refunded amounts
        $alreadyRefunded = RefundRequest::forInvoice($invoice->id)
            ->completed()
            ->sum('approved_amount');

        return max(0, $totalPaid - $alreadyRefunded);
    }

    /**
     * Update invoice status after refund
     */
    private function updateInvoiceStatus(RefundRequest $refund): void
    {
        $invoice = $refund->invoice;
        $totalRefunded = RefundRequest::forInvoice($invoice->id)
            ->completed()
            ->sum('approved_amount');

        if ($totalRefunded >= $invoice->total) {
            $invoice->status = Invoice::STATUS_REFUNDED;
            $invoice->save();
        }
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get refund list with filters
     */
    public function getList(array $filters = [], int $perPage = 15): array
    {
        $query = RefundRequest::with(['invoice', 'klien', 'reviewer']);

        if (!empty($filters['klien_id'])) {
            $query->forKlien($filters['klien_id']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->forInvoice($filters['invoice_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        if (!empty($filters['needs_review'])) {
            $query->needsReview();
        }

        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortField, $sortDir);

        $paginated = $query->paginate($perPage);

        return [
            'data' => collect($paginated->items())->map(fn($r) => $this->formatRefund($r))->toArray(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * Get refund detail with timeline
     */
    public function getDetail(RefundRequest $refund): array
    {
        $refund->load(['invoice', 'klien', 'reviewer', 'processor', 'events']);

        return [
            'refund' => $this->formatRefund($refund),
            'timeline' => $refund->events->map(fn($e) => [
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
     * Format refund for API response
     */
    private function formatRefund(RefundRequest $refund): array
    {
        return [
            'id' => $refund->id,
            'refund_number' => $refund->refund_number,
            'invoice_id' => $refund->invoice_id,
            'invoice_number' => $refund->invoice->invoice_number ?? null,
            'klien_id' => $refund->klien_id,
            'klien_name' => $refund->klien->nama ?? null,
            'reason' => $refund->reason,
            'reason_label' => $refund->reason_label,
            'description' => $refund->description,
            'requested_amount' => $refund->requested_amount,
            'approved_amount' => $refund->approved_amount,
            'currency' => $refund->currency,
            'refund_method' => $refund->refund_method,
            'method_label' => $refund->method_label,
            'status' => $refund->status,
            'status_label' => $refund->status_label,
            'is_pending' => $refund->is_pending,
            'can_cancel' => $refund->can_cancel,
            'reviewed_by' => $refund->reviewed_by,
            'reviewer_name' => $refund->reviewer->name ?? null,
            'reviewed_at' => $refund->reviewed_at?->toIso8601String(),
            'review_notes' => $refund->review_notes,
            'rejection_reason' => $refund->rejection_reason,
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'transaction_reference' => $refund->transaction_reference,
            'created_at' => $refund->created_at->toIso8601String(),
            'updated_at' => $refund->updated_at->toIso8601String(),
        ];
    }

    /**
     * Get dashboard statistics
     */
    public function getStats(): array
    {
        return [
            'pending_count' => RefundRequest::pending()->count(),
            'pending_amount' => RefundRequest::pending()->sum('requested_amount'),
            'completed_this_month' => RefundRequest::completed()
                ->whereMonth('processed_at', now()->month)
                ->whereYear('processed_at', now()->year)
                ->count(),
            'completed_amount_this_month' => RefundRequest::completed()
                ->whereMonth('processed_at', now()->month)
                ->whereYear('processed_at', now()->year)
                ->sum('approved_amount'),
            'by_status' => RefundRequest::selectRaw('status, count(*) as count, SUM(requested_amount) as total_amount')
                ->groupBy('status')
                ->get()
                ->keyBy('status')
                ->toArray(),
        ];
    }
}
