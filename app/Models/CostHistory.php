<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cost History Model
 * 
 * Track perubahan cost dari Gupshup atau sumber lain.
 */
class CostHistory extends Model
{
    protected $table = 'cost_history';

    protected $fillable = [
        'cost_per_message',
        'source',
        'reason',
        'metadata',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'cost_per_message' => 'decimal:2',
        'metadata' => 'array',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    // ==========================================
    // STATIC METHODS
    // ==========================================

    /**
     * Get current active cost
     */
    public static function getCurrentCost(): float
    {
        $current = self::where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        return $current?->cost_per_message ?? 350; // Default 350 IDR
    }

    /**
     * Record new cost
     */
    public static function recordCostChange(
        float $newCost,
        string $source = 'manual',
        ?string $reason = null,
        ?array $metadata = null
    ): self {
        // Close current cost record
        self::whereNull('effective_until')
            ->update(['effective_until' => now()]);

        // Create new record
        return self::create([
            'cost_per_message' => $newCost,
            'source' => $source,
            'reason' => $reason,
            'metadata' => $metadata,
            'effective_from' => now(),
        ]);
    }

    /**
     * Get cost history for period
     */
    public static function getHistory(int $days = 30): array
    {
        return self::where('effective_from', '>=', now()->subDays($days))
            ->orderBy('effective_from', 'desc')
            ->get()
            ->toArray();
    }
}
