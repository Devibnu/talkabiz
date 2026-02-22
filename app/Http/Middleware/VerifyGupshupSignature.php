<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyGupshupSignature Middleware
 * 
 * Validates HMAC SHA256 signature from Gupshup webhook.
 * 
 * SECURITY:
 * - Ambil header: X-Gupshup-Signature
 * - Validasi HMAC SHA256 dari raw body
 * - Secret dari env GUPSHUP_WEBHOOK_SECRET
 * 
 * AUDIT:
 * - Semua pelanggaran di-log ke storage/logs/security.log
 * 
 * @package App\Http\Middleware
 */
class VerifyGupshupSignature
{
    /**
     * Header name for Gupshup signature
     */
    private const SIGNATURE_HEADER = 'X-Gupshup-Signature';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('gupshup.webhook_secret');
        $rawPayload = $request->getContent();
        $clientIp = $request->ip();
        
        // Get signature from header
        $signature = $request->header(self::SIGNATURE_HEADER);
        
        // Check if signature header exists
        if (empty($signature)) {
            $this->logSecurityViolation('signature_missing', [
                'ip' => $clientIp,
                'reason' => 'X-Gupshup-Signature header not found',
                'user_agent' => $request->userAgent(),
            ]);
            
            abort(401, 'Unauthorized');
        }
        
        // Check if secret is configured
        if (empty($secret)) {
            \App\Helpers\SecurityLog::error('GUPSHUP_WEBHOOK_SECRET not configured', [
                'ip' => $clientIp,
            ]);
            
            abort(500, 'Webhook configuration error');
        }
        
        // Calculate expected signature
        $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);
        
        // Validate signature (timing-safe comparison)
        if (!hash_equals($expectedSignature, $signature)) {
            $this->logSecurityViolation('signature_invalid', [
                'ip' => $clientIp,
                'reason' => 'HMAC signature mismatch',
                'payload_hash' => hash('sha256', $rawPayload),
                'signature_received' => substr($signature, 0, 16) . '...',
            ]);
            
            abort(401, 'Unauthorized');
        }
        
        // Attach signature validation result to request
        $request->attributes->set('gupshup_signature_valid', true);
        $request->attributes->set('gupshup_payload_hash', hash('sha256', $rawPayload));
        
        // Log successful validation
        Log::channel('webhook')->info('Gupshup signature verified', [
            'ip' => $clientIp,
            'payload_size' => strlen($rawPayload),
        ]);
        
        return $next($request);
    }

    /**
     * Log security violation to security.log
     */
    private function logSecurityViolation(string $event, array $context): void
    {
        \App\Helpers\SecurityLog::warning("GUPSHUP_SECURITY: {$event}", array_merge($context, [
            'timestamp' => now()->toIso8601String(),
            'middleware' => 'VerifyGupshupSignature',
        ]));
    }
}
