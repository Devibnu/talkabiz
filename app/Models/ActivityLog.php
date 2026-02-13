<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'causer_id',
        'causer_type',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User yang terkena aksi
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * User yang melakukan aksi (admin)
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * Subject model (polymorphic)
     */
    public function subject()
    {
        if ($this->subject_type && $this->subject_id) {
            return $this->morphTo('subject', 'subject_type', 'subject_id');
        }
        return null;
    }

    // ==================== SCOPES ====================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Log an activity
     */
    public static function log(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        ?int $userId = null,
        ?int $causerId = null,
        ?array $properties = null
    ): self {
        $request = request();

        return self::create([
            'user_id' => $userId,
            'causer_id' => $causerId ?? auth()->id(),
            'causer_type' => User::class,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    // ==================== ACTION CONSTANTS ====================

    const ACTION_LOGIN = 'login';
    const ACTION_LOGIN_FAILED = 'login_failed';
    const ACTION_LOGOUT = 'logout';
    const ACTION_PASSWORD_RESET = 'password_reset';
    const ACTION_PASSWORD_CHANGED = 'password_changed';
    const ACTION_FORCE_PASSWORD_SET = 'force_password_set';
    const ACTION_USER_CREATED = 'user_created';
    const ACTION_USER_UPDATED = 'user_updated';
    const ACTION_USER_DELETED = 'user_deleted';
    const ACTION_ROLE_CHANGED = 'role_changed';
    const ACTION_SESSION_INVALIDATED = 'session_invalidated';
}
