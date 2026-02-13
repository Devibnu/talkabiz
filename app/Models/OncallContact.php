<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * On-Call Contact
 * 
 * Kontak darurat untuk eskalasi.
 */
class OncallContact extends Model
{
    use HasFactory;

    protected $table = 'oncall_contacts';

    protected $fillable = [
        'role_id',
        'user_id',
        'name',
        'email',
        'phone',
        'slack_handle',
        'telegram_id',
        'schedule_type',
        'rotation_start',
        'rotation_end',
        'schedule_days',
        'shift_start',
        'shift_end',
        'is_active',
    ];

    protected $casts = [
        'schedule_days' => 'array',
        'rotation_start' => 'date',
        'rotation_end' => 'date',
        'shift_start' => 'datetime:H:i',
        'shift_end' => 'datetime:H:i',
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function role(): BelongsTo
    {
        return $this->belongsTo(RunbookRole::class, 'role_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('schedule_type', 'primary');
    }

    public function scopeCurrentRotation($query)
    {
        $today = now()->toDateString();
        return $query->where(function ($q) use ($today) {
            $q->whereNull('rotation_start')
              ->orWhere('rotation_start', '<=', $today);
        })->where(function ($q) use ($today) {
            $q->whereNull('rotation_end')
              ->orWhere('rotation_end', '>=', $today);
        });
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsOnDutyAttribute(): bool
    {
        // Check if within rotation dates
        $today = now();
        
        if ($this->rotation_start && $today->lt($this->rotation_start)) {
            return false;
        }
        
        if ($this->rotation_end && $today->gt($this->rotation_end)) {
            return false;
        }

        // Check day of week
        if ($this->schedule_days) {
            $dayOfWeek = $today->dayOfWeek; // 0=Sun, 6=Sat
            if (!in_array($dayOfWeek, $this->schedule_days)) {
                return false;
            }
        }

        // Check time
        if ($this->shift_start && $this->shift_end) {
            $currentTime = $today->format('H:i:s');
            $start = $this->shift_start->format('H:i:s');
            $end = $this->shift_end->format('H:i:s');
            
            if ($currentTime < $start || $currentTime > $end) {
                return false;
            }
        }

        return true;
    }

    public function getContactInfoAttribute(): string
    {
        $info = [];
        if ($this->phone) $info[] = "📞 {$this->phone}";
        if ($this->email) $info[] = "📧 {$this->email}";
        if ($this->slack_handle) $info[] = "💬 {$this->slack_handle}";
        if ($this->telegram_id) $info[] = "✈️ {$this->telegram_id}";
        return implode(' | ', $info);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getCurrentOnCall(string $roleSlug): ?self
    {
        return static::active()
            ->currentRotation()
            ->whereHas('role', fn($q) => $q->where('slug', $roleSlug))
            ->primary()
            ->first();
    }

    public static function getAllCurrentOnCall(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
            ->currentRotation()
            ->primary()
            ->with('role')
            ->get();
    }
}
