<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * DisputeRequest Model
 * 
 * Dispute/sengketa atas transaksi atau layanan.
 * Lebih kompleks dari refund, bisa melibatkan investigasi.
 */
class DisputeRequest extends Model
{
    use SoftDeletes;

    protected $table = 'dispute_requests';

    // ==================== TYPE CONSTANTS ====================
    const TYPE_BILLING_ERROR = 'billing_error';
    const TYPE_SERVICE_QUALITY = 'service_quality';
    const TYPE_UNAUTHORIZED_CHARGE = 'unauthorized_charge';
    const TYPE_SLA_BREACH = 'sla_breach';
    const TYPE_CONTRACT_ISSUE = 'contract_issue';
    const TYPE_OTHER = 'other';

    // ==================== PRIORITY CONSTANTS ====================
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    // ==================== STATUS CONSTANTS ====================
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_INVESTIGATING = 'investigating';
    const STATUS_PENDING_INFO = 'pending_info';
    const STATUS_RESOLVED_FAVOR_CLIENT = 'resolved_favor_client';
    const STATUS_RESOLVED_FAVOR_OWNER = 'resolved_favor_owner';
    const STATUS_RESOLVED_PARTIAL = 'resolved_partial';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ESCALATED = 'escalated';
    const STATUS_CLOSED = 'closed';

    // ==================== RESOLUTION TYPE CONSTANTS ====================
    const RESOLUTION_REFUND_FULL = 'refund_full';
    const RESOLUTION_REFUND_PARTIAL = 'refund_partial';
    const RESOLUTION_CREDIT_COMPENSATION = 'credit_compensation';
    const RESOLUTION_SERVICE_EXTENSION = 'service_extension';
    const RESOLUTION_NO_ACTION = 'no_action';
    const RESOLUTION_OTHER = 'other';

    protected $fillable = [
        'dispute_number',
        'klien_id',
        'invoice_id',
        'subscription_id',
        'related_refund_id',
        'type',
        'priority',
        'subject',
        'description',
        'evidence',
        'disputed_amount',
        'resolved_amount',
        'currency',
        'status',
        'resolution_type',
        'resolution_description',
        'assigned_to',
        'resolved_by',
        'acknowledged_at',
        'resolved_at',
        'closed_at',
        'impact_analysis',
        'metadata',
    ];

    protected $casts = [
        'disputed_amount' => 'integer',
        'resolved_amount' => 'integer',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'evidence' => 'array',
        'impact_analysis' => 'array',
        'metadata' => 'array',
    ];

