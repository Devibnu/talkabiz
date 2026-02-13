<?php

namespace App\Services\Message;

class MessageDispatchResult
{
    public readonly bool $success;
    public readonly int $totalSent;
    public readonly int $totalFailed;
    public readonly int $totalCost;
    public readonly int $balanceAfter;
    public readonly string $transactionCode;
    public readonly array $sentResults;
    public readonly array $metadata;

    public function __construct(
        bool $success,
        int $totalSent,
        int $totalFailed,
        int $totalCost,
        int $balanceAfter,
        string $transactionCode,
        array $sentResults = [],
        array $metadata = []
    ) {
        $this->success = $success;
        $this->totalSent = $totalSent;
        $this->totalFailed = $totalFailed;
        $this->totalCost = $totalCost;
        $this->balanceAfter = $balanceAfter;
        $this->transactionCode = $transactionCode;
        $this->sentResults = $sentResults;
        $this->metadata = $metadata;
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRate(): float
    {
        $total = $this->totalSent + $this->totalFailed;
        return $total > 0 ? ($this->totalSent / $total) * 100 : 0;
    }

    /**
     * Get total recipients processed
     */
    public function getTotalRecipients(): int
    {
        return $this->totalSent + $this->totalFailed;
    }

    /**
     * Check if partially successful (some sent, some failed)
     */
    public function isPartialSuccess(): bool
    {
        return $this->totalSent > 0 && $this->totalFailed > 0;
    }

    /**
     * Get failed recipients for retry
     */
    public function getFailedRecipients(): array
    {
        return array_filter($this->sentResults, fn($result) => $result['status'] === 'failed');
    }

    /**
     * Get successful sends
     */
    public function getSuccessfulSends(): array
    {
        return array_filter($this->sentResults, fn($result) => $result['status'] === 'sent');
    }

    /**
     * Convert to API response format
     */
    public function toApiResponse(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->generateMessage(),
            'data' => [
                'total_sent' => $this->totalSent,
                'total_failed' => $this->totalFailed,
                'success_rate' => round($this->getSuccessRate(), 2),
                'total_cost' => $this->totalCost,
                'formatted_cost' => 'Rp ' . number_format($this->totalCost, 0, ',', '.'),
                'balance_after' => $this->balanceAfter,
                'formatted_balance' => 'Rp ' . number_format($this->balanceAfter, 0, ',', '.'),
                'transaction_code' => $this->transactionCode,
            ],
            'details' => [
                'sent_results' => $this->sentResults,
                'metadata' => $this->metadata,
            ]
        ];
    }

    /**
     * Convert to dashboard/UI format
     */
    public function toUiResponse(): array
    {
        $total = $this->getTotalRecipients();
        
        return [
            'success' => $this->success,
            'message' => $this->generateMessage(),
            'stats' => [
                'total_recipients' => $total,
                'sent' => $this->totalSent,
                'failed' => $this->totalFailed,
                'success_rate' => $this->getSuccessRate(),
            ],
            'cost' => [
                'total' => $this->totalCost,
                'formatted' => 'Rp ' . number_format($this->totalCost, 0, ',', '.'),
            ],
            'balance' => [
                'remaining' => $this->balanceAfter,
                'formatted' => 'Rp ' . number_format($this->balanceAfter, 0, ',', '.'),
            ],
            'transaction_code' => $this->transactionCode,
            'is_partial' => $this->isPartialSuccess(),
        ];
    }

    /**
     * Convert to queue job result format
     */
    public function toJobResult(): array
    {
        return [
            'success' => $this->success,
            'sent_count' => $this->totalSent,
            'failed_count' => $this->totalFailed,
            'cost' => $this->totalCost,
            'transaction_code' => $this->transactionCode,
            'failed_recipients' => $this->getFailedRecipients(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Generate contextual message
     */
    protected function generateMessage(): string
    {
        if (!$this->success && $this->totalSent === 0) {
            return 'Semua pesan gagal terkirim.';
        }

        if ($this->success && $this->totalFailed === 0) {
            return "Berhasil mengirim {$this->totalSent} pesan.";
        }

        if ($this->isPartialSuccess()) {
            return "Berhasil mengirim {$this->totalSent} pesan, {$this->totalFailed} gagal.";
        }

        return 'Status pengiriman tidak dikenali.';
    }

    /**
     * Check if should retry failed sends
     */
    public function shouldRetry(): bool
    {
        return $this->totalFailed > 0 && $this->getSuccessRate() > 50;
    }

    /**
     * Get summary for logging
     */
    public function getSummary(): string
    {
        $total = $this->getTotalRecipients();
        $rate = round($this->getSuccessRate(), 1);
        $cost = number_format($this->totalCost, 0, ',', '.');
        
        return "Message dispatch: {$this->totalSent}/{$total} sent ({$rate}%), cost Rp {$cost}, txn: {$this->transactionCode}";
    }
}