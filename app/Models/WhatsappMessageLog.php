<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'message_id',
        'direction',
        'phone_number',
        'template_id',
        'content',
        'media',
        'status',
        'error_code',
        'error_message',
        'cost',
        'campaign_id',
        'metadata',
    ];

    protected $casts = [
        'media' => 'array',
        'metadata' => 'array',
        'cost' => 'decimal:4',
    ];

    // Direction constants
    const DIRECTION_INBOUND = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    /**
     * Get the klien
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    /**
     * Get the campaign if applicable
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsappCampaign::class, 'campaign_id');
    }

    /**
     * Scope for inbound messages
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope for outbound messages
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
    }

    /**
     * Log an outbound message
     */
    public static function logOutbound(
        int $klienId,
        string $phoneNumber,
        ?string $messageId = null,
        ?string $templateId = null,
        ?string $content = null,
        ?int $campaignId = null,
        string $status = self::STATUS_PENDING,
        float $cost = 0
    ): self {
        return self::create([
            'klien_id' => $klienId,
            'message_id' => $messageId,
            'direction' => self::DIRECTION_OUTBOUND,
            'phone_number' => $phoneNumber,
            'template_id' => $templateId,
            'content' => $content,
            'campaign_id' => $campaignId,
            'status' => $status,
            'cost' => $cost,
        ]);
    }

    /**
     * Log an inbound message
     */
    public static function logInbound(
        int $klienId,
        string $phoneNumber,
        ?string $messageId = null,
        ?string $content = null,
        ?array $media = null
    ): self {
        return self::create([
            'klien_id' => $klienId,
            'message_id' => $messageId,
            'direction' => self::DIRECTION_INBOUND,
            'phone_number' => $phoneNumber,
            'content' => $content,
            'media' => $media,
            'status' => self::STATUS_DELIVERED,
        ]);
    }

    /**
     * Update status by message ID
     */
    public static function updateStatusByMessageId(string $messageId, string $status, ?string $errorCode = null, ?string $errorMessage = null): bool
    {
        $log = self::where('message_id', $messageId)->first();
        
        if (!$log) {
            return false;
        }

        $log->update([
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        return true;
    }
}
