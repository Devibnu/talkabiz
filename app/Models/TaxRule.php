<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class TaxRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_code',
        'rule_name',
        'description',
        'tax_type',
        'rate',
        'minimum_amount',
        'maximum_amount',
        'is_inclusive',
        'calculation_rules',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by'
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'minimum_amount' => 'decimal:2',
        'maximum_amount' => 'decimal:2',
        'is_inclusive' => 'boolean',
        'is_active' => 'boolean',
        'calculation_rules' => 'array',
        'effective_from' => 'date',
        'effective_until' => 'date'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User who created this tax rule
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Client configurations for this tax rule
     */
    public function clientConfigurations(): HasMany
    {
        return $this->hasMany(ClientTaxConfiguration::class);
    }

    // ==================== SCOPES ====================

    /**
     * Active tax rules only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Effective tax rules (within date range)
     */
    public function scopeEffective(Builder $query, Carbon $date = null): Builder
    {
        $date = $date ?: now();
        
        return $query->where('effective_from', '<=', $date)
                    ->where(function ($q) use ($date) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>=', $date);
                    });
    }

    /**
     * Filter by tax type
     */
    public function scopeTaxType(Builder $query, string $type): Builder
    {
        return $query->where('tax_type', $type);
    }

    /**
     * Filter by minimum amount threshold
     */
    public function scopeForAmount(Builder $query, float $amount): Builder
    {
        return $query->where('minimum_amount', '<=', $amount)
                    ->where(function ($q) use ($amount) {
                        $q->whereNull('maximum_amount')
                          ->orWhere('maximum_amount', '>=', $amount);
                    });
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted rate as percentage
     */
    public function getFormattedRateAttribute(): string
    {
        return number_format($this->rate, 2) . '%';
    }

    /**
     * Get tax type label
     */
    public function getTaxTypeLabelAttribute(): string
    {
        $labels = [
            'ppn' => 'PPN (Pajak Pertambahan Nilai)',
            'pph21' => 'PPh 21 (Pajak Penghasilan Pasal 21)',
            'pph22' => 'PPh 22 (Pajak Penghasilan Pasal 22)',
            'pph23' => 'PPh 23 (Pajak Penghasilan Pasal 23)',
            'pph4ayat2' => 'PPh Final Pasal 4 Ayat 2',
            'custom' => 'Pajak Khusus'
        ];

        return $labels[$this->tax_type] ?? $this->tax_type;
    }

    /**
     * Get inclusive/exclusive label
     */
    public function getInclusiveLabel(): string
    {
        return $this->is_inclusive ? 'Inclusive' : 'Exclusive';
    }

    /**
     * Check if rule is currently effective
     */
    public function getIsCurrentlyEffectiveAttribute(): bool
    {
        $now = now();
        
        return $this->is_active && 
               $this->effective_from <= $now &&
               ($this->effective_until === null || $this->effective_until >= $now);
    }

    /**
     * Get formatted amount range
     */
    public function getAmountRangeAttribute(): string
    {
        $min = 'Rp ' . number_format($this->minimum_amount, 0, ',', '.');
        
        if ($this->maximum_amount) {
            $max = 'Rp ' . number_format($this->maximum_amount, 0, ',', '.');
            return "{$min} - {$max}";
        }
        
        return $min . ' ke atas';
    }

    // ==================== BUSINESS METHODS ====================

    /**
     * Check if rule applies to given amount
     */
    public function appliesTo(float $amount): bool
    {
        if (!$this->is_currently_effective) {
            return false;
        }

        if ($amount < $this->minimum_amount) {
            return false;
        }

        if ($this->maximum_amount && $amount > $this->maximum_amount) {
            return false;
        }

        return true;
    }

    /**
     * Calculate tax amount for given base amount
     */
    public function calculateTax(float $baseAmount): array
    {
        if (!$this->appliesTo($baseAmount)) {
            return [
                'applies' => false,
                'base_amount' => $baseAmount,
                'tax_amount' => 0,
                'total_amount' => $baseAmount,
                'tax_rate' => 0,
                'calculation_method' => 'not_applicable'
            ];
        }

        $taxRate = $this->rate / 100; // Convert percentage to decimal
        $rules = $this->calculation_rules ?: [];
        $roundingMode = $rules['rounding'] ?? 'round';
        $precision = $rules['precision'] ?? 2;

        if ($this->is_inclusive) {
            // Tax is included in the amount
            $taxAmount = $baseAmount * $taxRate / (1 + $taxRate);
            $netAmount = $baseAmount - $taxAmount;
            $totalAmount = $baseAmount;
        } else {
            // Tax is added to the amount
            $taxAmount = $baseAmount * $taxRate;
            $netAmount = $baseAmount;
            $totalAmount = $baseAmount + $taxAmount;
        }

        // Apply rounding
        $taxAmount = $this->applyRounding($taxAmount, $roundingMode, $precision);

        return [
            'applies' => true,
            'rule_code' => $this->rule_code,
            'rule_name' => $this->rule_name,
            'tax_type' => $this->tax_type,
            'base_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $this->is_inclusive ? $baseAmount : $netAmount + $taxAmount,
            'tax_rate' => $this->rate,
            'is_inclusive' => $this->is_inclusive,
            'calculation_method' => $this->is_inclusive ? 'inclusive' : 'exclusive',
            'calculation_details' => [
                'original_amount' => $baseAmount,
                'tax_rate_decimal' => $taxRate,
                'rounding_mode' => $roundingMode,
                'precision' => $precision
            ]
        ];
    }

    /**
     * Apply rounding based on rules
     */
    protected function applyRounding(float $amount, string $mode = 'round', int $precision = 2): float
    {
        $multiplier = pow(10, $precision);
        
        return match($mode) {
            'floor' => floor($amount * $multiplier) / $multiplier,
            'ceil' => ceil($amount * $multiplier) / $multiplier,
            default => round($amount, $precision)
        };
    }

    /**
     * Get calculation preview for amount
     */
    public function getCalculationPreview(float $amount): array
    {
        $calculation = $this->calculateTax($amount);
        
        return array_merge($calculation, [
            'preview_text' => $this->generatePreviewText($calculation),
            'breakdown' => $this->generateBreakdown($calculation)
        ]);
    }

    /**
     * Generate human-readable preview text
     */
    protected function generatePreviewText(array $calculation): string
    {
        if (!$calculation['applies']) {
            return "Tax rule tidak berlaku untuk amount Rp " . number_format($calculation['base_amount'], 0, ',', '.');
        }

        $baseFormatted = number_format($calculation['base_amount'], 0, ',', '.');
        $taxFormatted = number_format($calculation['tax_amount'], 0, ',', '.');
        $totalFormatted = number_format($calculation['total_amount'], 0, ',', '.');

        if ($calculation['is_inclusive']) {
            return "Amount Rp {$totalFormatted} sudah termasuk {$this->tax_type_label} {$this->formatted_rate} = Rp {$taxFormatted}";
        } else {
            return "Amount Rp {$baseFormatted} + {$this->tax_type_label} {$this->formatted_rate} = Rp {$taxFormatted}, Total: Rp {$totalFormatted}";
        }
    }

    /**
     * Generate calculation breakdown
     */
    protected function generateBreakdown(array $calculation): array
    {
        if (!$calculation['applies']) {
            return [];
        }

        $breakdown = [];

        if ($calculation['is_inclusive']) {
            $breakdown[] = [
                'label' => 'Amount (termasuk pajak)',
                'amount' => $calculation['total_amount']
            ];
            $breakdown[] = [
                'label' => "Pajak {$this->tax_type_label} ({$this->formatted_rate})",
                'amount' => $calculation['tax_amount']
            ];
            $breakdown[] = [
                'label' => 'Amount sebelum pajak',
                'amount' => $calculation['base_amount']
            ];
        } else {
            $breakdown[] = [
                'label' => 'Amount sebelum pajak',
                'amount' => $calculation['base_amount']
            ];
            $breakdown[] = [
                'label' => "Pajak {$this->tax_type_label} ({$this->formatted_rate})",
                'amount' => $calculation['tax_amount']
            ];
            $breakdown[] = [
                'label' => 'Total Amount',
                'amount' => $calculation['total_amount']
            ];
        }

        return $breakdown;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Find applicable tax rules for amount
     */
    public static function findApplicableRules(float $amount, string $taxType = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = self::active()->effective()->forAmount($amount);
        
        if ($taxType) {
            $query->taxType($taxType);
        }
        
        return $query->orderBy('rate', 'desc')->get();
    }

    /**
     * Get default PPN rule
     */
    public static function getDefaultPpn(): ?self
    {
        return self::active()
                   ->effective()
                   ->taxType('ppn')
                   ->where('rule_code', 'PPN_STANDARD')
                   ->first();
    }

    /**
     * Get rule by code
     */
    public static function getByCode(string $code): ?self
    {
        return self::active()
                   ->effective()
                   ->where('rule_code', $code)
                   ->first();
    }

    /**
     * Calculate tax using best applicable rule
     */
    public static function calculateBestTax(float $amount, string $preferredType = 'ppn'): array
    {
        $rule = self::findApplicableRules($amount, $preferredType)->first();
        
        if (!$rule) {
            return [
                'applies' => false,
                'base_amount' => $amount,
                'tax_amount' => 0,
                'total_amount' => $amount,
                'message' => 'No applicable tax rule found'
            ];
        }

        return $rule->calculateTax($amount);
    }

    /**
     * Get tax rules summary for admin
     */
    public static function getSummary(): array
    {
        $rules = self::active()->effective()->get();
        
        return [
            'total_rules' => $rules->count(),
            'by_type' => $rules->groupBy('tax_type')->map->count(),
            'average_rate' => $rules->avg('rate'),
            'highest_rate' => $rules->max('rate'),
            'lowest_rate' => $rules->min('rate')
        ];
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Ensure rule code is unique
        static::saving(function ($rule) {
            $exists = self::where('rule_code', $rule->rule_code)
                         ->where('id', '!=', $rule->id)
                         ->exists();
                         
            if ($exists) {
                throw new \Exception("Tax rule code '{$rule->rule_code}' already exists");
            }
        });

        // Log tax rule changes
        static::saved(function ($rule) {
            activity()
                ->performedOn($rule)
                ->withProperties([
                    'rule_code' => $rule->rule_code,
                    'tax_type' => $rule->tax_type,
                    'rate' => $rule->rate,
                    'changes' => $rule->getChanges()
                ])
                ->log('tax_rule_saved');
        });
    }
}