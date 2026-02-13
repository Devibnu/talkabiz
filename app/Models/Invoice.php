<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Invoice Model
 * 
 * SUMBER KEBENARAN untuk semua transaksi keuangan.
 * 
 * FLOW:
 * =====
 * 1. Create invoice (draft)
 * 2. Send invoice (pending) → generate payment
 * 3. Payment success → invoice paid → activate service
 * 4. Payment expired → invoice expired → grace period → suspend
 * 
 * STATUS TRANSITIONS:
 * - draft → pending (when sent)
 * - pending → paid (payment success)
 * - pending → expired (payment timeout)
 * - pending → cancelled (manual cancel)
 * - paid → refunded (refund processed)
 */
class Invoice extends Model
{

    protected $table = 'invoices';

    // ==================== CONSTANTS ====================

    // Types
    const TYPE_SUBSCRIPTION = 'subscription';
    const TYPE_SUBSCRIPTION_UPGRADE = 'subscription_upgrade';
    const TYPE_SUBSCRIPTION_RENEWAL = 'subscription_renewal';
    const TYPE_TOPUP = 'topup';
    const TYPE_ADDON = 'addon';
    const TYPE_OTHER = 'other';

    // Statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_PARTIAL = 'partial';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    // Grace period (days)
    const GRACE_PERIOD_DAYS = 3;

    // ==================== FILLABLE ====================

