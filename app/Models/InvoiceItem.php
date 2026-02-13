<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'item_code',
        'item_name',
        'item_description',
        'quantity',
        'unit',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'tax_rate',
        'tax_type',
        'is_tax_inclusive',
        'item_metadata',
        'sort_order'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'is_tax_inclusive' => 'boolean',
        'item_metadata' => 'array',
        'sort_order' => 'integer'
    ];

    // ==================== RELATIONSHIPS ====================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // ==================== SCOPES ====================

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // ==================== ACCESSORS ====================

    public function getFormattedUnitPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->unit_price, 0, ',', '.');
    }

    public function getFormattedTotalAmountAttribute(): string
    {
        return 'Rp ' . number_format($this->total_amount, 0, ',', '.');
    }

    // ==================== BUSINESS METHODS ====================

    public function calculateAmounts(): void
    {
        $this->subtotal = $this->quantity * $this->unit_price;
        
        if ($this->tax_rate > 0) {
            if ($this->is_tax_inclusive) {
                $this->tax_amount = $this->subtotal * ($this->tax_rate / 100) / (1 + ($this->tax_rate / 100));
            } else {
                $this->tax_amount = $this->subtotal * ($this->tax_rate / 100);
            }
        } else {
            $this->tax_amount = 0;
        }

        $this->total_amount = $this->subtotal + $this->tax_amount - $this->discount_amount;
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        static::saving(function ($item) {
            $item->calculateAmounts();
        });
    }
}