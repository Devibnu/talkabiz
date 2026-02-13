<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * On-Call Schedule Model
 * 
 * Manages on-call rotations for incident response.
 */
class OnCallSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'team',
        'primary_user_id',
        'secondary_user_id',
        'escalation_user_id',
        'starts_at',
        'ends_at',
        'timezone',
        'primary_phone',
        'primary_slack',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Teams
    public const TEAM_PLATFORM = 'platform';
    public const TEAM_OPS = 'ops';
    public const TEAM_DEV = 'dev';
    public const TEAM_INFRA = 'infra';

    // ==================== RELATIONSHIPS ====================

    public function primaryUser()
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }

    public function secondaryUser()
    {
        return $this->belongsTo(User::class, 'secondary_user_id');
    }

    public function escalationUser()
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeam($query, string $team)
    {
        return $query->where('team', $team);
    }

    public function scopeCurrent($query)
    {
        $now = now();
        return $query->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->where('is_active', true);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get current on-call for a team
     */
    public static function getCurrentOnCall(string $team): ?self
    {
        return self::forTeam($team)->current()->first();
    }

    /**
     * Get all current on-calls
     */
    public static function getAllCurrentOnCalls(): \Illuminate\Database\Eloquent\Collection
    {
        return self::current()->get();
    }

    // ==================== HELPERS ====================

    public function isCurrent(): bool
    {
        $now = now();
        return $this->is_active 
            && $this->starts_at <= $now 
            && $this->ends_at >= $now;
    }

    public function getResponderForLevel(int $level): ?int
    {
        return match ($level) {
            1 => $this->primary_user_id,
            2 => $this->secondary_user_id,
            3 => $this->escalation_user_id,
            default => null,
        };
    }

    public function getAllResponders(): array
    {
        return array_filter([
            $this->primary_user_id,
            $this->secondary_user_id,
            $this->escalation_user_id,
        ]);
    }
}
