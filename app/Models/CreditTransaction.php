<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * CreditTransaction Model
 * 
 * Catatan transaksi credit balance dari refund, kompensasi, dll.
 * Terhubung ke DompetSaldo.
 */
class CreditTransaction extends Model
{
    protected $table = 'credit_transactions';

    // ==================== TYPE CONSTANTS ====================
    const TYPE_REFUND = 'refund';
    const TYPE_COMPENSATION = 'compensation';
    const TYPE_BONUS = 'bonus';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_MIGRATION = 'migration';

    protected $fillable = [
        'transaction_number',
        'klien_id',
        'dompet_saldo_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'currency',
        'reference_id',
        'reference_type',
        'description',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = [
        'type_label',
        'is_credit',
        'is_debit',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = self::generateNumber();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function dompetSaldo(): BelongsTo
    {
        return $this->belongsTo(DompetSaldo::class, 'dompet_saldo_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    // ==================== SCOPES ====================

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('amount', '<', 0);
    }

    public function scopeForPeriod(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // ==================== ACCESSORS ====================

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            self::TYPE_REFUND => 'Refund',
            self::TYPE_COMPENSATION => 'Kompensasi',
            self::TYPE_BONUS => 'Bonus',
            self::TYPE_ADJUSTMENT => 'Adjustment',
            self::TYPE_MIGRATION => 'Migrasi',
            default => $this->type,
        };
    }

    public function getIsCreditAttribute(): bool
    {
        return $this->amount > 0;
    }

    public function getIsDebitAttribute(): bool
    {
        return $this->amount < 0;
    }

    public function getAbsoluteAmountAttribute(): int
    {
        return abs($this->amount);
    }

    // ==================== HELPERS ====================

    public static function generateNumber(): string
    {
        $prefix = 'CRT';
        $yearMonth = now()->format('Ym');
        $sequence = self::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $yearMonth, $sequence);
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_REFUND => 'Refund',
            self::TYPE_COMPENSATION => 'Kompensasi',
            self::TYPE_BONUS => 'Bonus',
            self::TYPE_ADJUSTMENT => 'Adjustment',
            self::TYPE_MIGRATION => 'Migrasi',
        ];
    }

    /**
     * Create a credit transaction from a refund
     */
    public static function createFromRefund(
        RefundRequest $refund,
        DompetSaldo $dompet,
        int $approverId
    ): self {
        $balanceBefore = $dompet->saldo_tersedia;
        $amount = $refund->approved_amount ?? $refund->requested_amount;
        $balanceAfter = $balanceBefore + $amount;

        return self::create([
            'klien_id' => $refund->klien_id,
            'dompet_saldo_id' => $dompet->id,
            'type' => self::TYPE_REFUND,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference_id' => $refund->id,
            'reference_type' => RefundRequest::class,
            'description' => "Refund dari invoice #{$refund->invoice->invoice_number ?? $refund->invoice_id}",
            'approved_by' => $approverId,
            'approved_at' => now(),
            'metadata' => [
                'refund_number' => $refund->refund_number,
                'invoice_id' => $refund->invoice_id,
                'reason' => $refund->reason,
            ],
        ]);
    }

    /**
     * Create a credit transaction from dispute compensation
     */
    public static function createFromDispute(
        DisputeRequest $dispute,
        DompetSaldo $dompet,
        int $amount,
        int $approverId
    ): self {
        $balanceBefore = $dompet->saldo_tersedia;
        $balanceAfter = $balanceBefore + $amount;

        return self::create([
            'klien_id' => $dispute->klien_id,
            'dompet_saldo_id' => $dompet->id,
            'type' => self::TYPE_COMPENSATION,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference_id' => $dispute->id,
            'reference_type' => DisputeRequest::class,
            'description' => "Kompensasi dari dispute #{$dispute->dispute_number}",
            'approved_by' => $approverId,
            'approved_at' => now(),
            'metadata' => [
                'dispute_number' => $dispute->dispute_number,
                'resolution_type' => $dispute->resolution_type,
            ],
        ]);
    }

    /**
     * Get summary for a klien
     */
    public static function getSummary(int $klienId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = self::forKlien($klienId);

        if ($startDate && $endDate) {
            $query->forPeriod($startDate, $endDate);
        }

        $totalCredits = (clone $query)->credits()->sum('amount');
        $totalDebits = abs((clone $query)->debits()->sum('amount'));

        $byType = (clone $query)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->toArray();

        return [
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'net' => $totalCredits - $totalDebits,
            'by_type' => $byType,
        ];
    }
}
