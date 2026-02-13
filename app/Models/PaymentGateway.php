<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class PaymentGateway extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_enabled',
        'is_active',
        'server_key',
        'client_key',
        'webhook_secret',
        'environment',
        'settings',
        'last_verified_at',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
        'last_verified_at' => 'datetime',
    ];

    /**
     * Hidden attributes - never expose keys to frontend
     */
    protected $hidden = [
        'server_key',
        'client_key',
        'webhook_secret',
    ];

    // ==========================================
    // ENCRYPTION ACCESSORS & MUTATORS
    // ==========================================

    /**
     * Encrypt server_key when setting
     */
    public function setServerKeyAttribute($value)
    {
        $this->attributes['server_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt server_key when getting
     */
    public function getServerKeyAttribute($value)
    {
        if (!$value) return null;
        
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Encrypt client_key when setting
     */
    public function setClientKeyAttribute($value)
    {
        $this->attributes['client_key'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt client_key when getting
     */
    public function getClientKeyAttribute($value)
    {
        if (!$value) return null;
        
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Encrypt webhook_secret when setting
     */
    public function setWebhookSecretAttribute($value)
    {
        $this->attributes['webhook_secret'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt webhook_secret when getting
     */
    public function getWebhookSecretAttribute($value)
    {
        if (!$value) return null;
        
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==========================================
    // SCOPES
    // ==========================================

    /**
     * Get only enabled gateways
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Get the active gateway
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Get the currently active payment gateway
     */
    public static function getActive(): ?self
    {
        return static::where('is_active', true)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Set a gateway as the active one (only 1 can be active)
     * Automatically disables the other gateway
     */
    public static function setActiveGateway(string $name, ?int $updatedBy = null): bool
    {
        $gateway = static::where('name', $name)->first();
        
        if (!$gateway || !$gateway->is_enabled) {
            Log::warning('Cannot set active gateway: not found or not enabled', ['name' => $name]);
            return false;
        }

        if (!$gateway->isConfigured()) {
            Log::warning('Cannot set active gateway: not configured', ['name' => $name]);
            return false;
        }

        // Deactivate all gateways
        static::query()->update(['is_active' => false]);
        
        // Activate the selected one
        $gateway->update([
            'is_active' => true,
            'updated_by' => $updatedBy ?? auth()->id(),
        ]);

        Log::info('Active payment gateway changed', [
            'gateway' => $name,
            'updated_by' => $updatedBy ?? auth()->id(),
        ]);
        
        return true;
    }

    /**
     * Deactivate all gateways (no active gateway)
     */
    public static function deactivateAll(?int $updatedBy = null): void
    {
        static::query()->update([
            'is_active' => false,
            'updated_by' => $updatedBy ?? auth()->id(),
        ]);

        Log::info('All payment gateways deactivated', [
            'updated_by' => $updatedBy ?? auth()->id(),
        ]);
    }

    /**
     * Check if gateway is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->server_key) && !empty($this->client_key);
    }

    /**
     * Check if gateway is ready for transactions
     * Must be enabled, active, and configured
     */
    public function isReady(): bool
    {
        return $this->is_enabled && $this->is_active && $this->isConfigured();
    }

    /**
     * Get validation status with detailed message
     */
    public function getValidationStatus(): array
    {
        if (!$this->is_enabled) {
            return [
                'valid' => false,
                'code' => 'not_enabled',
                'message' => 'Gateway belum diaktifkan (enabled)',
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'valid' => false,
                'code' => 'not_configured',
                'message' => 'Credential gateway belum lengkap',
            ];
        }

        if (!$this->is_active) {
            return [
                'valid' => false,
                'code' => 'not_active',
                'message' => 'Gateway tidak dalam status aktif',
            ];
        }

        return [
            'valid' => true,
            'code' => 'ready',
            'message' => 'Gateway siap digunakan',
        ];
    }

    /**
     * Get environment label for display
     */
    public function getEnvironmentLabel(): string
    {
        return $this->isProduction() ? 'Production' : 'Sandbox';
    }

    /**
     * Get full display name with environment
     */
    public function getFullDisplayName(): string
    {
        return $this->display_name . ' (' . $this->getEnvironmentLabel() . ')';
    }

    /**
     * Get masked key for display (last 4 chars visible)
     */
    public function getMaskedServerKey(): string
    {
        $key = $this->server_key;
        if (!$key || strlen($key) < 8) return '••••••••';
        
        return str_repeat('•', strlen($key) - 4) . substr($key, -4);
    }

    /**
     * Get masked client key for display
     */
    public function getMaskedClientKey(): string
    {
        $key = $this->client_key;
        if (!$key || strlen($key) < 8) return '••••••••';
        
        return str_repeat('•', strlen($key) - 4) . substr($key, -4);
    }

    // ==========================================
    // GATEWAY SPECIFIC CHECKS
    // ==========================================

    public function isMidtrans(): bool
    {
        return $this->name === 'midtrans';
    }

    public function isXendit(): bool
    {
        return $this->name === 'xendit';
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }
}