    protected $appends = [
        'status_label',
        'type_label',
        'priority_label',
        'is_open',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dispute) {
            if (empty($dispute->dispute_number)) {
                $dispute->dispute_number = self::generateNumber();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function relatedRefund(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'related_refund_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class, 'dispute_id')->orderBy('created_at', 'desc');
    }

    public function creditTransaction(): HasOne
    {
        return $this->hasOne(CreditTransaction::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    // ==================== SCOPES ====================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_RESOLVED_FAVOR_CLIENT,
            self::STATUS_RESOLVED_FAVOR_OWNER,
            self::STATUS_RESOLVED_PARTIAL,
            self::STATUS_REJECTED,
            self::STATUS_CLOSED,
        ]);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_RESOLVED_FAVOR_CLIENT,
            self::STATUS_RESOLVED_FAVOR_OWNER,
            self::STATUS_RESOLVED_PARTIAL,
            self::STATUS_REJECTED,
            self::STATUS_CLOSED,
        ]);
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED,
            self::STATUS_PENDING_INFO,
            self::STATUS_ESCALATED,
        ]);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_SUBMITTED => 'Baru Disubmit',
            self::STATUS_ACKNOWLEDGED => 'Diterima',
            self::STATUS_INVESTIGATING => 'Dalam Investigasi',
            self::STATUS_PENDING_INFO => 'Menunggu Info Klien',
            self::STATUS_RESOLVED_FAVOR_CLIENT => 'Diselesaikan (Pro Klien)',
            self::STATUS_RESOLVED_FAVOR_OWNER => 'Diselesaikan (Pro Owner)',
            self::STATUS_RESOLVED_PARTIAL => 'Diselesaikan Sebagian',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_ESCALATED => 'Dieskalasi',
            self::STATUS_CLOSED => 'Ditutup',
            default => $this->status,
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            self::TYPE_BILLING_ERROR => 'Kesalahan Billing',
            self::TYPE_SERVICE_QUALITY => 'Kualitas Layanan',
            self::TYPE_UNAUTHORIZED_CHARGE => 'Transaksi Tidak Dikenal',
            self::TYPE_SLA_BREACH => 'Pelanggaran SLA',
            self::TYPE_CONTRACT_ISSUE => 'Masalah Kontrak',
            self::TYPE_OTHER => 'Lainnya',
            default => $this->type,
        };
    }

    public function getPriorityLabelAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_MEDIUM => 'Sedang',
            self::PRIORITY_HIGH => 'Tinggi',
            self::PRIORITY_CRITICAL => 'Kritis',
            default => $this->priority,
        };
    }

    public function getIsOpenAttribute(): bool
    {
        return !in_array($this->status, [
            self::STATUS_RESOLVED_FAVOR_CLIENT,
            self::STATUS_RESOLVED_FAVOR_OWNER,
            self::STATUS_RESOLVED_PARTIAL,
            self::STATUS_REJECTED,
            self::STATUS_CLOSED,
        ]);
    }

    public function getResolutionLabelAttribute(): ?string
    {
        return match($this->resolution_type) {
            self::RESOLUTION_REFUND_FULL => 'Refund Penuh',
            self::RESOLUTION_REFUND_PARTIAL => 'Refund Sebagian',
            self::RESOLUTION_CREDIT_COMPENSATION => 'Kompensasi Kredit',
            self::RESOLUTION_SERVICE_EXTENSION => 'Perpanjangan Layanan',
            self::RESOLUTION_NO_ACTION => 'Tidak Ada Aksi',
            self::RESOLUTION_OTHER => 'Lainnya',
            default => null,
        };
    }

    // ==================== HELPERS ====================

    public static function generateNumber(): string
    {
        $prefix = 'DSP';
        $yearMonth = now()->format('Ym');
        $sequence = self::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $yearMonth, $sequence);
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_BILLING_ERROR => 'Kesalahan Billing',
            self::TYPE_SERVICE_QUALITY => 'Kualitas Layanan',
            self::TYPE_UNAUTHORIZED_CHARGE => 'Transaksi Tidak Dikenal',
            self::TYPE_SLA_BREACH => 'Pelanggaran SLA',
            self::TYPE_CONTRACT_ISSUE => 'Masalah Kontrak',
            self::TYPE_OTHER => 'Lainnya',
        ];
    }

    public static function getPriorities(): array
    {
        return [
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_MEDIUM => 'Sedang',
            self::PRIORITY_HIGH => 'Tinggi',
            self::PRIORITY_CRITICAL => 'Kritis',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED => 'Baru Disubmit',
            self::STATUS_ACKNOWLEDGED => 'Diterima',
            self::STATUS_INVESTIGATING => 'Dalam Investigasi',
            self::STATUS_PENDING_INFO => 'Menunggu Info Klien',
            self::STATUS_RESOLVED_FAVOR_CLIENT => 'Diselesaikan (Pro Klien)',
            self::STATUS_RESOLVED_FAVOR_OWNER => 'Diselesaikan (Pro Owner)',
            self::STATUS_RESOLVED_PARTIAL => 'Diselesaikan Sebagian',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_ESCALATED => 'Dieskalasi',
            self::STATUS_CLOSED => 'Ditutup',
        ];
    }

    public static function getResolutionTypes(): array
    {
        return [
            self::RESOLUTION_REFUND_FULL => 'Refund Penuh',
            self::RESOLUTION_REFUND_PARTIAL => 'Refund Sebagian',
            self::RESOLUTION_CREDIT_COMPENSATION => 'Kompensasi Kredit',
            self::RESOLUTION_SERVICE_EXTENSION => 'Perpanjangan Layanan',
            self::RESOLUTION_NO_ACTION => 'Tidak Ada Aksi',
            self::RESOLUTION_OTHER => 'Lainnya',
        ];
    }

    /**
     * Get allowed status transitions
     */
    public function getAllowedTransitions(): array
    {
        return match($this->status) {
            self::STATUS_SUBMITTED => [
                self::STATUS_ACKNOWLEDGED,
                self::STATUS_REJECTED,
            ],
            self::STATUS_ACKNOWLEDGED => [
                self::STATUS_INVESTIGATING,
                self::STATUS_PENDING_INFO,
                self::STATUS_RESOLVED_FAVOR_CLIENT,
                self::STATUS_RESOLVED_FAVOR_OWNER,
                self::STATUS_RESOLVED_PARTIAL,
                self::STATUS_REJECTED,
            ],
            self::STATUS_INVESTIGATING => [
                self::STATUS_PENDING_INFO,
                self::STATUS_RESOLVED_FAVOR_CLIENT,
                self::STATUS_RESOLVED_FAVOR_OWNER,
                self::STATUS_RESOLVED_PARTIAL,
                self::STATUS_ESCALATED,
            ],
            self::STATUS_PENDING_INFO => [
                self::STATUS_INVESTIGATING,
                self::STATUS_RESOLVED_FAVOR_OWNER, // No response = favor owner
                self::STATUS_CLOSED,
            ],
            self::STATUS_ESCALATED => [
                self::STATUS_INVESTIGATING,
                self::STATUS_RESOLVED_FAVOR_CLIENT,
                self::STATUS_RESOLVED_FAVOR_OWNER,
                self::STATUS_RESOLVED_PARTIAL,
            ],
            self::STATUS_RESOLVED_FAVOR_CLIENT,
            self::STATUS_RESOLVED_FAVOR_OWNER,
            self::STATUS_RESOLVED_PARTIAL => [
                self::STATUS_CLOSED,
            ],
            default => [],
        };
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, $this->getAllowedTransitions());
    }
}
