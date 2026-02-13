<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class CompanyProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'company_code', 
        'address',
        'city',
        'postal_code',
        'phone',
        'email',
        'website',
        'npwp',
        'is_pkp',
        'pkp_number',
        'pkp_date',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'logo_path',
        'signature_path',
        'invoice_prefix',
        'invoice_counter',
        'invoice_number_format',
        'is_active'
    ];

    protected $casts = [
        'is_pkp' => 'boolean',
        'is_active' => 'boolean',
        'pkp_date' => 'date',
        'invoice_counter' => 'integer'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Owner of the company profile
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Invoices using this company profile
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ==================== SCOPES ====================

    /**
     * Active company profiles only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * PKP companies only
     */
    public function scopePkp(Builder $query): Builder
    {
        return $query->where('is_pkp', true);
    }

    /**
     * Filter by user
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted address
     */
    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->postal_code
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get formatted NPWP
     */
    public function getFormattedNpwpAttribute(): ?string
    {
        if (!$this->npwp) {
            return null;
        }

        // Format: XX.XXX.XXX.X-XXX.XXX
        $npwp = preg_replace('/\D/', '', $this->npwp);
        
        if (strlen($npwp) === 15) {
            return substr($npwp, 0, 2) . '.' . 
                   substr($npwp, 2, 3) . '.' . 
                   substr($npwp, 5, 3) . '.' . 
                   substr($npwp, 8, 1) . '-' . 
                   substr($npwp, 9, 3) . '.' . 
                   substr($npwp, 12, 3);
        }

        return $this->npwp;
    }

    /**
     * Get PKP status text
     */
    public function getPkpStatusAttribute(): string
    {
        return $this->is_pkp ? 'PKP' : 'Non-PKP';
    }

    /**
     * Get logo URL
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        if (Storage::disk('public')->exists($this->logo_path)) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return null;
    }

    /**
     * Get signature URL
     */
    public function getSignatureUrlAttribute(): ?string
    {
        if (!$this->signature_path) {
            return null;
        }

        if (Storage::disk('private')->exists($this->signature_path)) {
            return route('invoice.signature', ['company' => $this->id]);
        }

        return null;
    }

    /**
     * Get display name for company
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->company_name ?: ($this->user->name ?? 'Unknown Company');
    }

    /**
     * Check if company has complete tax information
     */
    public function getHasCompleteTaxInfoAttribute(): bool
    {
        if ($this->is_pkp) {
            return !empty($this->npwp) && 
                   !empty($this->pkp_number) && 
                   !empty($this->pkp_date);
        }

        // Non-PKP only needs NPWP (optional but good to have)
        return true;
    }

    // ==================== BUSINESS METHODS ====================

    /**
     * Generate next invoice number
     */
    public function generateInvoiceNumber(): string
    {
        $this->increment('invoice_counter');
        
        $format = $this->invoice_number_format;
        $placeholders = [
            '{prefix}' => $this->invoice_prefix,
            '{year}' => now()->format('Y'),
            '{month}' => now()->format('m'),
            '{counter}' => str_pad($this->invoice_counter, 5, '0', STR_PAD_LEFT),
            '{company_code}' => $this->company_code ?: 'COMP'
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $format);
    }

    /**
     * Reset invoice counter (usually at year end)
     */
    public function resetInvoiceCounter(): bool
    {
        return $this->update(['invoice_counter' => 0]);
    }

    /**
     * Validate NPWP format
     */
    public function validateNpwp(string $npwp): bool
    {
        $cleaned = preg_replace('/\D/', '', $npwp);
        return strlen($cleaned) === 15;
    }

    /**
     * Upload and save logo
     */
    public function uploadLogo(\Illuminate\Http\UploadedFile $file): bool
    {
        try {
            // Delete old logo
            if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
                Storage::disk('public')->delete($this->logo_path);
            }

            // Store new logo
            $path = $file->store('company-logos/' . $this->id, 'public');
            
            return $this->update(['logo_path' => $path]);

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Upload and save signature
     */
    public function uploadSignature(\Illuminate\Http\UploadedFile $file): bool
    {
        try {
            // Delete old signature
            if ($this->signature_path && Storage::disk('private')->exists($this->signature_path)) {
                Storage::disk('private')->delete($this->signature_path);
            }

            // Store new signature
            $path = $file->store('company-signatures/' . $this->id, 'private');
            
            return $this->update(['signature_path' => $path]);

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get company tax settings
     */
    public function getTaxSettings(): array
    {
        return [
            'is_pkp' => $this->is_pkp,
            'npwp' => $this->npwp,
            'pkp_number' => $this->pkp_number,
            'pkp_date' => $this->pkp_date?->format('Y-m-d'),
            'default_tax_rate' => TaxSetting::getValue('default_ppn_rate', $this->user_id, 11.00),
            'tax_inclusive' => TaxSetting::getValue('ppn_inclusive_default', $this->user_id, false)
        ];
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get or create company profile for user
     */
    public static function getOrCreateForUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId],
            [
                'company_name' => User::find($userId)?->name ?? 'My Company',
                'is_active' => true,
                'invoice_prefix' => 'INV',
                'invoice_counter' => 0,
                'invoice_number_format' => '{prefix}/{year}/{month}/{counter}'
            ]
        );
    }

    /**
     * Get default company profile for system
     */
    public static function getSystemDefault(): ?self
    {
        return self::active()->first();
    }

    /**
     * Search companies by name or NPWP
     */
    public static function search(string $query): Builder
    {
        return self::where(function ($q) use ($query) {
            $q->where('company_name', 'like', "%{$query}%")
              ->orWhere('npwp', 'like', "%{$query}%")
              ->orWhere('company_code', 'like', "%{$query}%");
        });
    }

    /**
     * Get companies that need tax compliance review
     */
    public static function needingTaxReview(): Builder
    {
        return self::active()->where(function ($query) {
            $query->where('is_pkp', true)
                  ->where(function ($q) {
                      $q->whereNull('npwp')
                        ->orWhereNull('pkp_number')
                        ->orWhereNull('pkp_date');
                  });
        });
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Generate company code if not provided
        static::creating(function ($company) {
            if (!$company->company_code) {
                $company->company_code = strtoupper(substr($company->company_name, 0, 4)) . rand(100, 999);
            }
        });

        // Log company profile changes
        static::updated(function ($company) {
            if ($company->isDirty(['npwp', 'is_pkp', 'pkp_number'])) {
                activity()
                    ->performedOn($company)
                    ->withProperties([
                        'changes' => $company->getChanges(),
                        'old_values' => array_intersect_key($company->getOriginal(), $company->getChanges())
                    ])
                    ->log('company_tax_info_updated');
            }
        });

        // Clean up files when deleted
        static::deleting(function ($company) {
            if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
                Storage::disk('public')->delete($company->logo_path);
            }
            
            if ($company->signature_path && Storage::disk('private')->exists($company->signature_path)) {
                Storage::disk('private')->delete($company->signature_path);
            }
        });
    }
}