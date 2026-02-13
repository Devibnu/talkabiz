<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingItem extends Model
{
    protected $fillable = [
        'section_id',
        'key',
        'title',
        'description',
        'icon',
        'bullets',
        'cta_label',
        'cta_url',
        'is_active',
        'order',
    ];

    protected $casts = [
        'bullets' => 'array',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(LandingSection::class, 'section_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
