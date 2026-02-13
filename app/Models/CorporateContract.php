<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Corporate Contract Model
 * 
 * Manual billing for corporate clients:
 * - Invoice-based (no Midtrans)
 * - Custom contract values
 * - Billing cycle tracking
 * 
 * MANUAL BILLING = No self-service, admin-driven
 */
class CorporateContract extends Model
{
    protected $fillable = [
        'corporate_client_id',
        'contract_number',
        'plan_name',
        'plan_description',
        'billing_cycle',
        'contract_value',
        'monthly_rate',
        'currency',
        'start_date',
        'end_date',
        'auto_renew',
        'status',
        'last_invoice_date',
        'next_invoice_date',
        'outstanding_amount',
        'created_by',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'last_invoice_date' => 'date',
        'next_invoice_date' => 'date',
        'approved_at' => 'datetime',
        'contract_value' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'auto_renew' => 'boolean',
    ];

    // ==================== CONSTANTS ====================

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_RENEWED = 'renewed';

    const BILLING_MONTHLY = 'monthly';
    const BILLING_QUARTERLY = 'quarterly';
    const BILLING_ANNUAL = 'annual';
    const BILLING_CUSTOM = 'custom';

    // ==================== RELATIONSHIPS ====================

    public function corporateClient(): BelongsTo
    {
        return $this->belongsTo(CorporateClient::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ==================== STATUS HELPERS ====================

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->end_date->gte(now());
    }

    public function isExpired(): bool
    {
        return $this->end_date->lt(now());
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Days until contract expires.
     */
    public function daysUntilExpiry(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }

    /**
     * Is expiring soon (within 30 days)?
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->isActive() && $this->daysUntilExpiry() <= $days;
    }

    // ==================== CONTRACT VALUE HELPERS ====================

    /**
     * Calculate monthly value (for reporting).
     */
    public function getMonthlyValue(): float
    {
        switch ($this->billing_cycle) {
            case self::BILLING_MONTHLY:
                return (float) $this->contract_value;
            case self::BILLING_QUARTERLY:
                return (float) $this->contract_value / 3;
            case self::BILLING_ANNUAL:
                return (float) $this->contract_value / 12;
            default:
                $months = max(1, $this->start_date->diffInMonths($this->end_date));
                return (float) $this->contract_value / $months;
        }
    }

    /**
     * Get total contract value (lifetime).
     */
    public function getTotalContractValue(): float
    {
        $months = max(1, $this->start_date->diffInMonths($this->end_date));
        return $this->getMonthlyValue() * $months;
    }

    // ==================== CONTRACT ACTIONS ====================

    /**
     * Approve contract (admin action).
     */
    public function approve(int $adminId): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'approved_by' => $adminId,
            'approved_at' => now(),
        ]);

        $this->corporateClient->logActivity(
            'contract_approved',
            'billing',
            "Contract #{$this->contract_number} approved",
            $adminId
        );
    }

    /**
     * Terminate contract.
     */
    public function cancel(int $adminId, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);

        $this->corporateClient->logActivity(
            'contract_cancelled',
            'billing',
            "Contract #{$this->contract_number} cancelled: {$reason}",
            $adminId
        );
    }

    /**
     * Mark contract as renewed.
     */
    public function markAsRenewed(): void
    {
        $this->update(['status' => self::STATUS_RENEWED]);
    }

    /**
     * Renew contract (create new contract).
     */
    public function renew(int $adminId, ?float $newValue = null): self
    {
        // Mark current as renewed
        $this->markAsRenewed();

        // Create new contract
        $newContract = self::create([
            'corporate_client_id' => $this->corporate_client_id,
            'contract_number' => self::generateContractNumber(),
            'plan_name' => $this->plan_name,
            'billing_cycle' => $this->billing_cycle,
            'contract_value' => $newValue ?? $this->contract_value,
            'currency' => $this->currency,
            'start_date' => $this->end_date->addDay(),
            'end_date' => $this->calculateNextEndDate(),
            'status' => self::STATUS_DRAFT,
            'auto_renew' => $this->auto_renew,
            'created_by' => $adminId,
            'notes' => "Renewed from contract #{$this->contract_number}",
        ]);

        $this->corporateClient->logActivity(
            'contract_renewed',
            'billing',
            "Contract #{$this->contract_number} renewed to #{$newContract->contract_number}",
            $adminId
        );

        return $newContract;
    }

    // ==================== HELPERS ====================

    /**
     * Generate unique contract number.
     */
    public static function generateContractNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        $sequence = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count() + 1;

        return sprintf('CTR-%s%s-%04d', $year, $month, $sequence);
    }

    /**
     * Calculate next end date based on billing cycle.
     */
    protected function calculateNextEndDate(): Carbon
    {
        $startDate = $this->end_date->addDay();

        switch ($this->billing_cycle) {
            case self::BILLING_MONTHLY:
                return $startDate->addMonth()->subDay();
            case self::BILLING_QUARTERLY:
                return $startDate->addMonths(3)->subDay();
            case self::BILLING_ANNUAL:
                return $startDate->addYear()->subDay();
            default:
                return $startDate->addMonth()->subDay();
        }
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('end_date', '>=', now());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->active()
            ->where('end_date', '<=', now()->addDays($days));
    }

    public function scopeNeedsRenewalReminder($query)
    {
        return $query->active()
            ->where('auto_renew', false)
            ->where('end_date', '<=', now()->addDays(30));
    }
}
