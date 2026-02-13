<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Corporate Invite Model
 * 
 * Manages invite-only corporate pilot invitations.
 * Invites are sent by admin and must be accepted within expiry period.
 * 
 * STATUS FLOW:
 * pending → accepted (user registered)
 * pending → expired (not accepted in time)
 * pending → revoked (admin cancelled)
 */
class CorporateInvite extends Model
{
    protected $fillable = [
        'email',
        'company_name',
        'contact_person',
        'contact_phone',
        'industry',
        'notes',
        'invite_token',
        'invite_expires_at',
        'status',
        'invited_by',
        'user_id',
        'accepted_at',
    ];

    protected $casts = [
        'invite_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    // ==================== CONSTANTS ====================
    
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVOKED = 'revoked';

    // ==================== RELATIONSHIPS ====================

    public function invitedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ==================== HELPERS ====================

    /**
     * Generate unique invite token.
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('invite_token', $token)->exists());
        
        return $token;
    }

    /**
     * Check if invite is still valid.
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->invite_expires_at->isFuture();
    }

    /**
     * Check if invite is expired.
     */
    public function isExpired(): bool
    {
        return $this->invite_expires_at->isPast();
    }

    /**
     * Mark invite as accepted.
     */
    public function markAsAccepted(User $user): void
    {
        $this->update([
            'status' => self::STATUS_ACCEPTED,
            'user_id' => $user->id,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Revoke invite.
     */
    public function revoke(): void
    {
        $this->update(['status' => self::STATUS_REVOKED]);
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('invite_expires_at', '>', now());
    }

    public function scopeExpiredUnprocessed($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('invite_expires_at', '<=', now());
    }
}
