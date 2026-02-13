<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * SubscriptionInvoice — Bukti tagihan langganan paket
 * 
 * Dibuat saat user checkout paket (status: pending).
 * Di-update ke 'paid' saat payment berhasil (via webhook).
 * 
 * BUKAN wallet topup. Ini untuk biaya akses sistem (subscription).
 * 
 * @property int $id
 * @property string $invoice_number
 * @property int $klien_id
 * @property int $user_id
 * @property int $plan_id
 * @property int|null $plan_transaction_id
 * @property int|null $subscription_id
 * @property float $amount
 * @property float $discount_amount
 * @property float $final_amount
 * @property string $currency
 * @property array|null $plan_snapshot
 * @property string $status
 * @property string|null $payment_method
 * @property string|null $payment_channel
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property string|null $description
 * @property string|null $notes
 * @property string|null $idempotency_key
 * 
 * @property-read Klien $klien
 * @property-read User $user
 * @property-read Plan $plan
 * @property-read PlanTransaction|null $planTransaction
 * @property-read Subscription|null $subscription
 */
class SubscriptionInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'subscription_invoices';

    // ==================== CONSTANTS ====================

    const STATUS_PENDING   = 'pending';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_REFUNDED  = 'refunded';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'invoice_number',
        'klien_id',
        'user_id',
        'plan_id',
        'plan_transaction_id',
        'subscription_id',
        'amount',
        'discount_amount',
        'final_amount',
        'currency',
        'plan_snapshot',
        'status',
        'payment_method',
        'payment_channel',
        'paid_at',
        'cancelled_at',
        'description',
        'notes',
        'idempotency_key',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'plan_snapshot'   => 'array',
        'amount'          => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount'    => 'decimal:2',
        'paid_at'         => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function planTransaction(): BelongsTo
    {
        return $this->belongsTo(PlanTransaction::class, 'plan_transaction_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    // ==================== SCOPES ====================

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create invoice from Plan checkout — IDEMPOTENT.
     * 
     * Jika invoice dengan idempotency_key yang sama sudah ada,
     * return invoice tersebut tanpa INSERT baru.
     * 
     * Price diambil dari Plan (FIXED), bukan user input.
     * 
     * @param int $klienId
     * @param int $userId
     * @param Plan $plan
     * @param PlanTransaction|null $transaction
     * @param float|null $discountAmount
     * @return self
     */
    public static function createFromCheckout(
        int $klienId,
        int $userId,
        Plan $plan,
        ?PlanTransaction $transaction = null,
        ?float $discountAmount = null
    ): self {
        // ================================================================
        // RULE A+B: Idempotent — gunakan idempotency_key dari transaction
        // BUKAN UUID baru setiap klik. Key stabil per transaksi.
        // ================================================================
        $idempotencyKey = $transaction?->idempotency_key;

        // Cek invoice existing berdasarkan idempotency_key
        if ($idempotencyKey) {
            $existing = self::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                \Illuminate\Support\Facades\Log::info('Returning existing invoice (idempotent)', [
                    'invoice_number' => $existing->invoice_number,
                    'idempotency_key' => $idempotencyKey,
                    'status' => $existing->status,
                ]);
                return $existing;
            }
        }

        $amount = (float) $plan->price_monthly;
        $discount = $discountAmount ?? 0;
        $finalAmount = max(0, $amount - $discount);

        // RULE D: Try-catch untuk race condition (concurrent requests)
        try {
            return self::create([
                'invoice_number'      => self::generateInvoiceNumber(),
                'klien_id'            => $klienId,
                'user_id'             => $userId,
                'plan_id'             => $plan->id,
                'plan_transaction_id' => $transaction?->id,
                'amount'              => $amount,
                'discount_amount'     => $discount,
                'final_amount'        => $finalAmount,
                'currency'            => 'IDR',
                'plan_snapshot'       => $plan->toSnapshot(),
                'status'              => self::STATUS_PENDING,
                'description'         => "Langganan paket {$plan->name}",
                'idempotency_key'     => $idempotencyKey,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key constraint — ambil invoice existing
            if ($idempotencyKey && str_contains($e->getMessage(), 'Duplicate')) {
                $fallback = self::where('idempotency_key', $idempotencyKey)->first();
                if ($fallback) {
                    \Illuminate\Support\Facades\Log::info('Invoice duplicate caught, returning existing', [
                        'idempotency_key' => $idempotencyKey,
                        'invoice_number' => $fallback->invoice_number,
                    ]);
                    return $fallback;
                }
            }
            throw $e;
        }
    }

    // ==================== STATE TRANSITIONS ====================

    /**
     * Mark invoice as paid (called from webhook activation).
     */
    public function markAsPaid(
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        ?int $subscriptionId = null
    ): self {
        $this->update([
            'status'          => self::STATUS_PAID,
            'paid_at'         => now(),
            'payment_method'  => $paymentMethod,
            'payment_channel' => $paymentChannel,
            'subscription_id' => $subscriptionId,
        ]);

        return $this;
    }

    /**
     * Mark invoice as cancelled.
     */
    public function markAsCancelled(?string $reason = null): self
    {
        $this->update([
            'status'       => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'notes'        => $reason,
        ]);

        return $this;
    }

    /**
     * Mark invoice as expired (payment timeout).
     */
    public function markAsExpired(): self
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);

        return $this;
    }

    // ==================== HELPERS ====================

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Generate unique invoice number: INV-SUB-YYYYMM-XXXXX
     */
    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-SUB-' . now()->format('Ym');

        $lastInvoice = self::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -5);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get formatted amount for display.
     */
    public function getFormattedAmountAttribute(): string
    {
        return 'Rp ' . number_format($this->final_amount, 0, ',', '.');
    }

    /**
     * Get status badge color.
     */
    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => 'warning',
            self::STATUS_PAID      => 'success',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_EXPIRED   => 'danger',
            self::STATUS_REFUNDED  => 'info',
            default                => 'secondary',
        };
    }

    /**
     * Get status label in Bahasa.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => 'Menunggu Pembayaran',
            self::STATUS_PAID      => 'Lunas',
            self::STATUS_CANCELLED => 'Dibatalkan',
            self::STATUS_EXPIRED   => 'Kedaluwarsa',
            self::STATUS_REFUNDED  => 'Dikembalikan',
            default                => 'Tidak Diketahui',
        };
    }
}
