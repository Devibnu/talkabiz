<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * RefundRequest Model
 * 
 * Request refund dari klien atas invoice yang sudah dibayar.
 * SEMUA refund harus diapprove oleh Owner.
 */
class RefundRequest extends Model
{
    use SoftDeletes;

    protected $table = 'refund_requests';

    // ==================== REASON CONSTANTS ====================
    const REASON_SERVICE_NOT_WORKING = 'service_not_working';
    const REASON_DUPLICATE_PAYMENT = 'duplicate_payment';
    const REASON_WRONG_AMOUNT = 'wrong_amount';
    const REASON_SERVICE_NOT_USED = 'service_not_used';
    const REASON_DOWNGRADE_DIFFERENCE = 'downgrade_difference';
    const REASON_CANCELLATION = 'cancellation';
    const REASON_OTHER = 'other';

    // ==================== REFUND METHOD CONSTANTS ====================
    const METHOD_CREDIT_BALANCE = 'credit_balance';
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHOD_ORIGINAL_METHOD = 'original_method';

    // ==================== STATUS CONSTANTS ====================
    const STATUS_PENDING = 'pending';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'refund_number',
        'invoice_id',
        'klien_id',
        'subscription_id',
        'reason',
        'description',
        'evidence',
        'requested_amount',
        'approved_amount',
        'currency',
        'refund_method',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'rejection_reason',
        'processed_by',
        'processed_at',
        'transaction_reference',
        'invoice_snapshot',
        'metadata',
    ];

    protected $casts = [
        'requested_amount' => 'integer',
        'approved_amount' => 'integer',
        'reviewed_at' => 'datetime',
        'processed_at' => 'datetime',
        'evidence' => 'array',
        'invoice_snapshot' => 'array',
        'metadata' => 'array',
    ];

    protected $appends = [
        'status_label',
        'reason_label',
        'method_label',
        'is_pending',
        'can_cancel',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($refund) {
            if (empty($refund->refund_number)) {
                $refund->refund_number = self::generateNumber();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class, 'refund_id')->orderBy('created_at', 'desc');
    }

    public function creditTransaction(): HasOne
    {
        return $this->hasOne(CreditTransaction::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    // ==================== SCOPES ====================

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_UNDER_REVIEW]);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Menunggu Review',
            self::STATUS_UNDER_REVIEW => 'Sedang Direview',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_PROCESSING => 'Sedang Diproses',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => $this->status,
        };
    }

    public function getReasonLabelAttribute(): string
    {
        return match($this->reason) {
            self::REASON_SERVICE_NOT_WORKING => 'Layanan Tidak Berfungsi',
            self::REASON_DUPLICATE_PAYMENT => 'Pembayaran Ganda',
            self::REASON_WRONG_AMOUNT => 'Jumlah Salah',
            self::REASON_SERVICE_NOT_USED => 'Layanan Tidak Digunakan',
            self::REASON_DOWNGRADE_DIFFERENCE => 'Selisih Downgrade',
            self::REASON_CANCELLATION => 'Pembatalan',
            self::REASON_OTHER => 'Lainnya',
            default => $this->reason,
        };
    }

    public function getMethodLabelAttribute(): string
    {
        return match($this->refund_method) {
            self::METHOD_CREDIT_BALANCE => 'Saldo Kredit',
            self::METHOD_BANK_TRANSFER => 'Transfer Bank',
            self::METHOD_ORIGINAL_METHOD => 'Metode Pembayaran Asli',
            default => $this->refund_method,
        };
    }

    public function getIsPendingAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_UNDER_REVIEW,
        ]);
    }

    public function getCanCancelAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getFinalAmountAttribute(): int
    {
        return $this->approved_amount ?? $this->requested_amount;
    }

    // ==================== HELPERS ====================

    public static function generateNumber(): string
    {
        $prefix = 'REF';
        $yearMonth = now()->format('Ym');
        $sequence = self::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() + 1;

        return sprintf('%s-%s-%05d', $prefix, $yearMonth, $sequence);
    }

    public static function getReasons(): array
    {
        return [
            self::REASON_SERVICE_NOT_WORKING => 'Layanan Tidak Berfungsi',
            self::REASON_DUPLICATE_PAYMENT => 'Pembayaran Ganda',
            self::REASON_WRONG_AMOUNT => 'Jumlah Salah',
            self::REASON_SERVICE_NOT_USED => 'Layanan Tidak Digunakan',
            self::REASON_DOWNGRADE_DIFFERENCE => 'Selisih Downgrade',
            self::REASON_CANCELLATION => 'Pembatalan',
            self::REASON_OTHER => 'Lainnya',
        ];
    }

    public static function getMethods(): array
    {
        return [
            self::METHOD_CREDIT_BALANCE => 'Saldo Kredit',
            self::METHOD_BANK_TRANSFER => 'Transfer Bank',
            self::METHOD_ORIGINAL_METHOD => 'Metode Pembayaran Asli',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Menunggu Review',
            self::STATUS_UNDER_REVIEW => 'Sedang Direview',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_PROCESSING => 'Sedang Diproses',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
        ];
    }

    /**
     * Check if transition to status is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = match($this->status) {
            self::STATUS_PENDING => [
                self::STATUS_UNDER_REVIEW,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_UNDER_REVIEW => [
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
            ],
            self::STATUS_APPROVED => [
                self::STATUS_PROCESSING,
                self::STATUS_COMPLETED,
            ],
            self::STATUS_PROCESSING => [
                self::STATUS_COMPLETED,
            ],
            default => [],
        };

        return in_array($newStatus, $allowed);
    }

    /**
     * Get allowed status transitions
     */
    public function getAllowedTransitions(): array
    {
        return match($this->status) {
            self::STATUS_PENDING => [
                self::STATUS_UNDER_REVIEW,
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_UNDER_REVIEW => [
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
            ],
            self::STATUS_APPROVED => [
                self::STATUS_PROCESSING,
                self::STATUS_COMPLETED,
            ],
            self::STATUS_PROCESSING => [
                self::STATUS_COMPLETED,
            ],
            default => [],
        };
    }
}
