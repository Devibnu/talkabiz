<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'template_id',
        'name',
        'category',
        'language',
        'components',
        'sample_text',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PAUSED = 'paused';

    // Category constants
    const CATEGORY_MARKETING = 'MARKETING';
    const CATEGORY_UTILITY = 'UTILITY';
    const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';

    /**
     * Get the klien that owns this template
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    /**
     * Get campaigns using this template
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(WhatsappCampaign::class, 'template_id');
    }

    /**
     * Check if template is approved
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Scope for approved templates
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get formatted body text
     */
    public function getBodyText(): ?string
    {
        if (!$this->components) {
            return null;
        }

        $body = collect($this->components)->firstWhere('type', 'BODY');
        return $body['text'] ?? null;
    }

    /**
     * Get variable placeholders count
     */
    public function getVariableCount(): int
    {
        $body = $this->getBodyText();
        if (!$body) {
            return 0;
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        return count($matches[1]);
    }
}
