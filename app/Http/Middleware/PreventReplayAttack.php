<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

/**
 * PreventReplayAttack Middleware
 * 
 * Prevents replay attacks on Gupshup webhooks.
 * 
 * SECURITY:
 * - Ambil timestamp dari payload
 * - Tolak jika selisih waktu > 5 menit
 * - Cache request_id/event_id untuk cegah duplikat
 * 
 * AUDIT:
 * - Log pelanggaran ke storage/logs/security.log
 * 
 * @package App\Http\Middleware
 */
class PreventReplayAttack
{
    /**
     * Maximum age of webhook timestamp (in seconds)
     */
    private const MAX_TIMESTAMP_AGE = 300; // 5 minutes

    /**
     * Cache prefix for processed events
     */
    private const CACHE_PREFIX = 'gupshup_event:';

    /**
     * Cache TTL (in seconds)
     */
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $payload = $request->all();
        $clientIp = $request->ip();
        
        // ==========================================
        // STEP 1: VALIDATE TIMESTAMP
        // ==========================================
        $timestampResult = $this->validateTimestamp($payload);
        
        if (!$timestampResult['valid']) {
            $this->logSecurityViolation('replay_attack_timestamp', [
                'ip' => $clientIp,
                'reason' => $timestampResult['reason'],
                'timestamp_received' => $timestampResult['timestamp'] ?? null,
                'age_seconds' => $timestampResult['age'] ?? null,
            ]);
            
            abort(401, 'Unauthorized');
        }
        
        // ==========================================
        // STEP 2: CHECK EVENT ID (Idempotency)
        // ==========================================
        $eventId = $this->extractEventId($payload);
        
        if ($this->wasProcessed($eventId)) {
            $this->logSecurityViolation('replay_attack_duplicate', [
                'ip' => $clientIp,
                'reason' => 'Event ID already processed',
                'event_id' => $eventId,
            ]);
            
            abort(401, 'Unauthorized');
        }
        
        // Mark event as processed
        $this->markAsProcessed($eventId);
        
        // Attach to request
        $request->attributes->set('gupshup_event_id', $eventId);
        $request->attributes->set('gupshup_timestamp_valid', true);
        
        // Log successful validation
        Log::channel('webhook')->info('Gupshup replay check passed', [
            'ip' => $clientIp,
            'event_id' => $eventId,
        ]);
        
        return $next($request);
    }

    /**
     * Validate webhook timestamp
     */
    private function validateTimestamp(array $payload): array
    {
        // Try different timestamp fields
        $timestamp = $payload['timestamp'] ?? $payload['eventTime'] ?? $payload['ts'] ?? null;
        
        if (empty($timestamp)) {
            return [
                'valid' => false,
                'reason' => 'Timestamp field not found in payload',
                'timestamp' => null,
            ];
        }
        
        try {
            // Parse timestamp (handle various formats)
            if (is_numeric($timestamp)) {
                // Unix timestamp (seconds or milliseconds)
                $timestamp = strlen((string)$timestamp) > 10 
                    ? Carbon::createFromTimestampMs($timestamp)
                    : Carbon::createFromTimestamp($timestamp);
            } else {
                // ISO 8601 or other string format
                $timestamp = Carbon::parse($timestamp);
            }
            
            $now = now();
            $ageSeconds = abs($now->diffInSeconds($timestamp));
            
            if ($ageSeconds > self::MAX_TIMESTAMP_AGE) {
                return [
                    'valid' => false,
                    'reason' => "Timestamp too old: {$ageSeconds}s (max: " . self::MAX_TIMESTAMP_AGE . "s)",
                    'timestamp' => $timestamp->toIso8601String(),
                    'age' => $ageSeconds,
                ];
            }
            
            return [
                'valid' => true,
                'timestamp' => $timestamp->toIso8601String(),
                'age' => $ageSeconds,
            ];
            
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'reason' => 'Invalid timestamp format: ' . $e->getMessage(),
                'timestamp' => $timestamp,
            ];
        }
    }

    /**
     * Extract or generate event ID from payload
     */
    private function extractEventId(array $payload): string
    {
        // Try to get event ID from payload
        $eventId = $payload['event_id'] 
            ?? $payload['eventId'] 
            ?? $payload['id'] 
            ?? $payload['messageId']
            ?? null;
        
        if ($eventId) {
            return (string) $eventId;
        }
        
        // Generate deterministic ID from payload content
        $idParts = [
            $payload['type'] ?? '',
            $payload['phone'] ?? '',
            $payload['timestamp'] ?? '',
            $payload['app'] ?? '',
        ];
        
        return hash('sha256', implode('|', $idParts));
    }

    /**
     * Check if event was already processed
     */
    private function wasProcessed(string $eventId): bool
    {
        return Cache::has(self::CACHE_PREFIX . $eventId);
    }

    /**
     * Mark event as processed
     */
    private function markAsProcessed(string $eventId): void
    {
        Cache::put(
            self::CACHE_PREFIX . $eventId,
            now()->toIso8601String(),
            self::CACHE_TTL
        );
    }

    /**
     * Log security violation to security.log
     */
    private function logSecurityViolation(string $event, array $context): void
    {
        Log::channel('security')->warning("GUPSHUP_SECURITY: {$event}", array_merge($context, [
            'timestamp' => now()->toIso8601String(),
            'middleware' => 'PreventReplayAttack',
        ]));
    }
}
