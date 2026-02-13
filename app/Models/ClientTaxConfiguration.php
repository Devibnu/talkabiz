<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClientTaxConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'tax_rule_id',
        'is_enabled',
        'custom_rate',
        'custom_settings',
        'notes',
        'configured_by'
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'custom_rate' => 'decimal:4',
        'custom_settings' => 'array'
    ];

    // ==================== RELATIONSHIPS ====================

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class);
    }

    public function configurer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }

    // ==================== BUSINESS METHODS ====================

    public function getEffectiveRate(): float
    {
        return $this->custom_rate ?? $this->taxRule->rate;
    }

    public function calculateTax(float $amount): array
    {
        if (!$this->is_enabled) {
            return [
                'applies' => false,
                'base_amount' => $amount,
                'tax_amount' => 0,
                'total_amount' => $amount
            ];
        }

        $rate = $this->getEffectiveRate() / 100;
        $taxAmount = $amount * $rate;

        return [
            'applies' => true,
            'base_amount' => $amount,
            'tax_amount' => $taxAmount,
            'total_amount' => $amount + $taxAmount,
            'tax_rate' => $this->getEffectiveRate(),
            'rule_name' => $this->taxRule->rule_name,
            'is_custom_rate' => !is_null($this->custom_rate)
        ];
    }
}