    protected $fillable = [
        'invoice_number',
        'klien_id',
        'user_id',
        'type',
        'invoiceable_id',
        'invoiceable_type',
        'wallet_transaction_id',
        'subtotal',
        'discount',
        'tax',
        'total',
        'currency',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'expired_at',
        'grace_period_ends_at',
        'grace_period_notified',
        'payment_method',
        'payment_channel',
        'line_items',
        'metadata',
        'notes',
        // NEW TAX FIELDS
        'tax_amount',
        'discount_amount', 
        'total_calculated',
        'tax_snapshot',
        'pdf_path',
        'pdf_generated_at',
        'pdf_hash',
        'is_locked',
        'locked_at',
        'locked_by',
        'company_profile_id',
        'formatted_invoice_number',
        'requires_tax_invoice',
        'tax_status',
        // EXISTING TAX FIELDS
        'tax_rate',
        'tax_type',
        'tax_calculation',
        'tax_included',
        'fiscal_year',
        'fiscal_month',
        'buyer_npwp',
        'buyer_npwp_name',
        'buyer_npwp_address',
        'buyer_is_pkp',
        'seller_npwp',
        'seller_npwp_name',
        'seller_npwp_address',
        'efaktur_number',
        'efaktur_generated_at',
        'is_tax_invoice',
        'tax_locked',
        // BUSINESS SNAPSHOT
        'billing_snapshot',
        'snapshot_business_name',
        'snapshot_business_type',
        'snapshot_npwp',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'grace_period_notified' => 'boolean',
        'line_items' => 'array',
        'metadata' => 'array',
        // NEW TAX CASTS
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_calculated' => 'decimal:2',
        'tax_snapshot' => 'array',
        'pdf_generated_at' => 'datetime',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
        'requires_tax_invoice' => 'boolean',
        // EXISTING TAX CASTS
        'tax_rate' => 'decimal:2',
        'buyer_is_pkp' => 'boolean',
        'efaktur_generated_at' => 'datetime',
        'is_tax_invoice' => 'boolean',
        'tax_locked' => 'boolean',
        'tax_included' => 'boolean',
        'fiscal_year' => 'integer',
        'fiscal_month' => 'integer',
        // BUSINESS SNAPSHOT
        'billing_snapshot' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Related wallet transaction (for topup invoices)
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class)->orderBy('created_at', 'desc');
    }

    /**
     * Polymorphic relation to invoiceable entity
     */
    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Invoice items (detailed line items)
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->ordered();
    }

    /**
     * Audit logs
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(InvoiceAuditLog::class)->orderBy('created_at', 'desc');
    }

    /**
     * Company profile used for this invoice
     */
    public function companyProfile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class);
    }

    /**
     * User who locked the invoice
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    // ==================== SCOPES ====================

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_EXPIRED]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('due_at', '<', now());
    }

    public function scopeInGracePeriod(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '>', now());
    }

    public function scopeGracePeriodExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED)
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', now());
    }

    public function scopeSubscription(Builder $query): Builder
    {
        return $query->whereIn('type', [
            self::TYPE_SUBSCRIPTION,
            self::TYPE_SUBSCRIPTION_UPGRADE,
            self::TYPE_SUBSCRIPTION_RENEWAL,
        ]);
    }

    public function scopeTopup(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_TOPUP);
    }

    // ==================== ACCESSORS ====================

    public function getIsPaidAttribute(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_PENDING 
            && $this->due_at 
            && $this->due_at->isPast();
    }

    public function getIsInGracePeriodAttribute(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            && $this->grace_period_ends_at
            && $this->grace_period_ends_at->isFuture();
    }

    public function getGracePeriodDaysLeftAttribute(): ?int
    {
        if (!$this->is_in_grace_period) {
            return null;
        }
        return (int) now()->diffInDays($this->grace_period_ends_at, false);
    }

    public function getFormattedTotalAttribute(): string
    {
        return 'Rp ' . number_format($this->total, 0, ',', '.');
    }

    public function getLatestPaymentAttribute(): ?Payment
    {
        return $this->payments()->latest()->first();
    }

    // ==================== STATIC METHODS ====================

    /**
     * Generate unique invoice number
     */
    public static function generateInvoiceNumber(): string
    {
        $date = now()->format('Ymd');
        $count = static::whereDate('created_at', today())->count() + 1;
        $sequence = str_pad($count, 5, '0', STR_PAD_LEFT);
        
        return "INV-{$date}-{$sequence}";
    }

    /**
     * Create invoice for subscription
     * 
     * SNAPSHOT CAPTURE:
     * - Mengambil snapshot bisnis saat invoice dibuat
     * - Snapshot immutable (tidak berubah walaupun profil bisnis diupdate)
     * - Untuk audit trail, PPN, dan compliance
     */
    public static function createForSubscription(
        Klien $klien,
        Subscription $subscription,
        string $type = self::TYPE_SUBSCRIPTION
    ): self {
        $snapshot = $subscription->plan_snapshot;
        $price = $subscription->price;

        // Capture business snapshot (IMMUTABLE)
        $klien->load(['taxProfile', 'businessType']);
        $businessSnapshot = $klien->generateBusinessSnapshot();

        $invoice = new static();
        $invoice->invoice_number = static::generateInvoiceNumber();
        $invoice->klien_id = $klien->id;
        $invoice->type = $type;
        $invoice->invoiceable_id = $subscription->id;
        $invoice->invoiceable_type = Subscription::class;
        $invoice->subtotal = $price;
        $invoice->discount = 0;
        $invoice->tax = 0;
        $invoice->total = $price;
        $invoice->status = self::STATUS_DRAFT;
        
        // Store business snapshot (IMMUTABLE)
        $invoice->billing_snapshot = $businessSnapshot;
        $invoice->snapshot_business_name = $businessSnapshot['business_name'];
        $invoice->snapshot_business_type = $businessSnapshot['business_type_code'];
        $invoice->snapshot_npwp = $businessSnapshot['npwp'] ?? null;
        
        $invoice->line_items = [
            [
                'name' => $snapshot['name'] ?? 'Subscription',
                'description' => $snapshot['description'] ?? '',
                'qty' => 1,
                'price' => $price,
                'total' => $price,
            ]
        ];
        $invoice->save();

        // Log event
        $invoice->logEvent('created', null, self::STATUS_DRAFT, [
            'subscription_id' => $subscription->id,
            'plan_name' => $snapshot['name'] ?? 'Unknown',
            'business_snapshot_captured' => true,
        ]);

        return $invoice;
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Send invoice (transition to pending)
     */
    public function send(int $dueDays = 1): self
    {
        $fromStatus = $this->status;
        
        $this->status = self::STATUS_PENDING;
        $this->issued_at = now();
        $this->due_at = now()->addDays($dueDays);
        $this->save();

        $this->logEvent('sent', $fromStatus, self::STATUS_PENDING, [
            'due_at' => $this->due_at->toIso8601String(),
        ]);

        return $this;
    }

    /**
     * Mark as paid
     */
    public function markPaid(
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        array $metadata = []
    ): self {
        $fromStatus = $this->status;

        $this->status = self::STATUS_PAID;
        $this->paid_at = now();
        $this->payment_method = $paymentMethod;
        $this->payment_channel = $paymentChannel;
        
        if (!empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }
        
        $this->save();

        $this->logEvent('paid', $fromStatus, self::STATUS_PAID, [
            'payment_method' => $paymentMethod,
            'payment_channel' => $paymentChannel,
        ]);

        return $this;
    }

    /**
     * Mark as expired (with grace period)
     */
    public function markExpired(bool $withGracePeriod = true): self
    {
        $fromStatus = $this->status;

        $this->status = self::STATUS_EXPIRED;
        $this->expired_at = now();
        
        if ($withGracePeriod) {
            $this->grace_period_ends_at = now()->addDays(self::GRACE_PERIOD_DAYS);
        }
        
        $this->save();

        $this->logEvent('expired', $fromStatus, self::STATUS_EXPIRED, [
            'grace_period_ends_at' => $this->grace_period_ends_at?->toIso8601String(),
        ]);

        return $this;
    }

    /**
     * Cancel invoice
     */
    public function cancel(string $reason = null): self
    {
        $fromStatus = $this->status;

        $this->status = self::STATUS_CANCELLED;
        $this->notes = $reason;
        $this->save();

        $this->logEvent('cancelled', $fromStatus, self::STATUS_CANCELLED, [
            'reason' => $reason,
        ]);

        return $this;
    }

    /**
     * Mark as refunded
     */
    public function markRefunded(array $refundData = []): self
    {
        $fromStatus = $this->status;

        $this->status = self::STATUS_REFUNDED;
        $this->metadata = array_merge($this->metadata ?? [], ['refund' => $refundData]);
        $this->save();

        $this->logEvent('refunded', $fromStatus, self::STATUS_REFUNDED, $refundData);

        return $this;
    }

    /**
     * Log invoice event
     */
    public function logEvent(
        string $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $data = [],
        string $source = 'system',
        ?int $userId = null
    ): InvoiceEvent {
        return InvoiceEvent::create([
            'invoice_id' => $this->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $userId,
            'source' => $source,
            'data' => $data,
        ]);
    }

    /**
     * Check if can be paid
     */
    public function canBePaid(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_EXPIRED, // Allow payment during grace period
        ]);
    }

    /**
     * Get active payment (pending/processing)
     */
    public function getActivePayment(): ?Payment
    {
        return $this->payments()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->latest()
            ->first();
    }

    /**
     * Get successful payment
     */
    public function getSuccessfulPayment(): ?Payment
    {
        return $this->payments()
            ->where('status', Payment::STATUS_SUCCESS)
            ->first();
    }

    // ==================== TAX METHODS ====================

    /**
     * E-Invoice relationship
     */
    public function eInvoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EInvoice::class);
    }

    /**
     * Get client tax profile (via klien)
     */
    public function getTaxProfile(): ?ClientTaxProfile
    {
        return $this->klien?->taxProfile;
    }

    /**
     * Check if invoice tax is locked (cannot modify)
     */
    public function isTaxLocked(): bool
    {
        return $this->tax_locked || $this->status === self::STATUS_PAID;
    }

    /**
     * Check if tax can be calculated/modified
     */
    public function canModifyTax(): bool
    {
        if ($this->isTaxLocked()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
        ]);
    }

    /**
     * Lock tax (prevent further modification)
     */
    public function lockTax(): self
    {
        $this->tax_locked = true;
        $this->save();
        return $this;
    }

    /**
     * Apply tax calculation
     * 
     * @throws \Exception if tax is locked
     */
    public function applyTax(
        float $taxRate,
        string $taxType = 'ppn',
        string $taxCalculation = 'exclusive'
    ): self {
        if (!$this->canModifyTax()) {
            throw new \Exception('Invoice tax is locked and cannot be modified');
        }

        $this->tax_rate = $taxRate;
        $this->tax_type = $taxType;
        $this->tax_calculation = $taxCalculation;

        if ($taxCalculation === 'exclusive') {
            // Tax tambahan (price + tax)
            $this->tax = round($this->subtotal * ($taxRate / 100), 2);
            $this->total = $this->subtotal + $this->tax - $this->discount;
        } else {
            // Inclusive - extract tax dari total
            $this->tax = round($this->subtotal - ($this->subtotal / (1 + ($taxRate / 100))), 2);
            $this->total = $this->subtotal - $this->discount;
        }

        return $this;
    }

    /**
     * Apply buyer (client) tax info snapshot
     */
    public function snapshotBuyerTaxInfo(?ClientTaxProfile $taxProfile = null): self
    {
        $taxProfile = $taxProfile ?? $this->getTaxProfile();

        if ($taxProfile) {
            $this->buyer_npwp = $taxProfile->npwp;
            $this->buyer_npwp_name = $taxProfile->npwp_name ?? $taxProfile->entity_name;
            $this->buyer_npwp_address = $taxProfile->npwp_address ?? $taxProfile->entity_address;
            $this->buyer_is_pkp = $taxProfile->is_pkp;
        }

        return $this;
    }

    /**
     * Apply seller tax info
     */
    public function snapshotSellerTaxInfo(TaxSettings $settings): self
    {
        $this->seller_npwp = $settings->company_npwp;
        $this->seller_npwp_name = $settings->company_name;
        $this->seller_npwp_address = $settings->company_address;
        return $this;
    }

    /**
     * Mark as formal tax invoice
     */
    public function markAsTaxInvoice(): self
    {
        $this->is_tax_invoice = true;
        return $this;
    }

    /**
     * Get tax breakdown for display (legacy method - use getTaxBreakdownFromSnapshot for immutable invoices)
     */
    public function getTaxBreakdown(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'dpp' => $this->subtotal - $this->discount, // Dasar Pengenaan Pajak
            'tax_rate' => $this->tax_rate ?? 0,
            'tax_type' => $this->tax_type ?? 'ppn',
            'tax_calculation' => $this->tax_calculation ?? 'exclusive',
            'tax_amount' => $this->tax,
            'total' => $this->total,
            'buyer_npwp' => $this->buyer_npwp,
            'seller_npwp' => $this->seller_npwp,
            'is_tax_invoice' => $this->is_tax_invoice,
            'efaktur_number' => $this->efaktur_number,
        ];
    }

    // ==================== NEW TAX & IMMUTABILITY METHODS ====================

    /**
     * Check if invoice is immutable (locked after paid)
     */
    public function getIsImmutableAttribute(): bool
    {
        return $this->is_locked || $this->status === self::STATUS_PAID;
    }

    /**
     * Lock invoice to prevent further modifications
     */
    public function lockInvoice(string $reason = 'Paid status achieved'): bool
    {
        if ($this->is_immutable) {
            return true; // Already locked
        }

        $this->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => auth()->id()
        ]);

        InvoiceAuditLog::logEvent(
            $this->id,
            'locked',
            null,
            ['is_locked' => true],
            ['reason' => $reason]
        );

        return true;
    }

    /**
     * Calculate and set tax snapshot (CRITICAL - must be called before payment)
     */
    public function calculateAndSnapshotTax(?CompanyProfile $companyProfile = null): array
    {
        if ($this->is_immutable) {
            throw new \Exception('Cannot modify tax on immutable invoice');
        }

        $companyProfile = $companyProfile ?: CompanyProfile::getOrCreateForUser($this->user_id);
        $taxSettings = TaxSetting::getTaxCalculationSettings($this->user_id);
        
        // Get applicable tax rule
        $taxRule = TaxRule::getDefaultPpn();
        if (!$taxRule) {
            throw new \Exception('No applicable tax rule found');
        }

        // Calculate tax
        $calculation = $taxRule->calculateTax($this->subtotal);
        
        // Create snapshot
        $snapshot = [
            'calculation_timestamp' => now()->toISOString(),
            'tax_rule' => [
                'rule_code' => $taxRule->rule_code,
                'rule_name' => $taxRule->rule_name,
                'tax_type' => $taxRule->tax_type,
                'rate' => $taxRule->rate,
                'is_inclusive' => $taxRule->is_inclusive
            ],
            'amounts' => [
                'subtotal' => $this->subtotal,
                'discount_amount' => $this->discount_amount ?: 0,
                'tax_amount' => $calculation['tax_amount'],
                'admin_fee' => $this->admin_fee ?: 0,
                'total_calculated' => $calculation['total_amount'] + ($this->admin_fee ?: 0) - ($this->discount_amount ?: 0)
            ],
            'tax_details' => $calculation,
            'company_info' => [
                'company_name' => $companyProfile->company_name,
                'npwp' => $companyProfile->npwp,
                'is_pkp' => $companyProfile->is_pkp,
                'address' => $companyProfile->formatted_address
            ],
            'client_info' => [
                'client_name' => $this->klien->nama_perusahaan ?? $this->klien->name,
                'client_email' => $this->klien->email,
                'npwp' => $this->klien->npwp ?? null
            ],
            'settings_used' => $taxSettings
        ];

        // Update invoice with calculated values
        $this->update([
            'tax_rate' => $taxRule->rate,
            'tax_amount' => $calculation['tax_amount'],
            'total_calculated' => $snapshot['amounts']['total_calculated'],
            'tax_snapshot' => $snapshot,
            'company_profile_id' => $companyProfile->id,
            'formatted_invoice_number' => $companyProfile->generateInvoiceNumber(),
            'requires_tax_invoice' => $companyProfile->is_pkp,
            'tax_status' => 'calculated'
        ]);

        // Log the tax calculation
        InvoiceAuditLog::logEvent(
            $this->id,
            'tax_calculated',
            null,
            $snapshot,
            ['tax_rule_used' => $taxRule->rule_code]
        );

        return $snapshot;
    }

    /**
     * Mark invoice as paid and lock it (IMMUTABLE after this point)
     */
    public function markPaidAndLock(
        ?string $paymentMethod = null,
        ?string $paymentChannel = null,
        array $metadata = []
    ): self {
        if ($this->is_immutable) {
            throw new \Exception('Invoice is already immutable and cannot be modified');
        }

        $fromStatus = $this->status;

        // Ensure tax is calculated and snapshot exists
        if (!$this->tax_snapshot) {
            $this->calculateAndSnapshotTax();
        }

        // Mark as paid
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
            'payment_method' => $paymentMethod,
            'payment_channel' => $paymentChannel,
            'tax_status' => 'invoiced',
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => auth()->id()
        ]);

        if (!empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
            $this->save();
        }

        // Log the payment and lock
        InvoiceAuditLog::logEvent(
            $this->id,
            'paid_and_locked',
            [$fromStatus, self::STATUS_PAID],
            [
                'payment_method' => $paymentMethod,
                'payment_channel' => $paymentChannel,
                'locked_automatically' => true
            ],
            $metadata
        );

        $this->logEvent('paid', $fromStatus, self::STATUS_PAID, [
            'payment_method' => $paymentMethod,
            'payment_channel' => $paymentChannel,
        ]);

        return $this;
    }

    /**
     * Generate PDF invoice (only after PAID status)
     */
    public function generatePdfInvoice(): string
    {
        if (!$this->is_paid) {
            throw new \Exception('PDF can only be generated for paid invoices');
        }

        if (!$this->tax_snapshot) {
            throw new \Exception('Cannot generate PDF: tax snapshot missing');
        }

        // Use PDF generation service
        $pdfService = app(\App\Services\InvoicePdfService::class);
        $pdfPath = $pdfService->generatePdf($this);
        
        $this->update([
            'pdf_path' => $pdfPath,
            'pdf_generated_at' => now(),
            'pdf_hash' => hash_file('sha256', storage_path('app/' . $pdfPath))
        ]);

        InvoiceAuditLog::logEvent(
            $this->id,
            'pdf_generated',
            null,
            ['pdf_path' => $pdfPath],
            ['generation_method' => 'automated']
        );

        return $pdfPath;
    }

    /**
     * Get tax breakdown from snapshot (read-only)
     */
    public function getTaxBreakdownFromSnapshot(): array
    {
        if (!$this->tax_snapshot) {
            return [
                'subtotal' => $this->subtotal,
                'tax_amount' => $this->tax_amount ?: 0,
                'total' => $this->total_calculated ?: $this->total,
                'tax_rate' => $this->tax_rate ?: 0,
                'source' => 'calculated'
            ];
        }

        $snapshot = $this->tax_snapshot;
        return [
            'subtotal' => $snapshot['amounts']['subtotal'],
            'discount_amount' => $snapshot['amounts']['discount_amount'],
            'tax_amount' => $snapshot['amounts']['tax_amount'],
            'admin_fee' => $snapshot['amounts']['admin_fee'],
            'total_calculated' => $snapshot['amounts']['total_calculated'],
            'tax_rate' => $snapshot['tax_rule']['rate'],
            'tax_type' => $snapshot['tax_rule']['tax_type'],
            'rule_name' => $snapshot['tax_rule']['rule_name'],
            'calculation_timestamp' => $snapshot['calculation_timestamp'],
            'source' => 'snapshot'
        ];
    }

    /**
     * Validate against unauthorized modifications
     */
    public function validateIntegrity(): array
    {
        $issues = [];

        if ($this->is_paid && !$this->is_locked) {
            $issues[] = 'Paid invoice should be locked';
        }

        if ($this->is_paid && !$this->tax_snapshot) {
            $issues[] = 'Paid invoice missing tax snapshot';
        }

        if ($this->pdf_path && $this->pdf_hash) {
            $currentHash = hash_file('sha256', storage_path('app/' . $this->pdf_path));
            if ($currentHash !== $this->pdf_hash) {
                $issues[] = 'PDF file integrity compromised';
            }
        }

        return $issues;
    }
}
