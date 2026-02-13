<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WarmupLimitChange Model
 * 
 * Audit trail untuk semua perubahan limit warmup.
 * Owner bisa melihat kenapa limit naik/turun.
 */
class WarmupLimitChange extends Model
{
    protected $table = 'warmup_limit_changes';
    
    public $timestamps = false;
    
    protected $fillable = [
        'warmup_id',
        'connection_id',
        'limit_type',
        'old_value',
        'new_value',
        'reason',
        'reason_detail',
        'warmup_state_at_change',
        'health_score_at_change',
        'actor_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ==================== LIMIT TYPES ====================
    
    const TYPE_DAILY = 'daily';
    const TYPE_HOURLY = 'hourly';
    const TYPE_BURST = 'burst';
    const TYPE_INTERVAL = 'interval';
    const TYPE_TEMPLATE = 'template';

    // ==================== REASONS ====================

    const REASON_STATE_TRANSITION = 'state_transition';
    const REASON_HEALTH_DROP = 'health_drop';
    const REASON_HEALTH_RECOVERY = 'health_recovery';
    const REASON_AGE_PROGRESSION = 'age_progression';
    const REASON_OWNER_OVERRIDE = 'owner_override';
    const REASON_AUTO_ADJUSTMENT = 'auto_adjustment';
    const REASON_COOLDOWN_START = 'cooldown_start';
    const REASON_COOLDOWN_END = 'cooldown_end';

    const REASON_LABELS = [
        self::REASON_STATE_TRANSITION => 'Perubahan State',
        self::REASON_HEALTH_DROP => 'Health Score Turun',
        self::REASON_HEALTH_RECOVERY => 'Health Score Naik',
        self::REASON_AGE_PROGRESSION => 'Nomor Makin Lama',
        self::REASON_OWNER_OVERRIDE => 'Override Owner',
        self::REASON_AUTO_ADJUSTMENT => 'Penyesuaian Otomatis',
        self::REASON_COOLDOWN_START => 'Masuk Cooldown',
        self::REASON_COOLDOWN_END => 'Keluar Cooldown',
    ];

    // ==================== RELATIONSHIPS ====================

    public function warmup(): BelongsTo
    {
        return $this->belongsTo(WhatsappWarmup::class, 'warmup_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== ACCESSORS ====================

    public function getReasonLabelAttribute(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    public function getChangeDescriptionAttribute(): string
    {
        $direction = $this->new_value > $this->old_value ? '↑' : '↓';
        return "{$this->limit_type}: {$this->old_value} {$direction} {$this->new_value}";
    }

    public function getIsIncreaseAttribute(): bool
    {
        return (float) $this->new_value > (float) $this->old_value;
    }

    // ==================== SCOPES ====================

    public function scopeForConnection($query, int $connectionId)
    {
        return $query->where('connection_id', $connectionId);
    }

    public function scopeForWarmup($query, int $warmupId)
    {
        return $query->where('warmup_id', $warmupId);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('limit_type', $type);
    }

    public function scopeDecreases($query)
    {
        return $query->whereRaw('CAST(new_value AS SIGNED) < CAST(old_value AS SIGNED)');
    }

    // ==================== STATIC METHODS ====================

    /**
     * Log a limit change
     */
    public static function logChange(
        WhatsappWarmup $warmup,
        string $limitType,
        $oldValue,
        $newValue,
        string $reason,
        ?string $reasonDetail = null,
        ?int $actorId = null
    ): self {
        return self::create([
            'warmup_id' => $warmup->id,
            'connection_id' => $warmup->connection_id,
            'limit_type' => $limitType,
            'old_value' => (string) $oldValue,
            'new_value' => (string) $newValue,
            'reason' => $reason,
            'reason_detail' => $reasonDetail,
            'warmup_state_at_change' => $warmup->warmup_state,
            'health_score_at_change' => $warmup->last_health_score,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }
}
