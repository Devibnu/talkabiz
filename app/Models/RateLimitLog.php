<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RateLimitLog - Rate Limiting Audit Log
 * 
 * @property int $id
 * @property int|null $rule_id
 * @property string $key
 * @property string $endpoint
 * @property int|null $user_id
 * @property string $ip_address
 * @property string $action_taken
 * @property int $current_count
 * @property int $limit
 * @property int $remaining
 * @property int $reset_at
 * @property array|null $context
 */
class RateLimitLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null; // Only created_at

    protected $fillable = [
        'rule_id',
        'key',
        'endpoint',
        'user_id',
        'ip_address',
        'action_taken',
        'current_count',
        'limit',
        'remaining',
        'reset_at',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'current_count' => 'integer',
        'limit' => 'integer',
        'remaining' => 'integer',
        'reset_at' => 'integer',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RateLimitRule::class, 'rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ==================== SCOPES ====================

    public function scopeBlocked($query)
    {
        return $query->where('action_taken', 'blocked');
    }

    public function scopeThrottled($query)
    {
        return $query->where('action_taken', 'throttled');
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
