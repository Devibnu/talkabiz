<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH COMMUNICATION MODEL
 * 
 * Template komunikasi per fase
 */
class LaunchCommunication extends Model
{
    use HasFactory;

    protected $fillable = [
        'launch_phase_id',
        'template_code',
        'template_name',
        'channel',
        'purpose',
        'target_segment',
        'subject',
        'body',
        'variables',
        'trigger',
        'trigger_event',
        'delay_hours',
        'is_active',
        'times_sent',
        'open_rate',
        'click_rate',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
        'open_rate' => 'decimal:2',
        'click_rate' => 'decimal:2',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'launch_phase_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPurpose($query, $purpose)
    {
        return $query->where('purpose', $purpose);
    }

    public function scopeForChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeForSegment($query, $segment)
    {
        return $query->whereIn('target_segment', ['all', $segment]);
    }

    public function scopeTriggeredBy($query, $event)
    {
        return $query->where('trigger', 'event')
            ->where('trigger_event', $event);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getChannelIconAttribute(): string
    {
        $icons = [
            'email' => '📧',
            'whatsapp' => '📱',
            'sms' => '💬',
            'in_app' => '🔔',
        ];
        
        return $icons[$this->channel] ?? '📨';
    }

    public function getPurposeLabelAttribute(): string
    {
        $labels = [
            'welcome' => 'Welcome',
            'onboarding' => 'Onboarding',
            'tips' => 'Tips & Tricks',
            'milestone' => 'Milestone',
            'warning' => 'Warning',
            'upgrade_offer' => 'Upgrade Offer',
            'feedback_request' => 'Feedback Request',
            'phase_transition' => 'Phase Transition',
            'incident_notification' => 'Incident Notification',
        ];
        
        return $labels[$this->purpose] ?? $this->purpose;
    }

    public function getPerformanceScoreAttribute(): float
    {
        if ($this->times_sent <= 0) {
            return 0;
        }
        
        $openWeight = 0.6;
        $clickWeight = 0.4;
        
        $openScore = min(100, ($this->open_rate ?? 0));
        $clickScore = min(100, ($this->click_rate ?? 0) * 5); // click rate usually lower
        
        return round(($openScore * $openWeight) + ($clickScore * $clickWeight), 1);
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function render(array $data): string
    {
        $body = $this->body;
        
        foreach ($data as $key => $value) {
            $body = str_replace("{{{$key}}}", $value, $body);
        }
        
        return $body;
    }

    public function renderSubject(array $data): string
    {
        $subject = $this->subject ?? '';
        
        foreach ($data as $key => $value) {
            $subject = str_replace("{{{$key}}}", $value, $subject);
        }
        
        return $subject;
    }

    public function recordSend(bool $opened = false, bool $clicked = false): void
    {
        $this->increment('times_sent');
        
        // Update rates (simplified rolling average)
        if ($this->times_sent > 1) {
            if ($opened) {
                $newOpenRate = (($this->open_rate ?? 0) * ($this->times_sent - 1) + 100) / $this->times_sent;
                $this->update(['open_rate' => $newOpenRate]);
            }
            
            if ($clicked) {
                $newClickRate = (($this->click_rate ?? 0) * ($this->times_sent - 1) + 100) / $this->times_sent;
                $this->update(['click_rate' => $newClickRate]);
            }
        }
    }

    public static function getTemplateForEvent(string $event, string $segment = 'all'): ?self
    {
        return static::active()
            ->triggeredBy($event)
            ->forSegment($segment)
            ->first();
    }
}
