<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WebhookLog Model
 * 
 * HARDENING: Menyimpan raw payload dari webhook untuk:
 * - Debugging jika ada error
 * - Audit trail
 * - Retry mechanism jika gagal diproses
 */
class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'gateway',
        'event_type',
        'order_id',
        'external_id',
        'payload',
        'headers',
        'signature',
        'signature_valid',
        'processed',
        'process_result',
        'error_message',
        'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'signature_valid' => 'boolean',
        'processed' => 'boolean',
    ];

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeFailed($query)
    {
        return $query->where('processed', false)
            ->whereNotNull('error_message');
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Create log entry for Midtrans webhook
     */
    public static function logMidtrans(
        array $payload,
        array $headers = [],
        ?string $ipAddress = null,
        bool $signatureValid = false
    ): self {
        return self::create([
            'gateway' => 'midtrans',
            'event_type' => $payload['transaction_status'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'payload' => $payload,
            'headers' => $headers,
            'signature' => $payload['signature_key'] ?? null,
            'signature_valid' => $signatureValid,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Create log entry for Xendit webhook
     */
    public static function logXendit(
        array $payload,
        array $headers = [],
        ?string $ipAddress = null,
        bool $tokenValid = false
    ): self {
        return self::create([
            'gateway' => 'xendit',
            'event_type' => $payload['status'] ?? ($payload['event'] ?? null),
            'order_id' => null,
            'external_id' => $payload['external_id'] ?? null,
            'payload' => $payload,
            'headers' => $headers,
            'signature' => $headers['X-CALLBACK-TOKEN'] ?? null,
            'signature_valid' => $tokenValid,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Mark as successfully processed
     */
    public function markProcessed(string $result = 'success'): void
    {
        $this->update([
            'processed' => true,
            'process_result' => $result,
            'error_message' => null,
        ]);
    }

    /**
     * Mark as failed
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'processed' => false,
            'error_message' => $errorMessage,
        ]);
    }
}
