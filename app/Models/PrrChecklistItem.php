<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRR Checklist Item
 * 
 * Individual checklist item untuk Production Readiness Review.
 * 
 * Severity Levels:
 * - blocker: Must pass - blocks go-live
 * - critical: Should pass - risks if ignored  
 * - major: Important but can defer
 * - minor: Nice to have
 * 
 * Verification Types:
 * - manual: Requires human verification
 * - automated: System can auto-check
 * - semi_automated: System checks, human confirms
 */
class PrrChecklistItem extends Model
{
    use HasFactory;

    protected $table = 'prr_checklist_items';

    protected $fillable = [
        'category_id',
        'slug',
        'title',
        'description',
        'how_to_verify',
        'remediation',
        'verification_type',
        'automated_check',
        'severity',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function category(): BelongsTo
    {
        return $this->belongsTo(PrrCategory::class, 'category_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(PrrReviewResult::class, 'item_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBlockers($query)
    {
        return $query->where('severity', 'blocker');
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('severity', ['blocker', 'critical']);
    }

    public function scopeAutomated($query)
    {
        return $query->whereIn('verification_type', ['automated', 'semi_automated']);
    }

    public function scopeManual($query)
    {
        return $query->where('verification_type', 'manual');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    public function scopeByCategory($query, string $categorySlug)
    {
        return $query->whereHas('category', fn($q) => $q->where('slug', $categorySlug));
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsBlockerAttribute(): bool
    {
        return $this->severity === 'blocker';
    }

    public function getIsCriticalAttribute(): bool
    {
        return in_array($this->severity, ['blocker', 'critical']);
    }

    public function getCanAutoVerifyAttribute(): bool
    {
        return in_array($this->verification_type, ['automated', 'semi_automated']) 
            && !empty($this->automated_check);
    }

    public function getSeverityIconAttribute(): string
    {
        return match ($this->severity) {
            'blocker' => '🚫',
            'critical' => '🔴',
            'major' => '🟡',
            'minor' => '🟢',
            default => '⚪',
        };
    }

    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'blocker' => 'red',
            'critical' => 'orange',
            'major' => 'yellow',
            'minor' => 'green',
            default => 'gray',
        };
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Run automated verification check
     */
    public function runAutomatedCheck(): ?array
    {
        if (!$this->can_auto_verify) {
            return null;
        }

        try {
            // Parse Class::method format
            $parts = explode('::', $this->automated_check);
            if (count($parts) !== 2) {
                return [
                    'passed' => false,
                    'message' => 'Invalid automated_check format',
                    'error' => true,
                ];
            }

            [$class, $method] = $parts;

            if (!class_exists($class)) {
                return [
                    'passed' => false,
                    'message' => "Class {$class} not found",
                    'error' => true,
                ];
            }

            $instance = app($class);
            
            if (!method_exists($instance, $method)) {
                return [
                    'passed' => false,
                    'message' => "Method {$method} not found in {$class}",
                    'error' => true,
                ];
            }

            $result = $instance->$method();

            // Normalize result to array format
            if (is_bool($result)) {
                return [
                    'passed' => $result,
                    'message' => $result ? 'Check passed' : 'Check failed',
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'passed' => false,
                'message' => $e->getMessage(),
                'error' => true,
                'exception' => get_class($e),
            ];
        }
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function getBlockerItems(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->blockers()->with('category')->ordered()->get();
    }

    public static function getAutomatedItems(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->automated()->with('category')->get();
    }

    public static function getCountsBySeverity(): array
    {
        return static::active()
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();
    }
}
