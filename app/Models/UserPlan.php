<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * UserPlan Model (Paket Aktif User)
 * 
 * Menyimpan paket yang dimiliki/aktif oleh user (klien).
 * 
 * ATURAN BISNIS:
 * - 1 user hanya boleh punya 1 paket AKTIF (status = active)
 * - Paket inactive/expired tetap disimpan untuk history
 * - Kuota berkurang setiap kirim pesan
 * - Jika kuota habis / expired → campaign diblok
 * 
 * STATUS FLOW:
 * pending → active → expired/cancelled/upgraded
 * 
 * @property int $id
 * @property int $klien_id
 * @property int $plan_id
 * @property int|null $assigned_by
 * @property string $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $expires_at
 * @property int $quota_messages_initial
 * @property int $quota_messages_used
 * @property int $quota_messages_remaining
 * @property string $activation_source
 * @property float $price_paid
 * @property string|null $idempotency_key
 */
class UserPlan extends Model
{
    use SoftDeletes;

    protected $table = 'user_plans';

    // ==================== CONSTANTS ====================

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_UPGRADED = 'upgraded';

    const SOURCE_PAYMENT = 'payment';
    const SOURCE_ADMIN = 'admin';
    const SOURCE_PROMO = 'promo';
    const SOURCE_UPGRADE = 'upgrade';
    const SOURCE_TRIAL = 'trial';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'klien_id',
        'plan_id',
        'assigned_by',
        'status',
        'activated_at',
        'expires_at',
        'quota_messages_initial',
        'quota_messages_used',
        'quota_messages_remaining',
        'quota_contacts_initial',
        'quota_contacts_used',
        'quota_campaigns_initial',
        'quota_campaigns_active',
        'activation_source',
        'price_paid',
        'currency',
        'idempotency_key',
        'transaction_id',
        'notes',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'quota_messages_initial' => 'integer',
        'quota_messages_used' => 'integer',
        'quota_messages_remaining' => 'integer',
        'quota_contacts_initial' => 'integer',
        'quota_contacts_used' => 'integer',
        'quota_campaigns_initial' => 'integer',
        'quota_campaigns_active' => 'integer',
        'price_paid' => 'decimal:2',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien pemilik paket
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Paket yang digunakan
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Admin yang assign (untuk corporate)
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Transaksi pembelian
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PlanTransaction::class, 'transaction_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope: Hanya paket aktif
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Paket yang belum expired
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope: Paket yang sudah expired
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
                     ->where('expires_at', '<=', now());
    }

    /**
     * Scope: Paket yang masih punya kuota
     */
    public function scopeHasQuota(Builder $query): Builder
    {
        return $query->where('quota_messages_remaining', '>', 0);
    }

    /**
     * Scope: Filter by klien
     */
    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get quota usage percentage
     */
    public function getQuotaUsagePercentageAttribute(): float
    {
        if ($this->quota_messages_initial <= 0) {
            return 0;
        }
        return round(($this->quota_messages_used / $this->quota_messages_initial) * 100, 2);
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->expires_at) {
            return null; // Unlimited
        }
        return max(0, now()->diffInDays($this->expires_at, false));
    }

    /**
     * Check if expired
     */
    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        return $this->expires_at->isPast();
    }

    // ==================== HELPER METHODS ====================

    /**
     * Cek apakah paket aktif dan valid
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE 
            && !$this->is_expired;
    }

    /**
     * Cek apakah masih punya kuota
     */
    public function hasQuota(int $amount = 1): bool
    {
        return $this->quota_messages_remaining >= $amount;
    }

    /**
     * Cek apakah bisa kirim pesan
     */
    public function canSendMessage(int $amount = 1): bool
    {
        return $this->isActive() && $this->hasQuota($amount);
    }

    /**
     * Consume quota (kurangi kuota)
     * 
     * @param int $amount Jumlah pesan
     * @return bool Success or fail
     * @throws \DomainException Jika kuota tidak cukup
     */
    public function consumeQuota(int $amount = 1): bool
    {
        if (!$this->canSendMessage($amount)) {
            throw new \DomainException(
                "Kuota tidak mencukupi. Tersisa: {$this->quota_messages_remaining}, dibutuhkan: {$amount}"
            );
        }

        // Atomic update dengan lock
        return DB::transaction(function () use ($amount) {
            $updated = static::where('id', $this->id)
                ->where('quota_messages_remaining', '>=', $amount)
                ->lockForUpdate()
                ->update([
                    'quota_messages_used' => DB::raw("quota_messages_used + {$amount}"),
                    'quota_messages_remaining' => DB::raw("quota_messages_remaining - {$amount}"),
                ]);

            if ($updated) {
                $this->refresh();
                return true;
            }

            throw new \DomainException('Gagal mengkonsumsi kuota. Mungkin kuota telah habis.');
        });
    }

    /**
     * Rollback quota (kembalikan kuota jika kirim gagal)
     */
    public function rollbackQuota(int $amount = 1): bool
    {
        return DB::transaction(function () use ($amount) {
            $maxRollback = min($amount, $this->quota_messages_used);
            
            if ($maxRollback <= 0) {
                return false;
            }

            static::where('id', $this->id)
                ->lockForUpdate()
                ->update([
                    'quota_messages_used' => DB::raw("quota_messages_used - {$maxRollback}"),
                    'quota_messages_remaining' => DB::raw("quota_messages_remaining + {$maxRollback}"),
                ]);

            $this->refresh();
            return true;
        });
    }

    /**
     * Activate paket
     */
    public function activate(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->status = self::STATUS_ACTIVE;
        $this->activated_at = now();
        
        // Set expiry date based on plan duration
        if ($this->plan && $this->plan->duration_days > 0) {
            $this->expires_at = now()->addDays($this->plan->duration_days);
        }

        return $this->save();
    }

    /**
     * Mark as expired
     */
    public function markAsExpired(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->status = self::STATUS_EXPIRED;
        return $this->save();
    }

    /**
     * Cancel paket
     */
    public function cancel(string $reason = null): bool
    {
        $this->status = self::STATUS_CANCELLED;
        if ($reason) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Cancelled: {$reason}";
        }
        return $this->save();
    }

    /**
     * Mark as upgraded (when user upgrade to new plan)
     */
    public function markAsUpgraded(): bool
    {
        $this->status = self::STATUS_UPGRADED;
        return $this->save();
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get active plan untuk klien
     */
    public static function getActiveForKlien(int $klienId): ?self
    {
        return static::forKlien($klienId)
            ->active()
            ->notExpired()
            ->first();
    }

    /**
     * Cek apakah klien sudah punya paket aktif
     */
    public static function hasActivePlan(int $klienId): bool
    {
        return static::getActiveForKlien($klienId) !== null;
    }

    /**
     * Create user plan dari plan (dengan validasi)
     * 
     * @throws \DomainException Jika sudah punya paket aktif
     */
    public static function createFromPlan(
        int $klienId,
        Plan $plan,
        string $activationSource,
        ?int $assignedBy = null,
        ?float $pricePaid = null,
        ?string $idempotencyKey = null
    ): self {
        // Cek double activation dengan idempotency key
        if ($idempotencyKey) {
            $existing = static::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing; // Idempotent: return existing
            }
        }

        // Cek apakah sudah punya paket aktif
        if (static::hasActivePlan($klienId)) {
            throw new \DomainException('Klien sudah memiliki paket aktif. Upgrade atau cancel terlebih dahulu.');
        }

        return static::create([
            'klien_id' => $klienId,
            'plan_id' => $plan->id,
            'assigned_by' => $assignedBy,
            'status' => self::STATUS_PENDING,
            'quota_messages_initial' => 0, // Quota via saldo (terpisah)
            'quota_messages_used' => 0,
            'quota_messages_remaining' => 0,
            'quota_contacts_initial' => 0,
            'quota_contacts_used' => 0,
            'quota_campaigns_initial' => $plan->max_campaigns,
            'quota_campaigns_active' => 0,
            'activation_source' => $activationSource,
            'price_paid' => $pricePaid ?? $plan->price_monthly,
            'currency' => 'IDR',
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Activate dari payment callback (dengan idempotency)
     */
    public static function activateFromPayment(string $idempotencyKey): ?self
    {
        $userPlan = static::where('idempotency_key', $idempotencyKey)
            ->where('status', self::STATUS_PENDING)
            ->first();

        if (!$userPlan) {
            return null; // Already activated or not found
        }

        // Deactivate existing active plan
        static::forKlien($userPlan->klien_id)
            ->active()
            ->where('id', '!=', $userPlan->id)
            ->update(['status' => self::STATUS_UPGRADED]);

        $userPlan->activate();
        return $userPlan;
    }
}
