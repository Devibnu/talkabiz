<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * RateLimitRule - Adaptive Rate Limiting Rules
 * 
 * Mendefinisikan aturan rate limit yang context-aware berdasarkan:
 * - User, API Key, Endpoint
 * - Risk Level (dari abuse scoring)
 * - Saldo Status
 * - IP Address
 * 
 * @property int $id
 * @property string $name
 * @property string $key_pattern
 * @property string $context_type
 * @property string|null $endpoint_pattern
 * @property string|null $risk_level
 * @property string|null $saldo_status
 * @property int $max_requests
 * @property int $window_seconds
 * @property string $algorithm
 * @property string $action
 * @property int|null $throttle_delay_ms
 * @property string|null $block_message
 * @property int $priority
 * @property bool $is_active
 * @property bool $apply_to_authenticated
 * @property bool $apply_to_guest
 * @property bool $bypass_for_owner
 * @property bool $send_headers
 * @property string|null $description
 * @property array|null $metadata
 */
class RateLimitRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key_pattern',
        'context_type',
        'endpoint_pattern',
        'risk_level',
        'saldo_status',
        'max_requests',
        'window_seconds',
        'algorithm',
        'action',
        'throttle_delay_ms',
        'block_message',
        'priority',
        'is_active',
        'apply_to_authenticated',
        'apply_to_guest',
        'bypass_for_owner',
        'send_headers',
        'description',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'apply_to_authenticated' => 'boolean',
        'apply_to_guest' => 'boolean',
        'bypass_for_owner' => 'boolean',
        'send_headers' => 'boolean',
        'metadata' => 'array',
        'max_requests' => 'integer',
        'window_seconds' => 'integer',
        'throttle_delay_ms' => 'integer',
        'priority' => 'integer',
    ];

    // ==================== CONSTANTS ====================
    
    const CONTEXT_GLOBAL = 'global';
    const CONTEXT_USER = 'user';
    const CONTEXT_API_KEY = 'api_key';
    const CONTEXT_ENDPOINT = 'endpoint';
    const CONTEXT_IP = 'ip';
    const CONTEXT_RISK_LEVEL = 'risk_level';
    const CONTEXT_SALDO_STATUS = 'saldo_status';
    
    const ACTION_THROTTLE = 'throttle';
    const ACTION_BLOCK = 'block';
    const ACTION_WARN = 'warn';
    
    const ALGORITHM_TOKEN_BUCKET = 'token_bucket';
    const ALGORITHM_SLIDING_WINDOW = 'sliding_window';

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEndpoint($query, string $endpoint)
    {
        return $query->where(function($q) use ($endpoint) {
            $q->whereNull('endpoint_pattern')
              ->orWhere(function($q2) use ($endpoint) {
                  // Match exact or wildcard patterns
                  $q2->where('endpoint_pattern', $endpoint)
                     ->orWhere('endpoint_pattern', 'LIKE', str_replace('*', '%', $endpoint));
              });
        });
    }

    public function scopeForContext($query, string $contextType)
    {
        return $query->where('context_type', $contextType);
    }

    public function scopeForRiskLevel($query, string $riskLevel)
    {
        return $query->where(function($q) use ($riskLevel) {
            $q->whereNull('risk_level')
              ->orWhere('risk_level', $riskLevel);
        });
    }

    public function scopeForSaldoStatus($query, string $saldoStatus)
    {
        return $query->where(function($q) use ($saldoStatus) {
            $q->whereNull('saldo_status')
              ->orWhere('saldo_status', $saldoStatus);
        });
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    // ==================== METHODS ====================

    /**
     * Check if rule matches given endpoint
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        if (empty($this->endpoint_pattern)) {
            return true; // No pattern = matches all
        }

        // Convert wildcard to regex
        $pattern = str_replace(['*', '/'], ['.*', '\/'], $this->endpoint_pattern);
        return (bool) preg_match("/^{$pattern}$/", $endpoint);
    }

    /**
     * Check if rule applies to current user
     */
    public function appliesTo(?Model $user = null): bool
    {
        if ($user) {
            // Bypass for owner if configured
            if ($this->bypass_for_owner && in_array($user->role ?? '', ['owner', 'super_admin'])) {
                return false;
            }
            return $this->apply_to_authenticated;
        }
        
        return $this->apply_to_guest;
    }

    /**
     * Get rate limit key for Redis
     */
    public function getRateLimitKey(array $context): string
    {
        $parts = [];
        
        // Add context type
        $parts[] = "ratelimit:{$this->context_type}";
        
        // Add specific identifiers based on context
        if (isset($context['user_id'])) {
            $parts[] = "user:{$context['user_id']}";
        }
        if (isset($context['api_key'])) {
            $parts[] = "apikey:" . md5($context['api_key']);
        }
        if (isset($context['endpoint'])) {
            $parts[] = "endpoint:" . md5($context['endpoint']);
        }
        if (isset($context['ip'])) {
            $parts[] = "ip:{$context['ip']}";
        }
        if ($this->risk_level) {
            $parts[] = "risk:{$this->risk_level}";
        }
        if ($this->saldo_status) {
            $parts[] = "saldo:{$this->saldo_status}";
        }
        
        $parts[] = "rule:{$this->id}";
        
        return implode(':', $parts);
    }

    /**
     * Get human-readable description
     */
    public function getDisplayName(): string
    {
        return $this->description ?: $this->name;
    }
}
