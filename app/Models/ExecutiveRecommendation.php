<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExecutiveRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'recommendation_id',
        'title',
        'description',
        'emoji',
        'category',
        'recommendation_type',
        'confidence_score',
        'based_on',
        'reasoning',
        'urgency',
        'valid_until',
        'suggested_action',
        'action_owner',
        'status',
        'actioned_by',
        'actioned_at',
        'action_notes',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'based_on' => 'array',
        'valid_until' => 'datetime',
        'actioned_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->recommendation_id)) {
                $model->recommendation_id = (string) Str::uuid();
            }
        });
    }

    // =========================================
    // RELATIONSHIPS
    // =========================================

    public function actionedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    // =========================================
    // SCOPES
    // =========================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('recommendation_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('urgency', 'critical');
    }

    public function scopeImportant($query)
    {
        return $query->whereIn('urgency', ['critical', 'important']);
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>', now());
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderByRaw("FIELD(urgency, 'critical', 'important', 'consider', 'fyi')")
            ->orderBy('created_at', 'desc');
    }

    // =========================================
    // ACCESSORS
    // =========================================

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getIsValidAttribute(): bool
    {
        return is_null($this->valid_until) || $this->valid_until->isFuture();
    }

    public function getUrgencyLevelAttribute(): int
    {
        return match ($this->urgency) {
            'critical' => 4,
            'important' => 3,
            'consider' => 2,
            'fyi' => 1,
            default => 0,
        };
    }

    public function getUrgencyColorAttribute(): string
    {
        return match ($this->urgency) {
            'critical' => 'red',
            'important' => 'orange',
            'consider' => 'yellow',
            'fyi' => 'blue',
            default => 'gray',
        };
    }

    public function getUrgencyLabelAttribute(): string
    {
        return match ($this->urgency) {
            'critical' => 'KRITIS',
            'important' => 'PENTING',
            'consider' => 'PERTIMBANGKAN',
            'fyi' => 'INFO',
            default => 'TIDAK DIKETAHUI',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->recommendation_type) {
            'go' => 'AMAN DILAKUKAN',
            'caution' => 'HATI-HATI',
            'hold' => 'TAHAN DULU',
            'stop' => 'JANGAN DILAKUKAN',
            'action' => 'PERLU AKSI',
            default => 'TIDAK DIKETAHUI',
        };
    }

    public function getTypeEmojiAttribute(): string
    {
        return match ($this->recommendation_type) {
            'go' => '✅',
            'caution' => '⚠️',
            'hold' => '⏸️',
            'stop' => '🛑',
            'action' => '🔔',
            default => '❓',
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'scaling' => 'Scaling',
            'campaign' => 'Campaign',
            'pricing' => 'Pricing & Promo',
            'risk' => 'Mitigasi Risiko',
            'customer' => 'Komunikasi Customer',
            'operational' => 'Operasional',
            'strategic' => 'Strategis',
            default => 'Lainnya',
        };
    }

    public function getConfidencePercentAttribute(): string
    {
        return number_format($this->confidence_score * 100, 0) . '%';
    }

    // =========================================
    // STATIC HELPERS
    // =========================================

    public static function getActiveRecommendations(int $limit = 5)
    {
        return static::active()
            ->valid()
            ->ordered()
            ->limit($limit)
            ->get();
    }

    public static function getCriticalActions()
    {
        return static::active()
            ->valid()
            ->whereIn('recommendation_type', ['action', 'stop'])
            ->ordered()
            ->get();
    }

    public static function createRecommendation(array $data): self
    {
        $type = $data['recommendation_type'] ?? 'go';
        $urgency = $data['urgency'] ?? 'fyi';

        return static::create(array_merge($data, [
            'emoji' => $data['emoji'] ?? self::getTypeEmoji($type),
        ]));
    }

    public static function getTypeEmoji(string $type): string
    {
        return match ($type) {
            'go' => '✅',
            'caution' => '⚠️',
            'hold' => '⏸️',
            'stop' => '🛑',
            'action' => '🔔',
            default => '💡',
        };
    }

    // =========================================
    // BUSINESS METHODS
    // =========================================

    public function acknowledge(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'acknowledged',
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_notes' => $notes,
        ]);
    }

    public function markActed(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'acted',
            'actioned_by' => $userId,
            'actioned_at' => now(),
            'action_notes' => $notes,
        ]);
    }

    public function dismiss(?string $reason = null): bool
    {
        return $this->update([
            'status' => 'dismissed',
            'action_notes' => $reason,
        ]);
    }

    public function expire(): bool
    {
        return $this->update([
            'status' => 'expired',
        ]);
    }

    public function getExecutiveSummary(): array
    {
        return [
            'id' => $this->recommendation_id,
            'headline' => $this->type_emoji . ' ' . $this->title,
            'description' => $this->description,
            'type' => [
                'value' => $this->recommendation_type,
                'label' => $this->type_label,
                'emoji' => $this->type_emoji,
            ],
            'urgency' => [
                'value' => $this->urgency,
                'label' => $this->urgency_label,
                'color' => $this->urgency_color,
            ],
            'category' => [
                'value' => $this->category,
                'label' => $this->category_label,
            ],
            'action' => [
                'suggestion' => $this->suggested_action,
                'owner' => $this->action_owner,
            ],
            'confidence' => $this->confidence_percent,
            'based_on' => $this->based_on ?? [],
            'reasoning' => $this->reasoning,
            'valid_until' => $this->valid_until?->format('d M Y H:i'),
            'status' => $this->status,
        ];
    }

    public function getQuickView(): array
    {
        return [
            'emoji' => $this->type_emoji,
            'title' => $this->title,
            'urgency' => $this->urgency_label,
            'action' => $this->suggested_action,
            'owner' => $this->action_owner,
        ];
    }
}
