<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * WaUsageLog Model
 * 
 * Model untuk log penggunaan WhatsApp.
 * WAJIB untuk audit dan tracking biaya.
 * 
 * ATURAN BISNIS:
 * - Setiap pesan = 1 log
 * - Simpan saldo sebelum & sesudah
 * - Simpan alasan jika ditolak
 * 
 * @property int $id
 * @property int $klien_id
 * @property int|null $pengguna_id
 * @property int|null $kampanye_id
 * @property int|null $target_kampanye_id
 * @property int|null $percakapan_inbox_id
 * @property string $nomor_tujuan
 * @property string $message_type
 * @property string $message_category
 * @property float $price_per_message
 * @property float $total_cost
 * @property string $currency
 * @property float $saldo_before
 * @property float $saldo_after
 * @property string $status
 * @property string|null $rejection_reason
 * @property string|null $provider_message_id
 * @property string|null $provider_status
 */
class WaUsageLog extends Model
{
    protected $table = 'wa_usage_logs';

    protected $fillable = [
        'klien_id',
        'pengguna_id',
        'kampanye_id',
        'target_kampanye_id',
        'percakapan_inbox_id',
        'nomor_tujuan',
        'message_type',
        'message_category',
        'price_per_message',
        'total_cost',
        'currency',
        'saldo_before',
        'saldo_after',
        'status',
        'rejection_reason',
        'provider_message_id',
        'provider_status',
    ];

    protected $casts = [
        'price_per_message' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'saldo_before' => 'decimal:2',
        'saldo_after' => 'decimal:2',
    ];

    // ==================== CONSTANTS ====================

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PENDING = 'pending';

    const REJECTION_LIMIT_DAILY = 'limit_daily';
    const REJECTION_LIMIT_MONTHLY = 'limit_monthly';
    const REJECTION_INSUFFICIENT_BALANCE = 'insufficient_balance';
    const REJECTION_PLAN_NOT_ALLOWED = 'plan_not_allowed';
    const REJECTION_CAMPAIGN_LIMIT = 'campaign_limit';

    // ==================== SCOPES ====================

    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year);
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id');
    }

    public function kampanye(): BelongsTo
    {
        return $this->belongsTo(Kampanye::class, 'kampanye_id');
    }

    public function targetKampanye(): BelongsTo
    {
        return $this->belongsTo(TargetKampanye::class, 'target_kampanye_id');
    }

    public function percakapanInbox(): BelongsTo
    {
        return $this->belongsTo(PercakapanInbox::class, 'percakapan_inbox_id');
    }

    // ==================== HELPERS ====================

    /**
     * Check if this log is a success
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if this log is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Get formatted cost
     */
    public function getFormattedCostAttribute(): string
    {
        return 'Rp ' . number_format($this->total_cost, 0, ',', '.');
    }

    /**
     * Get rejection reason label
     */
    public function getRejectionLabelAttribute(): string
    {
        $labels = [
            self::REJECTION_LIMIT_DAILY => 'Limit harian tercapai',
            self::REJECTION_LIMIT_MONTHLY => 'Limit bulanan tercapai',
            self::REJECTION_INSUFFICIENT_BALANCE => 'Saldo tidak mencukupi',
            self::REJECTION_PLAN_NOT_ALLOWED => 'Fitur tidak tersedia di plan Anda',
            self::REJECTION_CAMPAIGN_LIMIT => 'Limit campaign tercapai',
        ];

        return $labels[$this->rejection_reason] ?? $this->rejection_reason ?? '-';
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get daily usage count for klien
     */
    public static function getDailyUsage(int $klienId): int
    {
        return static::forKlien($klienId)
            ->success()
            ->today()
            ->count();
    }

    /**
     * Get monthly usage count for klien
     */
    public static function getMonthlyUsage(int $klienId): int
    {
        return static::forKlien($klienId)
            ->success()
            ->thisMonth()
            ->count();
    }

    /**
     * Get daily cost for klien
     */
    public static function getDailyCost(int $klienId): float
    {
        return (float) static::forKlien($klienId)
            ->success()
            ->today()
            ->sum('total_cost');
    }

    /**
     * Get monthly cost for klien
     */
    public static function getMonthlyCost(int $klienId): float
    {
        return (float) static::forKlien($klienId)
            ->success()
            ->thisMonth()
            ->sum('total_cost');
    }

    /**
     * Log a successful message
     */
    public static function logSuccess(array $data): self
    {
        $data['status'] = self::STATUS_SUCCESS;
        return static::create($data);
    }

    /**
     * Log a rejected message
     */
    public static function logRejection(array $data, string $reason): self
    {
        $data['status'] = self::STATUS_REJECTED;
        $data['rejection_reason'] = $reason;
        $data['total_cost'] = 0; // Tidak ada biaya untuk rejected
        return static::create($data);
    }

    /**
     * Log a failed message
     */
    public static function logFailure(array $data): self
    {
        $data['status'] = self::STATUS_FAILED;
        $data['total_cost'] = 0; // Tidak ada biaya untuk failed
        return static::create($data);
    }
}
