<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WarmupAutoBlock Model
 * 
 * Log ketika sistem auto-block/restrict nomor.
 * Untuk audit dan tracking dampak.
 */
class WarmupAutoBlock extends Model
{
    protected $table = 'warmup_auto_blocks';
    
    protected $fillable = [
        'warmup_id',
        'connection_id',
        'block_type',
        'severity',
        'trigger_event',
        'campaign_id',
        'blocked_at',
        'blocked_until',
        'block_duration_hours',
        'is_resolved',
        'resolved_at',
        'resolved_by_type',
        'resolved_by_id',
        'resolution_note',
        'messages_blocked',
        'metadata',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
        'blocked_until' => 'datetime',
        'resolved_at' => 'datetime',
        'is_resolved' => 'boolean',
        'metadata' => 'array',
    ];

    // ==================== BLOCK TYPES ====================
    
    const TYPE_BLAST_DISABLED = 'blast_disabled';
    const TYPE_CAMPAIGN_DISABLED = 'campaign_disabled';
    const TYPE_COOLDOWN_ENFORCED = 'cooldown_enforced';
    const TYPE_MARKETING_BLOCKED = 'marketing_blocked';
    const TYPE_RATE_LIMITED = 'rate_limited';
    const TYPE_SUSPENDED = 'suspended';

    const BLOCK_LABELS = [
        self::TYPE_BLAST_DISABLED => 'WA Blast Dinonaktifkan',
        self::TYPE_CAMPAIGN_DISABLED => 'Campaign Dinonaktifkan',
        self::TYPE_COOLDOWN_ENFORCED => 'Cooldown Diterapkan',
        self::TYPE_MARKETING_BLOCKED => 'Marketing Diblokir',
        self::TYPE_RATE_LIMITED => 'Rate Limited',
        self::TYPE_SUSPENDED => 'Ditangguhkan',
    ];

    // ==================== SEVERITIES ====================

    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    const SEVERITY_COLORS = [
        self::SEVERITY_LOW => 'info',
        self::SEVERITY_MEDIUM => 'warning',
        self::SEVERITY_HIGH => 'danger',
        self::SEVERITY_CRITICAL => 'dark',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    // ==================== ACCESSORS ====================

    public function getBlockLabelAttribute(): string
    {
        return self::BLOCK_LABELS[$this->block_type] ?? $this->block_type;
    }

    public function getSeverityColorAttribute(): string
    {
        return self::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function getIsActiveAttribute(): bool
    {
        if ($this->is_resolved) {
            return false;
        }
        
        if ($this->blocked_until && $this->blocked_until->isPast()) {
            return false;
        }
        
        return true;
    }

    public function getRemainingHoursAttribute(): int
    {
        if (!$this->blocked_until || $this->is_resolved) {
            return 0;
        }
        
        return max(0, now()->diffInHours($this->blocked_until, false));
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_resolved', false)
            ->where(function ($q) {
                $q->whereNull('blocked_until')
                  ->orWhere('blocked_until', '>', now());
            });
    }

    public function scopeResolved($query)
    {
        return $query->where('is_resolved', true);
    }

    public function scopeForConnection($query, int $connectionId)
    {
        return $query->where('connection_id', $connectionId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('block_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== METHODS ====================

    /**
     * Resolve this block
     */
    public function resolve(string $type = 'auto', ?int $userId = null, ?string $note = null): self
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by_type' => $type,
            'resolved_by_id' => $userId,
            'resolution_note' => $note,
        ]);

        return $this->fresh();
    }

    /**
     * Increment blocked message count
     */
    public function incrementBlocked(int $count = 1): void
    {
        $this->increment('messages_blocked', $count);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Create a new block record
     */
    public static function createBlock(
        WhatsappWarmup $warmup,
        string $blockType,
        string $severity = 'medium',
        ?string $trigger = null,
        ?int $durationHours = null,
        ?int $campaignId = null,
        array $metadata = []
    ): self {
        return self::create([
            'warmup_id' => $warmup->id,
            'connection_id' => $warmup->connection_id,
            'block_type' => $blockType,
            'severity' => $severity,
            'trigger_event' => $trigger,
            'campaign_id' => $campaignId,
            'blocked_at' => now(),
            'blocked_until' => $durationHours ? now()->addHours($durationHours) : null,
            'block_duration_hours' => $durationHours,
            'is_resolved' => false,
            'messages_blocked' => 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get active blocks for a connection
     */
    public static function getActiveForConnection(int $connectionId): \Illuminate\Database\Eloquent\Collection
    {
        return self::forConnection($connectionId)->active()->get();
    }
}
