<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PlanCostEstimate Model
 *
 * Menyimpan estimasi biaya per kategori untuk simulasi nilai paket.
 * Digunakan untuk menghitung margin dan rekomendasi harga.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $estimate_marketing Estimasi pesan marketing per bulan
 * @property int $estimate_utility Estimasi pesan utility per bulan
 * @property int $estimate_authentication Estimasi pesan authentication per bulan
 * @property int $estimate_service Estimasi pesan service per bulan
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Plan $plan
 */
class PlanCostEstimate extends Model
{
    protected $table = 'plan_cost_estimates';

    protected $fillable = [
        'plan_id',
        'estimate_marketing',
        'estimate_utility',
        'estimate_authentication',
        'estimate_service',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'estimate_marketing' => 'integer',
        'estimate_utility' => 'integer',
        'estimate_authentication' => 'integer',
        'estimate_service' => 'integer',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Plan ini belongs to
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get total estimated messages
     */
    public function getTotalEstimateAttribute(): int
    {
        return $this->estimate_marketing
            + $this->estimate_utility
            + $this->estimate_authentication
            + $this->estimate_service;
    }

    /**
     * Get estimates as array
     */
    public function getEstimatesArrayAttribute(): array
    {
        return [
            'marketing' => $this->estimate_marketing,
            'utility' => $this->estimate_utility,
            'authentication' => $this->estimate_authentication,
            'service' => $this->estimate_service,
        ];
    }
}
