<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BANNER DISMISSAL MODEL
 * 
 * Tracks which banners have been dismissed by which users.
 */
class BannerDismissal extends Model
{
    public $timestamps = false;

    protected $table = 'banner_dismissals';

    protected $fillable = [
        'banner_id',
        'user_id',
        'dismissed_at',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    public function banner(): BelongsTo
    {
        return $this->belongsTo(InAppBanner::class, 'banner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
