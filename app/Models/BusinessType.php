<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BusinessType Model - Master Data Tipe Bisnis
 * 
 * ARCHITECTURE RULES:
 * - NO hard delete (only soft disable via is_active)
 * - code MUST be unique, uppercase, snake_case
 * - Used for Klien tipe_bisnis categorization
 * 
 * @property int $id
 * @property string $code Unique uppercase code (e.g., PERORANGAN, CV, PT)
 * @property string $name Display name (e.g., Perorangan / UMKM)
 * @property string|null $description Optional description
 * @property bool $is_active Active status (default: true)
 * @property int $display_order Sort order for display
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BusinessType extends Model
{
    use HasFactory;

    protected $table = 'business_types';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'display_order',
        'pricing_multiplier',
        'risk_level',
        'minimum_balance_buffer',
        'requires_manual_approval',
        'default_limits',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'pricing_multiplier' => 'decimal:2',
        'minimum_balance_buffer' => 'integer',
        'requires_manual_approval' => 'boolean',
        'default_limits' => 'array',
    ];

    /**
     * Scope to get only active business types
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    /**
     * Get klien using this business type
     */
    public function kliens()
    {
        return $this->hasMany(Klien::class, 'tipe_bisnis', 'code');
    }

    /**
     * Check if business type can be deactivated
     * Cannot deactivate if there are active kliens using it
     */
    public function canBeDeactivated(): bool
    {
        return $this->kliens()->where('status', 'aktif')->count() === 0;
    }

    /**
     * Set code attribute - normalize to lowercase for klien ENUM compatibility
     * 
     * COMPATIBILITY NOTE:
     * - Current klien.tipe_bisnis uses lowercase ENUM: ['perorangan', 'cv', 'pt', 'ud', 'lainnya']
     * - Store codes in lowercase to match existing schema
     */
    public function setCodeAttribute($value)
    {
        $this->attributes['code'] = strtolower(trim($value));
    }

    /**
     * Get default limits with fallback values.
     * Ensures backward compatibility.
     */
    public function getDefaultLimits(): array
    {
        return $this->default_limits ?? [
            'max_active_campaign' => 1,
            'daily_message_quota' => 100,
            'monthly_message_quota' => 1000,
            'campaign_send_enabled' => true,
        ];
    }

    /**
     * Check if business type has custom default limits.
     */
    public function hasCustomLimits(): bool
    {
        return !empty($this->default_limits);
    }
}
