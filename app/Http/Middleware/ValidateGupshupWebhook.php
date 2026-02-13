<?php

namespace App\Http\Middleware;

use App\Models\WebhookEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ValidateGupshupWebhook Middleware
 * 
 * HARDENED SECURITY untuk webhook Gupshup WhatsApp.
 * 
 * SECURITY LAYERS:
 * 1. IP Whitelist validation
 * 2. HMAC SHA256 Signature validation
 * 3. Replay Attack Prevention (timestamp > 5 menit = reject)
 * 4. Required fields validation
 * 5. Idempotency check
 * 
 * AUDIT:
 * - Semua pelanggaran di-log ke storage/logs/security.log
 * - Semua webhook di-log ke storage/logs/webhook.log
 * 
 * @package App\Http\Middleware
 */
class ValidateGupshupWebhook
{
    /**
     * Maximum age of webhook timestamp (in seconds)
     * Webhooks older than this are considered replay attacks
     */
    private const MAX_TIMESTAMP_AGE_SECONDS = 300; // 5 minutes

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('webhook.gupshup');
        $clientIp = $this->getClientIp($request);
        $rawPayload = $request->getContent();
        $payloadHash = hash('sha256', $rawPayload);
        $requestId = uniqid('wh_', true);
        
        // Log webhook received (audit)
        $this->logWebhookEvent('webhook_received', [
            'request_id' => $requestId,
            'ip' => $clientIp,
            'payload_hash' => $payloadHash,
            'content_length' => strlen($rawPayload),
            'user_agent' => $request->userAgent(),
        ]);

        // ==========================================
        // LAYER 1: IP WHITELIST VALIDATION
        // ==========================================
        $ipValid = $this->validateIp($clientIp, $config);
        
        if (!$ipValid) {
            $this->logSecurityViolation('ip_rejected', [
                'request_id' => $requestId,
                'ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'reason' => 'IP not in whitelist',
            ]);
            
            // Return 200 to not reveal rejection (security through obscurity)
            return response()->json(['status' => 'ok'], 200);
        }
        
        // ==========================================
        // LAYER 2: HMAC SHA256 SIGNATURE VALIDATION
        // ==========================================
        $signatureResult = $this->validateSignature($request, $rawPayload, $config);
        
        if (!$signatureResult['valid']) {
            $this->logSecurityViolation('signature_invalid', [
                'request_id' => $requestId,
                'ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'reason' => $signatureResult['reason'],
                'has_header' => $request->hasHeader($config['signature_header']),
            ]);
            
            return response()->json(['status' => 'ok'], 200);
        }
        
        // ==========================================
        // LAYER 3: REPLAY ATTACK PREVENTION
        // ==========================================
        $payload = $request->all();
        $timestampResult = $this->validateTimestamp($payload);
        
        if (!$timestampResult['valid']) {
            $this->logSecurityViolation('replay_attack_detected', [
                'request_id' => $requestId,
                'ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'reason' => $timestampResult['reason'],
                'timestamp_received' => $timestampResult['timestamp'] ?? null,
                'age_seconds' => $timestampResult['age'] ?? null,
            ]);
            
            return response()->json(['status' => 'ok'], 200);
        }
        
        // ==========================================
        // LAYER 4: REQUIRED FIELDS VALIDATION
        // ==========================================
        $missingFields = $this->validateRequiredFields($payload, $config);
        
        if (!empty($missingFields)) {
            $this->logSecurityViolation('missing_required_fields', [
                'request_id' => $requestId,
                'ip' => $clientIp,
                'payload_hash' => $payloadHash,
                'missing_fields' => $missingFields,
            ]);
            
            return response()->json(['status' => 'ok'], 200);
        }
        
        // ==========================================
        // LAYER 5: IDEMPOTENCY CHECK
        // ==========================================
        $eventId = WebhookEvent::generateEventId($payload);
        
        if (WebhookEvent::wasProcessed($eventId)) {
            $this->logWebhookEvent('duplicate_webhook_ignored', [
                'request_id' => $requestId,
                'ip' => $clientIp,
                'event_id' => $eventId,
            ]);
            
            return response()->json(['status' => 'ok'], 200);
        }
        
        // ==========================================
        // ALL VALIDATIONS PASSED
        // ==========================================
        $this->logWebhookEvent('webhook_verified', [
            'request_id' => $requestId,
            'ip' => $clientIp,
            'event_id' => $eventId,
            'event_type' => $payload['type'] ?? 'unknown',
            'phone' => $payload['phone'] ?? null,
        ]);
        
        // Attach validated data to request for controller
        $request->attributes->set('webhook_validated', true);
        $request->attributes->set('webhook_request_id', $requestId);
        $request->attributes->set('webhook_event_id', $eventId);
        $request->attributes->set('webhook_payload_hash', $payloadHash);
        $request->attributes->set('webhook_ip_valid', true);
        $request->attributes->set('webhook_signature_valid', true);
        $request->attributes->set('webhook_timestamp_valid', true);
        $request->attributes->set('webhook_client_ip', $clientIp);
        
