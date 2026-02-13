<?php

namespace App\Services;

/**
 * DataMaskingService - Privacy & Data Protection
 * 
 * Purpose:
 * - Mask sensitive data before logging
 * - Comply with privacy requirements
 * - Prevent exposure of PII in logs
 * 
 * Masking Types:
 * - Phone: 628123456789 → 6281****6789
 * - Email: user@example.com → u***@example.com
 * - Token: abc123xyz → abc***xyz
 * - Card: 4111111111111111 → 4111****1111
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class DataMaskingService
{
    // ==================== CONFIGURATION ====================
    
    /**
     * Fields that should be fully redacted (replaced with ****)
     */
    protected array $redactFields = [
        'password',
        'password_confirmation',
        'secret',
        'api_secret',
        'private_key',
        'encryption_key',
        'access_token',
        'refresh_token',
        'bearer_token',
        'authorization',
        'x-api-key',
    ];
    
    /**
     * Fields that should be partially masked
     */
    protected array $maskFields = [
        // Phone numbers
        'phone' => 'phone',
        'phone_number' => 'phone',
        'mobile' => 'phone',
        'nomor_wa' => 'phone',
        'whatsapp' => 'phone',
        'recipient' => 'phone',
        'sender' => 'phone',
        'from' => 'phone',
        'to' => 'phone',
        
        // Email
        'email' => 'email',
        'user_email' => 'email',
        'admin_email' => 'email',
        
        // Tokens/Keys
        'token' => 'token',
        'api_key' => 'token',
        'webhook_token' => 'token',
        'session_id' => 'token',
        
        // Payment
        'card_number' => 'card',
        'credit_card' => 'card',
        'account_number' => 'account',
        'bank_account' => 'account',
        
        // Personal
        'name' => 'name',
        'full_name' => 'name',
        'first_name' => 'name',
        'last_name' => 'name',
        'address' => 'address',
        'ip_address' => 'ip',
    ];
    
    /**
     * Fields to completely exclude from logs
     */
    protected array $excludeFields = [
        'password_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];
    
    // ==================== MAIN METHODS ====================
    
    /**
     * Mask array of values
     */
    public function maskArray(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['[nested_too_deep]'];
        }
        
        $masked = [];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Exclude completely
            if (in_array($lowerKey, $this->excludeFields)) {
                continue;
            }
            
            // Redact completely
            if (in_array($lowerKey, $this->redactFields)) {
                $masked[$key] = '[REDACTED]';
                continue;
            }
            
            // Handle nested arrays/objects
            if (is_array($value)) {
                $masked[$key] = $this->maskArray($value, $depth + 1);
                continue;
            }
            
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $masked[$key] = $this->maskArray($value->toArray(), $depth + 1);
                } else {
                    $masked[$key] = '[OBJECT]';
                }
                continue;
            }
            
            // Check if field should be masked
            if (isset($this->maskFields[$lowerKey])) {
                $maskType = $this->maskFields[$lowerKey];
                $masked[$key] = $this->applyMask($value, $maskType);
                continue;
            }
            
            // Auto-detect and mask
            $masked[$key] = $this->autoDetectAndMask($value);
        }
        
        return $masked;
    }
    
    /**
     * Mask single value with specific type
     */
    public function mask($value, string $type): string
    {
        return $this->applyMask($value, $type);
    }
    
    /**
     * Mask phone number
     */
    public function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }
        
        // Remove non-numeric
        $clean = preg_replace('/[^0-9]/', '', $phone);
        $length = strlen($clean);
        
        if ($length < 8) {
            return str_repeat('*', $length);
        }
        
        // Show first 4 and last 4
        $prefix = substr($clean, 0, 4);
        $suffix = substr($clean, -4);
        $middle = str_repeat('*', $length - 8);
        
        return $prefix . $middle . $suffix;
    }
    
    /**
     * Mask email address
     */
    public function maskEmail(?string $email): string
    {
        if (empty($email)) {
            return '';
        }
        
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return str_repeat('*', strlen($email));
        }
        
        $local = $parts[0];
        $domain = $parts[1];
        
        // Show first char of local
        $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(0, strlen($local) - 1));
        
        return $maskedLocal . '@' . $domain;
    }
    
    /**
     * Mask token/API key
     */
    public function maskToken(?string $token): string
    {
        if (empty($token)) {
            return '';
        }
        
        $length = strlen($token);
        
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        
        // Show first 4 and last 4
        $prefix = substr($token, 0, 4);
        $suffix = substr($token, -4);
        
        return $prefix . '****' . $suffix;
    }
    
    /**
     * Mask credit card number
     */
    public function maskCard(?string $card): string
    {
        if (empty($card)) {
            return '';
        }
        
        $clean = preg_replace('/[^0-9]/', '', $card);
        $length = strlen($clean);
        
        if ($length < 12) {
            return str_repeat('*', $length);
        }
        
        // Show first 4 and last 4 (PCI compliant)
        $prefix = substr($clean, 0, 4);
        $suffix = substr($clean, -4);
        
        return $prefix . str_repeat('*', $length - 8) . $suffix;
    }
    
    /**
     * Mask bank account number
     */
    public function maskAccount(?string $account): string
    {
        if (empty($account)) {
            return '';
        }
        
        $clean = preg_replace('/[^0-9]/', '', $account);
        $length = strlen($clean);
        
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        
        // Show last 4 only
        return str_repeat('*', $length - 4) . substr($clean, -4);
    }
    
    /**
     * Mask name
     */
    public function maskName(?string $name): string
    {
        if (empty($name)) {
            return '';
        }
        
        $words = explode(' ', trim($name));
        $masked = [];
        
        foreach ($words as $word) {
            if (strlen($word) <= 2) {
                $masked[] = $word;
            } else {
                // First letter + asterisks
                $masked[] = substr($word, 0, 1) . str_repeat('*', strlen($word) - 1);
            }
        }
        
        return implode(' ', $masked);
    }
    
    /**
     * Mask address
     */
    public function maskAddress(?string $address): string
    {
        if (empty($address)) {
            return '';
        }
        
        $length = strlen($address);
        
        if ($length <= 10) {
            return str_repeat('*', $length);
        }
        
        // Show first 10 chars
        return substr($address, 0, 10) . '***';
    }
    
    /**
     * Mask IP address
     */
    public function maskIp(?string $ip): string
    {
        if (empty($ip)) {
            return '';
        }
        
        // IPv4
        if (strpos($ip, '.') !== false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.***.***';
            }
        }
        
        // IPv6
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return $parts[0] . ':' . $parts[1] . ':****:****';
            }
        }
        
        return str_repeat('*', strlen($ip));
    }
    
    // ==================== INTERNAL METHODS ====================
    
    /**
     * Apply mask based on type
     */
    protected function applyMask($value, string $type): string
    {
        if ($value === null) {
            return '';
        }
        
        $value = (string) $value;
        
        return match ($type) {
            'phone' => $this->maskPhone($value),
            'email' => $this->maskEmail($value),
            'token' => $this->maskToken($value),
            'card' => $this->maskCard($value),
            'account' => $this->maskAccount($value),
            'name' => $this->maskName($value),
            'address' => $this->maskAddress($value),
            'ip' => $this->maskIp($value),
            default => $this->maskGeneric($value),
        };
    }
    
    /**
     * Generic masking
     */
    protected function maskGeneric(?string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        $length = strlen($value);
        
        if ($length <= 4) {
            return $value;
        }
        
        // Show first 2 and last 2
        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }
    
    /**
     * Auto-detect type and mask
     */
    protected function autoDetectAndMask($value): mixed
    {
        if (!is_string($value) || empty($value)) {
            return $value;
        }
        
        // Phone number pattern (Indonesian)
        if (preg_match('/^(\+?62|08)[0-9]{8,12}$/', preg_replace('/[^0-9+]/', '', $value))) {
            return $this->maskPhone($value);
        }
        
        // Email pattern
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->maskEmail($value);
        }
        
        // Credit card pattern (16 digits)
        $clean = preg_replace('/[^0-9]/', '', $value);
        if (strlen($clean) >= 15 && strlen($clean) <= 19 && preg_match('/^[0-9]+$/', $clean)) {
            // Check if looks like card number
            if (preg_match('/^(4|5[1-5]|3[47]|6)/', $clean)) {
                return $this->maskCard($value);
            }
        }
        
        // Long token-like strings (mix of alphanumeric, 32+ chars)
        if (strlen($value) >= 32 && preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            return $this->maskToken($value);
        }
        
        // IP address
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $this->maskIp($value);
        }
        
        return $value;
    }
    
    // ==================== CONFIGURATION ====================
    
    /**
     * Add custom redact field
     */
    public function addRedactField(string $field): self
    {
        $this->redactFields[] = strtolower($field);
        return $this;
    }
    
    /**
     * Add custom mask field
     */
    public function addMaskField(string $field, string $type): self
    {
        $this->maskFields[strtolower($field)] = $type;
        return $this;
    }
    
    /**
     * Add custom exclude field
     */
    public function addExcludeField(string $field): self
    {
        $this->excludeFields[] = strtolower($field);
        return $this;
    }
    
    /**
     * Check if field is sensitive
     */
    public function isSensitiveField(string $field): bool
    {
        $lower = strtolower($field);
        
        return in_array($lower, $this->redactFields)
            || isset($this->maskFields[$lower])
            || in_array($lower, $this->excludeFields);
    }
    
    /**
     * Get all sensitive field names
     */
    public function getSensitiveFields(): array
    {
        return array_unique(array_merge(
            $this->redactFields,
            array_keys($this->maskFields),
            $this->excludeFields
        ));
    }
}
