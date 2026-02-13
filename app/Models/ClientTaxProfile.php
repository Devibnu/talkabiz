<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * ClientTaxProfile Model
 * 
 * Menyimpan data pajak per client (NPWP, PKP status, dll).
 * 
 * NPWP = Nomor Pokok Wajib Pajak
 * PKP = Pengusaha Kena Pajak (wajib buat faktur pajak)
 * 
 * @property int $id
 * @property int $klien_id
 * @property string|null $entity_name
 * @property string|null $npwp
 * @property string|null $npwp_name
 * @property bool $is_pkp
 * @property string|null $pkp_number
 * @property bool $tax_exempt
 * @property string $verification_status
 */
class ClientTaxProfile extends Model
{
    protected $table = 'client_tax_profiles';

    // ==================== CONSTANTS ====================

    const STATUS_PENDING = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';

    // ==================== FILLABLE ====================

    protected $fillable = [
        'klien_id',
        'entity_name',
        'entity_address',
        'npwp',
        'npwp_name',
        'npwp_address',
        'npwp_registered_at',
        'is_pkp',
        'pkp_number',
        'pkp_registered_at',
        'pkp_expired_at',
        'tax_exempt',
        'tax_exempt_reason',
        'custom_tax_rate',
        'tax_contact_name',
        'tax_contact_email',
        'tax_contact_phone',
        'npwp_document_path',
        'pkp_document_path',
        'verified_by',
        'verified_at',
        'verification_status',
        'verification_notes',
    ];

    // ==================== CASTS ====================

    protected $casts = [
        'is_pkp' => 'boolean',
        'tax_exempt' => 'boolean',
        'custom_tax_rate' => 'decimal:2',
        'npwp_registered_at' => 'date',
        'pkp_registered_at' => 'date',
        'pkp_expired_at' => 'date',
        'verified_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ==================== SCOPES ====================

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', self::STATUS_VERIFIED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('verification_status', self::STATUS_PENDING);
    }

    public function scopePkp(Builder $query): Builder
    {
        return $query->where('is_pkp', true);
    }

    public function scopeWithNpwp(Builder $query): Builder
    {
        return $query->whereNotNull('npwp')->where('npwp', '!=', '');
    }

    // ==================== ACCESSORS ====================

    public function getIsVerifiedAttribute(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED;
    }

    public function getHasValidNpwpAttribute(): bool
    {
        return !empty($this->npwp) && strlen($this->npwp) >= 15;
    }

    public function getFormattedNpwpAttribute(): ?string
    {
        if (empty($this->npwp)) {
            return null;
        }
        
        // Format: XX.XXX.XXX.X-XXX.XXX
        $npwp = preg_replace('/[^0-9]/', '', $this->npwp);
        if (strlen($npwp) !== 15) {
            return $this->npwp;
        }
        
        return sprintf(
            '%s.%s.%s.%s-%s.%s',
            substr($npwp, 0, 2),
            substr($npwp, 2, 3),
            substr($npwp, 5, 3),
            substr($npwp, 8, 1),
            substr($npwp, 9, 3),
            substr($npwp, 12, 3)
        );
    }

    public function getIsPkpActiveAttribute(): bool
    {
        if (!$this->is_pkp) {
            return false;
        }
        
        if ($this->pkp_expired_at && $this->pkp_expired_at->isPast()) {
            return false;
        }
        
        return true;
    }

    /**
     * Get effective tax rate for this client
     */
    public function getEffectiveTaxRateAttribute(): float
    {
        if ($this->tax_exempt) {
            return 0.0;
        }
        
        if ($this->custom_tax_rate !== null) {
            return (float) $this->custom_tax_rate;
        }
        
        // Get default rate from tax settings
        $settings = TaxSettings::getActive();
        return $settings?->default_ppn_rate ?? 11.0;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get or create tax profile for klien
     */
    public static function getOrCreateForKlien(int $klienId): self
    {
        return self::firstOrCreate(
            ['klien_id' => $klienId],
            ['verification_status' => self::STATUS_PENDING]
        );
    }

    /**
     * Get tax profile for klien
     */
    public static function forKlien(int $klienId): ?self
    {
        return self::where('klien_id', $klienId)->first();
    }

    // ==================== INSTANCE METHODS ====================

    /**
     * Verify the tax profile
     */
    public function verify(int $userId, ?string $notes = null): self
    {
        $this->verification_status = self::STATUS_VERIFIED;
        $this->verified_by = $userId;
        $this->verified_at = now();
        $this->verification_notes = $notes;
        $this->save();

        return $this;
    }

    /**
     * Reject the tax profile
     */
    public function reject(int $userId, string $reason): self
    {
        $this->verification_status = self::STATUS_REJECTED;
        $this->verified_by = $userId;
        $this->verified_at = now();
        $this->verification_notes = $reason;
        $this->save();

        return $this;
    }

    /**
     * Get data snapshot for invoice
     */
    public function toInvoiceSnapshot(): array
    {
        return [
            'npwp' => $this->npwp,
            'npwp_name' => $this->npwp_name ?? $this->entity_name,
            'npwp_address' => $this->npwp_address ?? $this->entity_address,
            'is_pkp' => $this->is_pkp_active,
            'tax_exempt' => $this->tax_exempt,
            'effective_tax_rate' => $this->effective_tax_rate,
        ];
    }

    /**
     * Validate NPWP format
     */
    public static function isValidNpwp(?string $npwp): bool
    {
        if (empty($npwp)) {
            return false;
        }
        
        $npwp = preg_replace('/[^0-9]/', '', $npwp);
        return strlen($npwp) === 15;
    }
}
