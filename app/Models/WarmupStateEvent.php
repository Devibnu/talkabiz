<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WarmupStateEvent Model
 * 
 * Audit trail untuk semua transisi state warmup.
 * Mencatat kapan state berubah, kenapa, dan siapa yang trigger.
 */
class WarmupStateEvent extends Model
{
    protected $table = 'warmup_state_events';
    
    public $timestamps = false;
    
    protected $fillable = [
        'warmup_id',
        'connection_id',
        'user_id',
        'from_state',
        'to_state',
        'trigger_type',
        'trigger_description',
        'health_score_at_event',
        'health_grade_at_event',
        'number_age_days_at_event',
        'sent_today_at_event',
        'daily_limit_at_event',
        'old_daily_limit',
        'new_daily_limit',
        'old_hourly_limit',
        'new_hourly_limit',
        'actor_id',
        'actor_role',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== TRIGGER TYPES ====================
    
    const TRIGGER_AUTO_AGE = 'auto_age';
    const TRIGGER_AUTO_HEALTH = 'auto_health';
    const TRIGGER_AUTO_RECOVERY = 'auto_recovery';
    const TRIGGER_WEBHOOK_BLOCK = 'webhook_block';
    const TRIGGER_WEBHOOK_FAIL = 'webhook_fail';
    const TRIGGER_OWNER_FORCE = 'owner_force';
    const TRIGGER_OWNER_RESUME = 'owner_resume';
    const TRIGGER_DAILY_CRON = 'daily_cron';
    const TRIGGER_MANUAL_OVERRIDE = 'manual_override';

    const TRIGGER_LABELS = [
        self::TRIGGER_AUTO_AGE => 'Transisi Otomatis (Umur)',
        self::TRIGGER_AUTO_HEALTH => 'Perubahan Health Score',
        self::TRIGGER_AUTO_RECOVERY => 'Pemulihan Otomatis',
        self::TRIGGER_WEBHOOK_BLOCK => 'Terblokir via Webhook',
        self::TRIGGER_WEBHOOK_FAIL => 'Gagal Tinggi via Webhook',
        self::TRIGGER_OWNER_FORCE => 'Owner Force Action',
        self::TRIGGER_OWNER_RESUME => 'Owner Resume',
        self::TRIGGER_DAILY_CRON => 'Proses Harian',
        self::TRIGGER_MANUAL_OVERRIDE => 'Override Manual',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== ACCESSORS ====================

    public function getTriggerLabelAttribute(): string
    {
        return self::TRIGGER_LABELS[$this->trigger_type] ?? $this->trigger_type;
    }

    public function getStateChangeDescriptionAttribute(): string
    {
        $from = $this->from_state ?? 'N/A';
        $to = $this->to_state;
        return "{$from} → {$to}";
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

    public function scopeByTrigger($query, string $trigger)
    {
        return $query->where('trigger_type', $trigger);
    }

    public function scopeStateTransitionsTo($query, string $state)
    {
        return $query->where('to_state', $state);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create a state transition event
     */
    public static function createEvent(
        WhatsappWarmup $warmup,
        string $fromState,
        string $toState,
        string $triggerType,
        ?string $description = null,
        ?int $actorId = null,
        ?string $actorRole = null,
        array $metadata = []
    ): self {
        return self::create([
            'warmup_id' => $warmup->id,
            'connection_id' => $warmup->connection_id,
            'user_id' => $warmup->connection->klien_id ?? null,
            'from_state' => $fromState,
            'to_state' => $toState,
            'trigger_type' => $triggerType,
            'trigger_description' => $description,
            'health_score_at_event' => $warmup->last_health_score,
            'health_grade_at_event' => $warmup->last_health_grade,
            'number_age_days_at_event' => $warmup->number_age_days,
            'sent_today_at_event' => $warmup->sent_today,
            'daily_limit_at_event' => $warmup->current_daily_limit,
            'old_daily_limit' => $warmup->getOriginal('current_daily_limit'),
            'new_daily_limit' => $warmup->current_daily_limit,
            'old_hourly_limit' => $warmup->getOriginal('current_hourly_limit'),
            'new_hourly_limit' => $warmup->current_hourly_limit,
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? ($actorId ? 'owner' : 'system'),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
