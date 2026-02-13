<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * BankStatement Model
 *
 * Mutasi bank — sumber data untuk rekonsiliasi bank.
 *
 * ATURAN:
 * - credit = dana masuk, debit = dana keluar
 * - reference = berita transfer / keterangan
 * - matched_payment_id = FK ke payments jika sudah match
 * - Data TIDAK boleh diedit setelah matched
 *
 * @property int    $id
 * @property string $bank_name
 * @property string $bank_account
 * @property string $trx_date
 * @property float  $amount
 * @property string $trx_type
 * @property string $description
 * @property string $reference
 * @property int    $matched_payment_id
 * @property string $match_status
 * @property string $import_source
 */
class BankStatement extends Model
{
    // ==================== CONSTANTS ====================

    const TRX_CREDIT = 'credit';
    const TRX_DEBIT  = 'debit';

    const MATCH_UNMATCHED = 'unmatched';
    const MATCH_MATCHED   = 'matched';
    const MATCH_PARTIAL   = 'partial';
    const MATCH_DISPUTED  = 'disputed';

    const IMPORT_MANUAL = 'manual';
    const IMPORT_CSV    = 'csv';
    const IMPORT_API    = 'api';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'bank_name',
        'bank_account',
        'trx_date',
        'amount',
        'trx_type',
        'description',
        'reference',
        'matched_payment_id',
        'match_status',
        'match_notes',
        'import_source',
        'import_batch_id',
        'imported_at',
        'imported_by',
        'statement_hash',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'trx_date'     => 'date',
        'amount'       => 'decimal:2',
        'imported_at'  => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function matchedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }

    public function importedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    // ==================== SCOPES ====================

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('trx_type', self::TRX_CREDIT);
    }

    public function scopeDebit(Builder $query): Builder
    {
        return $query->where('trx_type', self::TRX_DEBIT);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('match_status', self::MATCH_UNMATCHED);
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('match_status', self::MATCH_MATCHED);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('trx_date', $year)
                     ->whereMonth('trx_date', $month);
    }

    public function scopeForBank(Builder $query, string $bank): Builder
    {
        return $query->where('bank_name', $bank);
    }

    // ==================== ACCESSORS ====================

    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->trx_type === self::TRX_CREDIT ? '+' : '-';
        return $prefix . ' Rp ' . number_format(abs($this->amount), 0, ',', '.');
    }

    public function getMatchStatusBadgeAttribute(): string
    {
        return match ($this->match_status) {
            self::MATCH_MATCHED  => '<span class="badge bg-gradient-success badge-sm">Matched</span>',
            self::MATCH_PARTIAL  => '<span class="badge bg-gradient-warning badge-sm">Partial</span>',
            self::MATCH_DISPUTED => '<span class="badge bg-gradient-danger badge-sm">Disputed</span>',
            default              => '<span class="badge bg-gradient-secondary badge-sm">Unmatched</span>',
        };
    }

    // ==================== METHODS ====================

    /**
     * Mark as matched to a payment.
     */
    public function markMatched(int $paymentId, ?string $notes = null): self
    {
        $this->update([
            'matched_payment_id' => $paymentId,
            'match_status'       => self::MATCH_MATCHED,
            'match_notes'        => $notes,
        ]);
        return $this;
    }

    /**
     * Generate hash for integrity check.
     */
    public function generateHash(): string
    {
        return hash('sha256', json_encode([
            'bank'      => $this->bank_name,
            'date'      => $this->trx_date?->format('Y-m-d'),
            'amount'    => (string) $this->amount,
            'reference' => $this->reference,
            'type'      => $this->trx_type,
        ]));
    }

    /**
     * Is this statement already matched?
     */
    public function isMatched(): bool
    {
        return $this->match_status === self::MATCH_MATCHED;
    }
}
