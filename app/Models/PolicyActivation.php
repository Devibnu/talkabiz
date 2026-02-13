<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * POLICY ACTIVATION MODEL
 * =============================================================================
 * 
 * Log aktivasi dan deaktivasi reliability policy.
 * 
 * =============================================================================
 */
class PolicyActivation extends Model
{
    use HasFactory;

    protected $table = 'policy_activations';

    // ==================== CONSTANTS ====================

    public const RESOLUTION_AUTO_RESOLVED = 'auto_resolved';
    public const RESOLUTION_MANUALLY_RESOLVED = 'manually_resolved';
    public const RESOLUTION_OVERRIDDEN = 'overridden';
    public const RESOLUTION_EXPIRED = 'expired';
    public const RESOLUTION_SUPERSEDED = 'superseded';

    protected $fillable = [
        'policy_id',
        'slo_id',
        'activated_at',
        'deactivated_at',
        'is_active',
        'trigger_reason',
        'trigger_context',
        'actions_executed',
        'actions_results',
        'was_overridden',
        'overridden_by',
        'override_reason',
        'overridden_at',
        'resolution',
        'resolution_notes',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'is_active' => 'boolean',
        'trigger_context' => 'array',
        'actions_executed' => 'array',
        'actions_results' => 'array',
        'was_overridden' => 'boolean',
        'overridden_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ReliabilityPolicy::class, 'policy_id');
    }

    public function slo(): BelongsTo
    {
        return $this->belongsTo(SloDefinition::class, 'slo_id');
    }

    public function overriddenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('activated_at', '>=', now()->subDays($days));
    }

    public function scopeOverridden($query)
    {
        return $query->where('was_overridden', true);
    }

    // ==================== ACCESSORS ====================

    public function getDurationAttribute(): ?int
    {
        if (!$this->deactivated_at) {
            return $this->activated_at->diffInMinutes(now());
        }

        return $this->activated_at->diffInMinutes($this->deactivated_at);
    }

    public function getDurationLabelAttribute(): string
    {
        $minutes = $this->duration;

        if ($minutes < 60) {
            return "{$minutes} minutes";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return "{$hours}h {$remainingMinutes}m";
        }

        $days = floor($hours / 24);
        $remainingHours = $hours % 24;

        return "{$days}d {$remainingHours}h";
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->is_active) {
            return '🟢 Active';
        }

        return match ($this->resolution) {
            self::RESOLUTION_AUTO_RESOLVED => '✅ Auto-resolved',
            self::RESOLUTION_MANUALLY_RESOLVED => '👤 Manually resolved',
            self::RESOLUTION_OVERRIDDEN => '⚠️ Overridden',
            self::RESOLUTION_EXPIRED => '⏰ Expired',
            self::RESOLUTION_SUPERSEDED => '🔄 Superseded',
            default => '⚪ Inactive',
        };
    }

    // ==================== METHODS ====================

    /**
     * Deactivate the policy activation
     */
    public function deactivate(string $resolution = 'auto_resolved', ?string $notes = null): void
    {
        $this->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'resolution' => $resolution,
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Override the policy activation
     */
    public function override(int $userId, string $reason): void
    {
        $this->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'was_overridden' => true,
            'overridden_by' => $userId,
            'override_reason' => $reason,
            'overridden_at' => now(),
            'resolution' => self::RESOLUTION_OVERRIDDEN,
        ]);
    }

    /**
     * Record executed actions
     */
    public function recordActions(array $executed, array $results = []): void
    {
        $this->update([
            'actions_executed' => $executed,
            'actions_results' => $results,
        ]);
    }
}