        return $next($request);
    }

    /**
     * Get real client IP (handles proxies)
     */
    private function getClientIp(Request $request): string
    {
        // Check X-Forwarded-For header first (if behind proxy/load balancer)
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            // Take the first IP (original client)
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0];
        }
        
        // Check X-Real-IP header
        $realIp = $request->header('X-Real-IP');
        if ($realIp) {
            return $realIp;
        }
        
        return $request->ip();
    }

    /**
     * Validate IP against whitelist
     */
    private function validateIp(string $clientIp, array $config): bool
    {
        // Bypass in development if configured
        if (($config['bypass_ip_check'] ?? false) && app()->environment(['local', 'testing'])) {
            return true;
        }
        
        $allowedIps = $config['allowed_ips'] ?? [];
        
        // Check exact match
        if (in_array($clientIp, $allowedIps, true)) {
            return true;
        }
        
        // Check CIDR ranges
        foreach ($allowedIps as $allowed) {
            if (str_contains($allowed, '/') && $this->ipInCidr($clientIp, $allowed)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $mask = (int) $mask;
        
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        
        $maskLong = -1 << (32 - $mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Validate HMAC SHA256 signature
     */
    private function validateSignature(Request $request, string $rawPayload, array $config): array
    {
        $secret = $config['secret'] ?? null;
        
        // Secret WAJIB ada di production
        if (empty($secret)) {
            Log::channel('security')->critical('GUPSHUP_WEBHOOK_SECRET not configured!');
            return [
                'valid' => false,
                'reason' => 'webhook_secret_not_configured',
            ];
        }
        
        $signatureHeader = $config['signature_header'] ?? 'X-Gupshup-Signature';
        $providedSignature = $request->header($signatureHeader);
        
        if (empty($providedSignature)) {
            return [
                'valid' => false,
                'reason' => 'signature_header_missing',
            ];
        }
        
        // Remove any prefix (e.g., "sha256=")
        $providedSignature = preg_replace('/^sha256=/', '', $providedSignature);
        
        // Calculate expected signature using HMAC SHA256
        $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);
        
        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return [
                'valid' => false,
                'reason' => 'signature_mismatch',
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Validate timestamp to prevent replay attacks
     * Reject webhooks older than 5 minutes
     */
    private function validateTimestamp(array $payload): array
    {
        // Try multiple timestamp field names
        $timestampFields = ['timestamp', 'ts', 'time', 'created_at', 'sent_at'];
        $timestamp = null;
        
        foreach ($timestampFields as $field) {
            if (!empty($payload[$field])) {
                $timestamp = $payload[$field];
                break;
            }
            // Check nested payload
            if (!empty($payload['payload'][$field])) {
                $timestamp = $payload['payload'][$field];
                break;
            }
        }
        
        // If no timestamp found, allow but log warning
        // Some webhook payloads might not have timestamp
        if (empty($timestamp)) {
            Log::channel('webhook')->warning('Webhook without timestamp received', [
                'payload_keys' => array_keys($payload),
            ]);
            return ['valid' => true, 'reason' => 'no_timestamp_field'];
        }
        
        // Parse timestamp
        try {
            if (is_numeric($timestamp)) {
                // Unix timestamp (seconds or milliseconds)
                $ts = strlen((string)$timestamp) > 10 
                    ? (int)($timestamp / 1000)  // milliseconds
                    : (int)$timestamp;           // seconds
                $webhookTime = \Carbon\Carbon::createFromTimestamp($ts);
            } else {
                // ISO 8601 or other string format
                $webhookTime = \Carbon\Carbon::parse($timestamp);
            }
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'reason' => 'invalid_timestamp_format',
                'timestamp' => $timestamp,
            ];
        }
        
        $now = now();
        $ageSeconds = $now->diffInSeconds($webhookTime, false);
        
        // Check if timestamp is in the future (clock skew tolerance: 60 seconds)
        if ($ageSeconds < -60) {
            return [
                'valid' => false,
                'reason' => 'timestamp_in_future',
                'timestamp' => $webhookTime->toIso8601String(),
                'age' => $ageSeconds,
            ];
        }
        
        // Check if timestamp is too old (> 5 minutes)
        if ($ageSeconds > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return [
                'valid' => false,
                'reason' => 'timestamp_too_old',
                'timestamp' => $webhookTime->toIso8601String(),
                'age' => $ageSeconds,
                'max_age' => self::MAX_TIMESTAMP_AGE_SECONDS,
            ];
        }
        
        return [
            'valid' => true,
            'timestamp' => $webhookTime->toIso8601String(),
            'age' => $ageSeconds,
        ];
    }

    /**
     * Validate required fields in payload
     */
    private function validateRequiredFields(array $payload, array $config): array
    {
        $requiredFields = $config['required_fields'] ?? ['app', 'phone', 'type'];
        $missing = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }

    /**
     * Log security violation to security.log
     */
    private function logSecurityViolation(string $type, array $context): void
    {
        Log::channel('security')->warning("WEBHOOK_SECURITY_VIOLATION: {$type}", array_merge($context, [
            'provider' => 'gupshup',
            'severity' => $this->getViolationSeverity($type),
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    /**
     * Log webhook event to webhook.log
     */
    private function logWebhookEvent(string $event, array $context): void
    {
        Log::channel('webhook')->info("WEBHOOK_EVENT: {$event}", array_merge($context, [
            'provider' => 'gupshup',
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    /**
     * Get severity level for violation type
     */
    private function getViolationSeverity(string $type): string
    {
        return match($type) {
            'replay_attack_detected' => 'HIGH',
            'signature_invalid' => 'HIGH',
            'ip_rejected' => 'MEDIUM',
            'missing_required_fields' => 'LOW',
            default => 'LOW',
        };
    }
}
