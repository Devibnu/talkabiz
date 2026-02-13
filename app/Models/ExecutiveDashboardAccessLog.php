<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExecutiveDashboardAccessLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_id',
        'user_id',
        'user_name',
        'user_role',
        'access_type',
        'accessed_section',
        'accessed_data',
        'ip_address',
        'user_agent',
        'device_type',
        'session_id',
        'session_duration_seconds',
        'accessed_at',
    ];

    protected $casts = [
        'accessed_data' => 'array',
        'accessed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->access_id)) {
                $model->access_id = (string) Str::uuid();
            }
            if (empty($model->accessed_at)) {
                $model->accessed_at = now();
            }
        });
    }

    // =========================================
    // RELATIONSHIPS
    // =========================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('access_type', $type);
    }

    public function scopeBySection($query, string $section)
    {
        return $query->where('accessed_section', $section);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('accessed_at', now()->toDateString());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('accessed_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('accessed_at', now()->year)
            ->whereMonth('accessed_at', now()->month);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('accessed_at', '>=', now()->subHours($hours));
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getAccessTypeLabelAttribute(): string
    {
        return match ($this->access_type) {
            'view' => 'Melihat Dashboard',
            'export' => 'Export Data',
            'share' => 'Berbagi',
            'acknowledge' => 'Acknowledge Alert',
            'action' => 'Mengambil Aksi',
            default => 'Tidak Diketahui',
        };
    }

    public function getDeviceIconAttribute(): string
    {
        return match ($this->device_type) {
            'mobile' => '📱',
            'tablet' => '📱',
            'desktop' => '🖥️',
            default => '❓',
        };
    }

    public function getSessionDurationFormattedAttribute(): string
    {
        if (!$this->session_duration_seconds) {
            return '-';
        }

        $minutes = floor($this->session_duration_seconds / 60);
        $seconds = $this->session_duration_seconds % 60;

        if ($minutes > 0) {
            return $minutes . 'm ' . $seconds . 's';
        }

        return $seconds . 's';
    }

    public function getAccessedAtFormattedAttribute(): string
    {
        return $this->accessed_at->format('d M Y H:i:s');
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function log(
        int $userId,
        string $userName,
        string $userRole,
        string $accessType,
        ?string $section = null,
        ?array $data = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'user_name' => $userName,
            'user_role' => $userRole,
            'access_type' => $accessType,
            'accessed_section' => $section,
            'accessed_data' => $data,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'device_type' => self::detectDeviceType(request()->userAgent()),
            'session_id' => session()->getId(),
        ]);
    }

    public static function logView(int $userId, string $userName, string $userRole, ?string $section = null): self
    {
        return self::log($userId, $userName, $userRole, 'view', $section);
    }

    public static function logExport(int $userId, string $userName, string $userRole, array $exportedData): self
    {
        return self::log($userId, $userName, $userRole, 'export', 'export', $exportedData);
    }

    public static function logAction(int $userId, string $userName, string $userRole, string $section, array $actionData): self
    {
        return self::log($userId, $userName, $userRole, 'action', $section, $actionData);
    }

    public static function detectDeviceType(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, 'mobile') || str_contains($userAgent, 'android')) {
            return 'mobile';
        }

        if (str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }

    public static function getAccessStats(int $days = 7): array
    {
        $logs = static::where('accessed_at', '>=', now()->subDays($days))->get();

        return [
            'total_accesses' => $logs->count(),
            'unique_users' => $logs->unique('user_id')->count(),
            'by_type' => $logs->groupBy('access_type')->map->count(),
            'by_section' => $logs->groupBy('accessed_section')->map->count(),
            'by_device' => $logs->groupBy('device_type')->map->count(),
            'most_active_user' => $logs->groupBy('user_id')
                ->sortByDesc(fn($group) => $group->count())
                ->keys()
                ->first(),
        ];
    }

    public static function getRecentActivity(int $limit = 20)
    {
        return static::orderBy('accessed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function getAuditEntry(): array
    {
        return [
            'timestamp' => $this->accessed_at_formatted,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user_name,
                'role' => $this->user_role,
            ],
            'access' => [
                'type' => $this->access_type,
                'label' => $this->access_type_label,
                'section' => $this->accessed_section,
            ],
            'context' => [
                'ip' => $this->ip_address,
                'device' => $this->device_icon . ' ' . $this->device_type,
                'duration' => $this->session_duration_formatted,
            ],
            'data' => $this->accessed_data,
        ];
    }

    public function updateSessionDuration(int $durationSeconds): bool
    {
        return $this->update([
            'session_duration_seconds' => $durationSeconds,
        ]);
    }
}
