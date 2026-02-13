<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class TaxSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'client_id',
        'setting_key',
        'setting_value',
        'value_type',
        'is_active',
        'description',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Client that this setting belongs to (if scope = client)
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * User who created this setting
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated this setting
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ==================== SCOPES ====================

    /**
     * Filter by scope (global/client)
     */
    public function scopeScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * Filter by client
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Filter active settings only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filter by setting key
     */
    public function scopeKey(Builder $query, string $key): Builder
    {
        return $query->where('setting_key', $key);
    }

    /**
     * Global settings only
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', 'global')->whereNull('client_id');
    }

    /**
     * Client-specific settings only
     */
    public function scopeClientSpecific(Builder $query): Builder
    {
        return $query->where('scope', 'client')->whereNotNull('client_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get typed value based on value_type
     */
    public function getTypedValueAttribute()
    {
        return match($this->value_type) {
            'decimal' => (float) $this->setting_value,
            'integer' => (int) $this->setting_value,
            'boolean' => filter_var($this->setting_value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->setting_value, true),
            default => $this->setting_value
        };
    }

    /**
     * Get display name for setting key
     */
    public function getDisplayNameAttribute(): string
    {
        $displayNames = [
            'default_ppn_rate' => 'Default PPN Rate (%)',
            'ppn_inclusive_default' => 'PPN Inclusive by Default',
            'auto_calculate_tax' => 'Auto Calculate Tax',
            'tax_rounding_mode' => 'Tax Rounding Mode',
            'tax_calculation_precision' => 'Tax Calculation Precision',
            'invoice_tax_display' => 'Invoice Tax Display',
            'require_npwp_for_invoice' => 'Require NPWP for Invoice',
            'minimum_invoice_amount' => 'Minimum Invoice Amount'
        ];

        return $displayNames[$this->setting_key] ?? $this->setting_key;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get setting value with fallback
     */
    public static function getValue(string $key, int $clientId = null, $default = null)
    {
        // Try client-specific first, then global
        $setting = null;
        
        if ($clientId) {
            $setting = self::active()
                ->scope('client')
                ->forClient($clientId)
                ->key($key)
                ->first();
        }

        // Fallback to global setting
        if (!$setting) {
            $setting = self::active()
                ->scope('global')
                ->key($key)
                ->first();
        }

        return $setting ? $setting->typed_value : $default;
    }

    /**
     * Set setting value
     */
    public static function setValue(
        string $key, 
        $value, 
        string $scope = 'global', 
        int $clientId = null,
        string $valueType = 'string'
    ): self {
        $stringValue = match($valueType) {
            'json' => json_encode($value),
            'boolean' => $value ? 'true' : 'false',
            default => (string) $value
        };

        return self::updateOrCreate(
            [
                'scope' => $scope,
                'client_id' => $clientId,
                'setting_key' => $key
            ],
            [
                'setting_value' => $stringValue,
                'value_type' => $valueType,
                'is_active' => true,
                'updated_by' => auth()->id()
            ]
        );
    }

    /**
     * Get all settings for client with global fallback
     */
    public static function getClientSettings(int $clientId): array
    {
        $settings = [];
        
        // Get all global settings first
        $globalSettings = self::active()->global()->get();
        foreach ($globalSettings as $setting) {
            $settings[$setting->setting_key] = $setting->typed_value;
        }
        
        // Override with client-specific settings
        $clientSettings = self::active()->forClient($clientId)->get();
        foreach ($clientSettings as $setting) {
            $settings[$setting->setting_key] = $setting->typed_value;
        }
        
        return $settings;
    }

    /**
     * Get tax calculation settings
     */
    public static function getTaxCalculationSettings(int $clientId = null): array
    {
        $defaultPpnRate = self::getValue('default_ppn_rate', $clientId, 11.00);
        $ppnInclusive = self::getValue('ppn_inclusive_default', $clientId, false);
        $autoCalculate = self::getValue('auto_calculate_tax', $clientId, true);
        $roundingMode = self::getValue('tax_rounding_mode', $clientId, 'round');
        $precision = self::getValue('tax_calculation_precision', $clientId, 2);

        return [
            'default_ppn_rate' => $defaultPpnRate,
            'ppn_inclusive_default' => $ppnInclusive,
            'auto_calculate_tax' => $autoCalculate,
            'tax_rounding_mode' => $roundingMode,
            'tax_calculation_precision' => $precision
        ];
    }

    /**
     * Get invoice generation settings
     */
    public static function getInvoiceSettings(int $clientId = null): array
    {
        return [
            'tax_display' => self::getValue('invoice_tax_display', $clientId, 'breakdown'),
            'require_npwp' => self::getValue('require_npwp_for_invoice', $clientId, false),
            'minimum_amount' => self::getValue('minimum_invoice_amount', $clientId, 0.00)
        ];
    }

    /**
     * Validate setting value
     */
    public static function validateSettingValue(string $key, $value, string $valueType): bool
    {
        return match($key) {
            'default_ppn_rate' => is_numeric($value) && $value >= 0 && $value <= 100,
            'tax_calculation_precision' => is_int($value) && $value >= 0 && $value <= 10,
            'minimum_invoice_amount' => is_numeric($value) && $value >= 0,
            'tax_rounding_mode' => in_array($value, ['round', 'floor', 'ceil']),
            'invoice_tax_display' => in_array($value, ['breakdown', 'inclusive', 'exclusive']),
            default => true // Default validation passes
        };
    }

    /**
     * Get available setting keys with their metadata
     */
    public static function getAvailableSettings(): array
    {
        return [
            'default_ppn_rate' => [
                'type' => 'decimal',
                'default' => 11.00,
                'validation' => 'numeric|min:0|max:100',
                'description' => 'Default PPN rate percentage'
            ],
            'ppn_inclusive_default' => [
                'type' => 'boolean',
                'default' => false,
                'validation' => 'boolean',
                'description' => 'Whether PPN is included in price by default'
            ],
            'auto_calculate_tax' => [
                'type' => 'boolean',
                'default' => true,
                'validation' => 'boolean',
                'description' => 'Automatically calculate tax for invoices'
            ],
            'tax_rounding_mode' => [
                'type' => 'string',
                'default' => 'round',
                'validation' => 'in:round,floor,ceil',
                'description' => 'How to round tax calculations'
            ],
            'tax_calculation_precision' => [
                'type' => 'integer',
                'default' => 2,
                'validation' => 'integer|min:0|max:10',
                'description' => 'Decimal precision for tax calculations'
            ],
            'invoice_tax_display' => [
                'type' => 'string',
                'default' => 'breakdown',
                'validation' => 'in:breakdown,inclusive,exclusive',
                'description' => 'How to display tax in invoices'
            ],
            'require_npwp_for_invoice' => [
                'type' => 'boolean',
                'default' => false,
                'validation' => 'boolean',
                'description' => 'Require NPWP for invoice generation'
            ],
            'minimum_invoice_amount' => [
                'type' => 'decimal',
                'default' => 0.00,
                'validation' => 'numeric|min:0',
                'description' => 'Minimum amount required for invoice generation'
            ]
        ];
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Validate setting value before saving
        static::saving(function ($setting) {
            if (!self::validateSettingValue($setting->setting_key, $setting->typed_value, $setting->value_type)) {
                throw new \Exception("Invalid value for setting '{$setting->setting_key}'");
            }
        });

        // Log all setting changes
        static::saved(function ($setting) {
            activity()
                ->performedOn($setting)
                ->withProperties([
                    'scope' => $setting->scope,
                    'client_id' => $setting->client_id,
                    'setting_key' => $setting->setting_key,
                    'old_value' => $setting->getOriginal('setting_value'),
                    'new_value' => $setting->setting_value
                ])
                ->log('tax_setting_changed');
        });
    }
}