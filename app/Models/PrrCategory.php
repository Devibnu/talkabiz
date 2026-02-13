<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRR Category
 * 
 * Kategori checklist untuk Production Readiness Review.
 * 
 * Categories:
 * - environment-config: Environment & Configuration
 * - payment-billing: Payment & Billing
 * - messaging-delivery: Messaging & Delivery
 * - data-safety: Data & Safety
 * - scalability-performance: Scalability & Performance
 * - observability-alerting: Observability & Alerting
 * - security-compliance: Security & Compliance
 * - operational-readiness: Operational Readiness
 * - business-customer: Business & Customer
 */
class PrrCategory extends Model
{
    use HasFactory;

    protected $table = 'prr_categories';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'display_order',
        'icon',
        'owner_role',
        'is_critical',
        'is_active',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function items(): HasMany
    {
        return $this->hasMany(PrrChecklistItem::class, 'category_id');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->where('is_active', true)->orderBy('display_order');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getItemCountAttribute(): int
    {
        return $this->items()->where('is_active', true)->count();
    }

    public function getBlockerCountAttribute(): int
    {
        return $this->items()
            ->where('is_active', true)
            ->where('severity', 'blocker')
            ->count();
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function getAllOrdered(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->ordered()->get();
    }
}
