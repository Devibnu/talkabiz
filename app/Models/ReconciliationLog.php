<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * ReconciliationLog Model
 *
 * Log hasil rekonsiliasi per periode per source (gateway/bank).
 *
 * ATURAN:
 * - UNIQUE per (period_year, period_month, source)
 * - Setelah Monthly Closing CLOSED → is_locked = true → tidak bisa diubah
 * - Selisih SELALU dicatat, TIDAK pernah dihapus
 *
 * @property int    $id
 * @property int    $period_year
 * @property int    $period_month
 * @property string $source
 * @property float  $total_expected
 * @property float  $total_actual
 * @property float  $difference
 * @property string $status
 * @property bool   $is_locked
 */
class ReconciliationLog extends Model
{
    // ==================== CONSTANTS ====================

    const SOURCE_GATEWAY = 'gateway';
    const SOURCE_BANK    = 'bank';

    const STATUS_MATCH         = 'MATCH';
    const STATUS_PARTIAL_MATCH = 'PARTIAL_MATCH';
    const STATUS_MISMATCH      = 'MISMATCH';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'period_year',
        'period_month',
        'period_key',
        'source',
        'total_expected',
        'total_actual',
        'difference',
        'total_expected_count',
        'total_actual_count',
        'unmatched_invoice_count',
        'unmatched_payment_count',
        'double_payment_count',
        'status',
        'unmatched_invoices',
        'unmatched_payments',
        'amount_mismatches',
        'double_payments',
        'summary_snapshot',
        'notes',
        'discrepancy_notes',
        'is_locked',
        'reconciled_by',
        'reconciled_at',
        'recon_hash',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'period_year'            => 'integer',
        'period_month'           => 'integer',
        'total_expected'         => 'decimal:2',
        'total_actual'           => 'decimal:2',
        'difference'             => 'decimal:2',
        'total_expected_count'   => 'integer',
        'total_actual_count'     => 'integer',
        'unmatched_invoice_count' => 'integer',
        'unmatched_payment_count' => 'integer',
        'double_payment_count'   => 'integer',
        'is_locked'              => 'boolean',
        'reconciled_at'          => 'datetime',
        'unmatched_invoices'     => 'array',
        'unmatched_payments'     => 'array',
        'amount_mismatches'      => 'array',
        'double_payments'        => 'array',
        'summary_snapshot'       => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function reconciledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    // ==================== SCOPES ====================

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)
                     ->where('period_month', $month);
    }

    public function scopeGateway(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_GATEWAY);
    }

    public function scopeBank(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_BANK);
    }

    public function scopeMatched(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MATCH);
    }

    public function scopeMismatched(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MISMATCH);
    }

    // ==================== ACCESSORS ====================

    public function getPeriodLabelAttribute(): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        return ($bulan[$this->period_month] ?? 'N/A') . ' ' . $this->period_year;
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_MATCH         => '<span class="badge bg-gradient-success badge-sm">MATCH</span>',
            self::STATUS_PARTIAL_MATCH => '<span class="badge bg-gradient-warning badge-sm">PARTIAL MATCH</span>',
            self::STATUS_MISMATCH      => '<span class="badge bg-gradient-danger badge-sm">MISMATCH</span>',
            default                    => '<span class="badge bg-gradient-secondary badge-sm">UNKNOWN</span>',
        };
    }

    public function getSourceBadgeAttribute(): string
    {
        return match ($this->source) {
            self::SOURCE_GATEWAY => '<span class="badge bg-gradient-info badge-sm">Gateway</span>',
            self::SOURCE_BANK    => '<span class="badge bg-gradient-primary badge-sm">Bank</span>',
            default              => '<span class="badge bg-gradient-secondary badge-sm">-</span>',
        };
    }

    public function getFormattedExpectedAttribute(): string
    {
        return 'Rp ' . number_format($this->total_expected, 0, ',', '.');
    }

    public function getFormattedActualAttribute(): string
    {
        return 'Rp ' . number_format($this->total_actual, 0, ',', '.');
    }

    public function getFormattedDifferenceAttribute(): string
    {
        $prefix = $this->difference >= 0 ? '' : '-';
        return $prefix . 'Rp ' . number_format(abs($this->difference), 0, ',', '.');
    }

    // ==================== METHODS ====================

    /**
     * Is period locked (no more changes)?
     */
    public function isLocked(): bool
    {
        return $this->is_locked;
    }

    /**
     * Is reconciliation matching?
     */
    public function isMatch(): bool
    {
        return $this->status === self::STATUS_MATCH;
    }

    /**
     * Generate hash for integrity.
     */
    public function generateHash(): string
    {
        return hash('sha256', json_encode([
            'year'     => $this->period_year,
            'month'    => $this->period_month,
            'source'   => $this->source,
            'expected' => (string) $this->total_expected,
            'actual'   => (string) $this->total_actual,
            'diff'     => (string) $this->difference,
            'status'   => $this->status,
        ]));
    }

    /**
     * Lock this reconciliation record.
     */
    public function lock(): self
    {
        $this->update([
            'is_locked'  => true,
            'recon_hash' => $this->generateHash(),
        ]);
        return $this;
    }
}
