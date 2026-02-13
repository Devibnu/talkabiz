<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * =============================================================================
 * CHAOS FLAG MODEL
 * =============================================================================
 * 
 * Feature flags for controlled chaos injection
 * 
 * Flag Types:
 * - mock_response: Return fake API response
 * - inject_failure: Inject timeout, error, exception
 * - delay: Add artificial delay
 * - timeout: Force request timeout
 * - drop_webhook: Drop incoming webhooks
 * - kill_worker: Kill worker process
 * - cache_unavailable: Simulate cache failure
 * 
 * =============================================================================
 */
class ChaosFlag extends Model
{
    protected $table = 'chaos_flags';

    protected $fillable = [
        'flag_key',
        'flag_type',
        'target_component',
        'is_enabled',
        'config',
        'experiment_id',
        'enabled_at',
        'expires_at',
        'enabled_by'
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
        'enabled_at' => 'datetime',
        'expires_at' => 'datetime'
    ];

    // ==================== CONSTANTS ====================

    const TYPE_MOCK_RESPONSE = 'mock_response';
    const TYPE_INJECT_FAILURE = 'inject_failure';
    const TYPE_DELAY = 'delay';
    const TYPE_TIMEOUT = 'timeout';
    const TYPE_DROP_WEBHOOK = 'drop_webhook';
    const TYPE_KILL_WORKER = 'kill_worker';
    const TYPE_CACHE_UNAVAILABLE = 'cache_unavailable';
    const TYPE_REPLAY_WEBHOOK = 'replay_webhook';

    // ==================== RELATIONSHIPS ====================

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(ChaosExperiment::class, 'experiment_id');
    }

    // ==================== SCOPES ====================

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeActive($query)
    {
        return $query->enabled()->notExpired();
    }

    public function scopeByComponent($query, string $component)
    {
        return $query->where('target_component', $component);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('flag_type', $type);
    }

    // ==================== ACCESSORS ====================

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->is_enabled && !$this->is_expired;
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->flag_type) {
            self::TYPE_MOCK_RESPONSE => '🎭 Mock Response',
            self::TYPE_INJECT_FAILURE => '💥 Inject Failure',
            self::TYPE_DELAY => '⏱️ Delay',
            self::TYPE_TIMEOUT => '⌛ Timeout',
            self::TYPE_DROP_WEBHOOK => '🗑️ Drop Webhook',
            self::TYPE_KILL_WORKER => '☠️ Kill Worker',
            self::TYPE_CACHE_UNAVAILABLE => '🚫 Cache Unavailable',
            self::TYPE_REPLAY_WEBHOOK => '🔁 Replay Webhook',
            default => $this->flag_type
        };
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Check if a flag is active for a component
     */
    public static function isActive(string $flagKey): bool
    {
        return self::where('flag_key', $flagKey)->active()->exists();
    }

    /**
     * Get active flag config
     */
    public static function getConfig(string $flagKey): ?array
    {
        $flag = self::where('flag_key', $flagKey)->active()->first();
        return $flag?->config;
    }

    /**
     * Get all active flags for a component
     */
    public static function getActiveForComponent(string $component): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()->byComponent($component)->get();
    }

    /**
     * Check if any chaos is active for a component
     */
    public static function hasChaosFor(string $component): bool
    {
        return self::active()->byComponent($component)->exists();
    }

    // ==================== ENABLE/DISABLE ====================

    public function enable(int $enabledBy, ?int $durationSeconds = null): bool
    {
        $this->update([
            'is_enabled' => true,
            'enabled_at' => now(),
            'enabled_by' => $enabledBy,
            'expires_at' => $durationSeconds ? now()->addSeconds($durationSeconds) : null
        ]);

        // Log injection history
        ChaosInjectionHistory::create([
            'experiment_id' => $this->experiment_id,
            'flag_key' => $this->flag_key,
            'injection_type' => $this->flag_type,
            'target' => $this->target_component ?? 'global',
            'config' => $this->config,
            'action' => 'enabled',
            'performed_by' => $enabledBy,
            'performed_at' => now()
        ]);

        return true;
    }

    public function disable(): bool
    {
        $this->update(['is_enabled' => false]);

        ChaosInjectionHistory::create([
            'experiment_id' => $this->experiment_id,
            'flag_key' => $this->flag_key,
            'injection_type' => $this->flag_type,
            'target' => $this->target_component ?? 'global',
            'config' => $this->config,
            'action' => 'disabled',
            'performed_by' => $this->enabled_by,
            'performed_at' => now()
        ]);

        return true;
    }

    // ==================== FACTORY METHODS ====================

    public static function createForExperiment(
        ChaosExperiment $experiment,
        string $flagKey,
        string $flagType,
        string $targetComponent,
        array $config = []
    ): self {
        return self::create([
            'flag_key' => $flagKey,
            'flag_type' => $flagType,
            'target_component' => $targetComponent,
            'is_enabled' => false,
            'config' => $config,
            'experiment_id' => $experiment->id
        ]);
    }
}
