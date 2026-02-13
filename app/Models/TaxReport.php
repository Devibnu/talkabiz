<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\ImmutableLedger;

/**
 * TaxReport — Laporan Pajak Bulanan (PPN)
 *
 * IMMUTABLE LEDGER — Append Only (saat status = final)
 * ❌ Tidak boleh UPDATE report yang sudah final
 * ❌ Tidak boleh DELETE report
 * ✅ Re-generate hanya saat status = draft
 *
 * ATURAN KERAS:
 * ─────────────
 * ❌ Data TIDAK dari wallet_transactions
 * ✅ Data HANYA dari invoices (status=PAID, tax_type=PPN)
 * ✅ 1 report per bulan (unique year+month)
 * ✅ Bisa di-generate ulang selama status=draft
 * ✅ Tarif dari config('tax.*'), TIDAK hardcode
 *
 * @property int    $id
 * @property int    $year
 * @property int    $month
 * @property int    $total_invoices
 * @property float  $total_dpp
 * @property float  $total_ppn
 * @property float  $total_amount
 * @property float  $tax_rate
 * @property string $status           draft|final
 * @property int    $generated_by
 * @property string $generated_at
 * @property string $finalized_at
 * @property array  $metadata
 * @property string $report_hash
 */
class TaxReport extends Model
{
    use ImmutableLedger;

    protected $table = 'tax_reports';

    /**
     * Immutable setelah status final.
     * Draft masih boleh di-regenerate.
     */
    public function isLedgerImmutable(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    // ==================== CONSTANTS ====================

    const STATUS_DRAFT = 'draft';
    const STATUS_FINAL = 'final';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'year',
        'month',
        'total_invoices',
        'total_dpp',
        'total_ppn',
        'total_amount',
        'tax_rate',
        'status',
        'generated_by',
        'generated_at',
        'finalized_at',
        'metadata',
        'report_hash',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'year'           => 'integer',
        'month'          => 'integer',
        'total_invoices' => 'integer',
        'total_dpp'      => 'decimal:2',
        'total_ppn'      => 'decimal:2',
        'total_amount'   => 'decimal:2',
        'tax_rate'       => 'decimal:2',
        'generated_at'   => 'datetime',
        'finalized_at'   => 'datetime',
        'metadata'       => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User yang generate laporan ini.
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    // ==================== SCOPES ====================

    /**
     * Filter by periode (year + month).
     */
    public function scopeForPeriod($query, int $year, int $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Hanya report final (locked).
     */
    public function scopeFinal($query)
    {
        return $query->where('status', self::STATUS_FINAL);
    }

    /**
     * Hanya report draft (editable).
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Urutkan dari terbaru.
     */
    public function scopeLatestPeriod($query)
    {
        return $query->orderByDesc('year')->orderByDesc('month');
    }

    // ==================== ACCESSORS ====================

    /**
     * Label periode: "Januari 2026"
     */
    public function getPeriodLabelAttribute(): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return ($bulan[$this->month] ?? 'N/A') . ' ' . $this->year;
    }

    /**
     * Apakah masih bisa di-regenerate.
     */
    public function getIsEditableAttribute(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Format DPP untuk tampilan.
     */
    public function getFormattedDppAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_dpp, 0, ',', '.');
    }

    /**
     * Format PPN untuk tampilan.
     */
    public function getFormattedPpnAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_ppn, 0, ',', '.');
    }

    /**
     * Format total untuk tampilan.
     */
    public function getFormattedTotalAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_amount, 0, ',', '.');
    }

    // ==================== METHODS ====================

    /**
     * Finalize report (lock dari re-generate).
     */
    public function finalize(): bool
    {
        if ($this->status === self::STATUS_FINAL) {
            return false;
        }

        $this->update([
            'status'       => self::STATUS_FINAL,
            'finalized_at' => now(),
        ]);

        return true;
    }

    /**
     * Re-open report (unlock untuk re-generate).
     */
    public function reopen(): bool
    {
        if ($this->status === self::STATUS_DRAFT) {
            return false;
        }

        $this->update([
            'status'       => self::STATUS_DRAFT,
            'finalized_at' => null,
        ]);

        return true;
    }
}
