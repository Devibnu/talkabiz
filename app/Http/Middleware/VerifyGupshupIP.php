<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyGupshupIP Middleware
 * 
 * Validates IP address against Gupshup whitelist.
 * 
 * SECURITY:
 * - Whitelist IP Gupshup dari config
 * - Jika IP tidak cocok: Abort 403, Log ke security.log
 * 
 * @package App\Http\Middleware
 */
class VerifyGupshupIP
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $this->getClientIp($request);
        $allowedIps = config('gupshup.allowed_ips', []);
        
        // Bypass in local/testing environment if configured
        if (config('gupshup.bypass_ip_check', false) && app()->environment(['local', 'testing'])) {
            $request->attributes->set('gupshup_ip_valid', true);
            $request->attributes->set('gupshup_client_ip', $clientIp);
            return $next($request);
        }
        
        // Validate IP against whitelist
        if (!$this->isIpAllowed($clientIp, $allowedIps)) {
            $this->logSecurityViolation('ip_rejected', [
                'ip' => $clientIp,
                'reason' => 'IP not in Gupshup whitelist',
                'allowed_ips' => $allowedIps,
                'user_agent' => $request->userAgent(),
            ]);
            
            abort(403, 'Forbidden');
        }
        
        // Attach IP validation result to request
        $request->attributes->set('gupshup_ip_valid', true);
        $request->attributes->set('gupshup_client_ip', $clientIp);
        
        // Log successful validation
        Log::channel('webhook')->info('Gupshup IP verified', [
            'ip' => $clientIp,
        ]);
        
        return $next($request);
    }

    /**
     * Get real client IP (handles proxies/load balancers)
     */
    private function getClientIp(Request $request): string
    {
        // Check X-Forwarded-For header first
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
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
     * Check if IP is in the allowed list (supports CIDR notation)
     */
    private function isIpAllowed(string $clientIp, array $allowedIps): bool
    {
        foreach ($allowedIps as $allowedIp) {
            // Exact match
            if ($clientIp === $allowedIp) {
                return true;
            }
            
            // CIDR match
            if (str_contains($allowedIp, '/') && $this->ipInCidr($clientIp, $allowedIp)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Log security violation to security.log
     */
    private function logSecurityViolation(string $event, array $context): void
    {
        Log::channel('security')->warning("GUPSHUP_SECURITY: {$event}", array_merge($context, [
            'timestamp' => now()->toIso8601String(),
            'middleware' => 'VerifyGupshupIP',
        ]));
    }
}
