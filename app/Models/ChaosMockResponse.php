<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * =============================================================================
 * CHAOS MOCK RESPONSE MODEL
 * =============================================================================
 * 
 * Mock responses for provider simulation (WhatsApp, Payment Gateway, etc)
 * 
 * =============================================================================
 */
class ChaosMockResponse extends Model
{
    protected $table = 'chaos_mock_responses';

    protected $fillable = [
        'provider',
        'endpoint',
        'method',
        'scenario_type',
        'http_status',
        'response_body',
        'response_headers',
        'delay_ms',
        'probability',
        'is_active'
    ];

    protected $casts = [
        'response_body' => 'array',
        'response_headers' => 'array',
        'probability' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    // ==================== CONSTANTS ====================

    const PROVIDER_WHATSAPP = 'whatsapp';
    const PROVIDER_MIDTRANS = 'midtrans';
    const PROVIDER_WEBHOOK = 'webhook_receiver';

    const SCENARIO_REJECTED = 'rejected';
    const SCENARIO_RATE_LIMITED = 'rate_limited';
    const SCENARIO_TIMEOUT = 'timeout';
    const SCENARIO_QUALITY_DOWNGRADE = 'quality_downgrade';
    const SCENARIO_BAN = 'ban';

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByScenario($query, string $scenario)
    {
        return $query->where('scenario_type', $scenario);
    }

    // ==================== METHODS ====================

    /**
     * Check if this mock should be applied (based on probability)
     */
    public function shouldApply(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->probability >= 100) {
            return true;
        }

        return mt_rand(1, 100) <= $this->probability;
    }

    /**
     * Get mock response with delay
     */
    public function getResponse(): array
    {
        return [
            'status' => $this->http_status,
            'body' => $this->response_body,
            'headers' => $this->response_headers ?? [],
            'delay_ms' => $this->delay_ms
        ];
    }

    /**
     * Find matching mock for a request
     */
    public static function findForRequest(string $provider, string $endpoint, string $method = 'POST'): ?self
    {
        return self::active()
            ->where('provider', $provider)
            ->where('endpoint', $endpoint)
            ->where('method', $method)
            ->first();
    }

    /**
     * Find mocks for a scenario
     */
    public static function getForScenario(string $provider, string $scenario): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->where('provider', $provider)
            ->where('scenario_type', $scenario)
            ->get();
    }
}
