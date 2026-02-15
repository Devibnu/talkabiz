<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivationEvent — KPI Logging for Growth Funnel
 * 
 * Tracks user activation journey:
 * registered → onboarding_complete → viewed_subscription → clicked_pay → payment_success → first_campaign_sent
 * 
 * @property int $id
 * @property int $user_id
 * @property string $event_type
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 */
class ActivationEvent extends Model
{
    protected $fillable = [
        'user_id',
        'event_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    // ==================== EVENT TYPES ====================
    const EVENT_REGISTERED = 'registered';
    const EVENT_ONBOARDING_COMPLETE = 'onboarding_complete';
    const EVENT_VIEWED_SUBSCRIPTION = 'viewed_subscription';
    const EVENT_CLICKED_PAY = 'clicked_pay';
    const EVENT_PAYMENT_SUCCESS = 'payment_success';
    const EVENT_FIRST_CAMPAIGN_SENT = 'first_campaign_sent';
    const EVENT_ACTIVATION_MODAL_SHOWN = 'activation_modal_shown';
    const EVENT_ACTIVATION_MODAL_CTA = 'activation_modal_cta_clicked';
    const EVENT_SCARCITY_TIMER_SHOWN = 'scarcity_timer_shown';

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
