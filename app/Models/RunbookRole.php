<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Runbook Role
 * 
 * Peran dalam operasi SOC/NOC.
 * 
 * Levels:
 * - L1: NOC Operator
 * - L2: SOC Analyst
 * - L3: SRE On-Call
 * - L4: Incident Commander
 * - L5: Business Owner
 */
class RunbookRole extends Model
{
    use HasFactory;

    protected $table = 'runbook_roles';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'level',
        'responsibilities',
        'permissions',
        'escalation_order',
        'response_sla_minutes',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'responsibilities' => 'array',
        'permissions' => 'array',
        'escalation_order' => 'integer',
        'response_sla_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function contacts(): HasMany
    {
        return $this->hasMany(OncallContact::class, 'role_id');
    }

    public function activeContacts(): HasMany
    {
        return $this->contacts()->where('is_active', true);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('escalation_order');
    }

    public function scopeByLevel($query, int $level)
    {
        return $query->where('level', $level);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getLevelLabelAttribute(): string
    {
        return match ($this->level) {
            1 => 'L1',
            2 => 'L2',
            3 => 'L3',
            4 => 'IC',
            5 => 'BIZ',
            default => "L{$this->level}",
        };
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function getEscalationPath(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->ordered()->get();
    }

    public static function getNextEscalation(int $currentLevel): ?self
    {
        return static::active()
            ->where('level', '>', $currentLevel)
            ->orderBy('level')
            ->first();
    }
}
