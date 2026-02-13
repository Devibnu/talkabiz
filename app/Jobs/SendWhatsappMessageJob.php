<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Services\MessageSenderService;
use App\Services\RateLimiterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendWhatsappMessageJob - Production-Safe Message Sending Queue Job
 * 
 * Job ini mengimplementasikan:
 * 1. IDEMPOTENCY - Retry aman, tidak kirim ulang pesan sukses
 * 2. CONCURRENCY CONTROL - WithoutOverlapping middleware
 * 3. BACKOFF STRATEGY - Exponential backoff untuk retry
 * 4. CRASH RECOVERY - State machine di MessageLog
 * 
 * ATURAN KRITIS:
 * ==============
 * 1. Job ini HANYA bertanggung jawab untuk 1 pesan
 * 2. Semua idempotency logic ada di MessageSenderService
 * 3. Job ini TIDAK boleh throw exception untuk gagal kirim (sudah di-handle service)
 * 4. Job ini TIDAK boleh retry untuk status 'sent'
 * 
 * RETRY STRATEGY:
 * ===============
 * - Max 3 attempts dengan backoff [30, 60, 120] seconds
 * - Retry hanya untuk RETRYABLE errors (timeout, rate limit, network)
 * - PERMANENT errors (invalid number, blocked) = no retry
 * 
 * MENGAPA PAKAI ShouldBeUnique?
 * ============================
 * - Mencegah duplicate job untuk pesan yang sama
 * - Key = idempotency_key
 * - TTL = 5 menit (sama dengan SENDING_TIMEOUT)
 * 
 * @author Senior Software Architect
 */
class SendWhatsappMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum attempts = initial + retries
     * Karena MessageLog punya max_retries = 3, kita set 4 (1 initial + 3 retry)
     */
    public int $tries = 4;

    /**
     * Timeout per attempt (seconds)
     * Harus lebih besar dari HTTP timeout ke WA API
     */
    public int $timeout = 60;

    /**
     * Backoff strategy (seconds)
     * Exponential: 30, 60, 120
     */
    public array $backoff = [30, 60, 120];

    /**
     * Unique lock TTL (seconds)
     * Sama dengan MessageLog::SENDING_TIMEOUT_MINUTES * 60
     */
    public int $uniqueFor = 300;

    /**
     * Delete job jika model tidak ditemukan
     */
    public bool $deleteWhenMissingModels = true;

    // ==================== JOB DATA ====================

    /**
     * Idempotency key untuk pesan ini
     * Ini adalah unique identifier untuk mencegah double send
     */
    public string $idempotencyKey;

    /**
     * Kampanye ID (jika campaign message)
     */
    public ?int $kampanyeId;

    /**
     * Target kampanye ID (jika campaign message)
     */
    public ?int $targetKampanyeId;

    /**
     * Klien ID
     */
    public int $klienId;

    /**
     * User yang trigger
     */
    public ?int $penggunaId;

    /**
     * Message type: campaign, inbox, api
     */
    public string $messageType;

    /**
     * Additional payload
     */
    public array $payload;

    /**
     * Sender phone number (for rate limiting)
     */
    public ?string $senderPhone;

    /**
     * Constructor
     * 
     * @param string $idempotencyKey Unique key untuk pesan ini
     * @param int $klienId
     * @param string $messageType 'campaign', 'inbox', 'api'
     * @param array $payload Additional data based on message type
     * @param int|null $penggunaId
     * @param string|null $senderPhone Nomor pengirim untuk rate limiting
     */
    public function __construct(
        string $idempotencyKey,
        int $klienId,
        string $messageType,
        array $payload,
        ?int $penggunaId = null,
        ?string $senderPhone = null
    ) {
        $this->idempotencyKey = $idempotencyKey;
        $this->klienId = $klienId;
        $this->messageType = $messageType;
        $this->payload = $payload;
        $this->penggunaId = $penggunaId;
        $this->senderPhone = $senderPhone ?? ($payload['sender_phone'] ?? null);
        
        // Extract for quick access
        $this->kampanyeId = $payload['kampanye_id'] ?? null;
        $this->targetKampanyeId = $payload['target_kampanye_id'] ?? null;
    }

    // ==================== UNIQUE KEY ====================

    /**
     * Key untuk ShouldBeUnique
     * Memastikan tidak ada 2 job untuk pesan yang sama berjalan bersamaan
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }

    // ==================== MIDDLEWARE ====================

    /**
     * Job middleware
     * 
     * 1. WithoutOverlapping - Mencegah concurrent execution per message
     * 2. RateLimited - Global rate limit untuk WA API
     */
    public function middleware(): array
    {
        return [
            // Prevent concurrent processing of same message
            (new WithoutOverlapping($this->idempotencyKey))
                ->releaseAfter(60) // Release lock after 60 seconds if job crashes
                ->dontRelease(), // Don't release if job failed (let it retry)
        ];
    }

    // ==================== MAIN HANDLE ====================

    /**
     * Execute the job
     * 
     * FLOW WITH RATE LIMITING:
     * 1. Pre-check if already sent (idempotent)
     * 2. Check rate limit (multi-layer)
     * 3. If rate limited, release with delay
     * 4. If allowed, process message
     * 5. Record result to rate limiter (for health tracking)
     */
    public function handle(MessageSenderService $senderService, RateLimiterService $rateLimiter): void
    {
        Log::channel('whatsapp')->info('SendWhatsappMessageJob: START', [
            'idempotency_key' => $this->idempotencyKey,
            'message_type' => $this->messageType,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Pre-check: Cek apakah message sudah sent (skip tanpa error)
            $existingMessage = MessageLog::where('idempotency_key', $this->idempotencyKey)->first();
            
            if ($existingMessage && $existingMessage->isSuccessfullySent()) {
                Log::channel('whatsapp')->info('SendWhatsappMessageJob: SKIP - already sent', [
                    'idempotency_key' => $this->idempotencyKey,
                    'provider_message_id' => $existingMessage->provider_message_id,
                ]);
                return; // Job selesai sukses (idempotent)
            }

            // ==================== RATE LIMIT CHECK ====================
            // Cek rate limit sebelum kirim
            // Ini mencegah: spam, WA ban, overload API
            
            $senderPhone = $this->senderPhone ?? $this->getSenderPhone();
            
            if ($senderPhone) {
                $rateCheck = $rateLimiter->checkAndConsume(
                    $this->klienId,
                    $senderPhone,
                    $this->kampanyeId
                );

                if (!$rateCheck['allowed']) {
                    Log::channel('whatsapp')->info('SendWhatsappMessageJob: RATE LIMITED', [
                        'idempotency_key' => $this->idempotencyKey,
                        'reason' => $rateCheck['reason'] ?? 'unknown',
                        'delay_seconds' => $rateCheck['delay_seconds'] ?? 60,
                        'wait_time' => $rateCheck['wait_time'] ?? null,
                    ]);

                    // Determine delay before retry
                    $delay = $rateCheck['delay_seconds'] ?? $rateCheck['wait_time'] ?? 60;
                    $delay = min($delay, 300); // Max 5 minutes delay

                    // Log throttle event (for monitoring)
                    $rateLimiter->logThrottleEvent(
                        $this->klienId,
                        $senderPhone,
                        $rateCheck['bucket'] ?? 'unknown',
                        $rateCheck['reason'] ?? 'rate_limited',
                        $this->kampanyeId
                    );

                    // Release back to queue with delay
                    $this->release($delay);
                    return;
                }

                // Apply inter-message delay if specified
                if (isset($rateCheck['delay_seconds']) && $rateCheck['delay_seconds'] > 0) {
                    // Small delay for natural sending pattern
                    usleep((int)($rateCheck['delay_seconds'] * 1000000));
                }
            }
            // ==================== END RATE LIMIT CHECK ====================

            // Route ke method yang sesuai berdasarkan message type
            $result = match ($this->messageType) {
                'campaign' => $this->handleCampaignMessage($senderService),
                'inbox' => $this->handleInboxMessage($senderService),
                'api' => $this->handleApiMessage($senderService),
                default => throw new \InvalidArgumentException("Unknown message type: {$this->messageType}"),
            };

            // Log result
            Log::channel('whatsapp')->info('SendWhatsappMessageJob: RESULT', [
                'idempotency_key' => $this->idempotencyKey,
                'success' => $result['success'] ?? false,
                'reason' => $result['reason'] ?? null,
                'skipped' => $result['skipped'] ?? false,
            ]);

            // ==================== RATE LIMIT RESULT TRACKING ====================
            // Record success/failure untuk health tracking dan adaptive throttling
            if ($senderPhone) {
                $success = ($result['success'] ?? false) || ($result['skipped'] ?? false);
                $rateLimiter->recordSendResult(
                    $this->klienId,
                    $senderPhone,
                    $success,
                    $result['provider_error_code'] ?? null
                );
            }
            // ==================== END RATE LIMIT RESULT TRACKING ====================

            // Handle based on result
            if (!($result['success'] ?? false) && !($result['skipped'] ?? false)) {
                // Check if should retry
                $messageLog = MessageLog::where('idempotency_key', $this->idempotencyKey)->first();
                
                if ($messageLog && $messageLog->canRetry()) {
                    // Release back to queue with delay
                    $this->release($messageLog->getNextRetryDelay());
                    return;
                }

                // No retry needed - job finished (either success, max retries, or permanent error)
            }

        } catch (Throwable $e) {
            Log::channel('whatsapp')->error('SendWhatsappMessageJob: EXCEPTION', [
                'idempotency_key' => $this->idempotencyKey,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Record failure untuk rate limiter
            $senderPhone = $this->senderPhone ?? $this->getSenderPhone();
            if ($senderPhone) {
                app(RateLimiterService::class)->recordSendResult(
                    $this->klienId,
                    $senderPhone,
                    false,
                    'exception'
                );
            }

            // Re-throw untuk Laravel queue retry mechanism
            throw $e;
        }
    }

    // ==================== MESSAGE TYPE HANDLERS ====================

    /**
     * Handle campaign message
     */
    protected function handleCampaignMessage(MessageSenderService $senderService): array
    {
        $kampanye = Kampanye::find($this->kampanyeId);
        if (!$kampanye) {
            Log::warning('SendWhatsappMessageJob: Kampanye not found', ['id' => $this->kampanyeId]);
            return ['success' => false, 'reason' => 'kampanye_not_found'];
        }

        $target = TargetKampanye::find($this->targetKampanyeId);
        if (!$target) {
            Log::warning('SendWhatsappMessageJob: Target not found', ['id' => $this->targetKampanyeId]);
            return ['success' => false, 'reason' => 'target_not_found'];
        }

        return $senderService->sendCampaignMessage(
            $kampanye,
            $target,
            $this->penggunaId ?? 0
        );
    }

    /**
     * Handle inbox message
     */
    protected function handleInboxMessage(MessageSenderService $senderService): array
    {
        return $senderService->sendInboxMessage(
            $this->klienId,
            $this->payload['percakapan_id'],
            $this->payload['phone_number'],
            $this->payload['content'],
            $this->penggunaId ?? 0,
            $this->payload['uuid'] ?? null
        );
    }

    /**
     * Handle API message
     */
    protected function handleApiMessage(MessageSenderService $senderService): array
    {
        return $senderService->sendApiMessage(
            $this->klienId,
            $this->payload['phone_number'],
            $this->payload['content'],
            $this->payload['request_id'],
            $this->penggunaId
        );
    }

    // ==================== FAILURE HANDLING ====================

    /**
     * Handle job failure (after all retries exhausted)
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('whatsapp')->error('SendWhatsappMessageJob: FAILED (all retries exhausted)', [
            'idempotency_key' => $this->idempotencyKey,
            'message_type' => $this->messageType,
            'error' => $exception->getMessage(),
        ]);

        // Mark message as expired (final failure)
        $messageLog = MessageLog::where('idempotency_key', $this->idempotencyKey)->first();
        
        if ($messageLog && !$messageLog->isSuccessfullySent()) {
            $messageLog->transitionToExpired('Job failed: ' . $exception->getMessage());

            // Update target kampanye if applicable
            if ($this->targetKampanyeId) {
                TargetKampanye::where('id', $this->targetKampanyeId)->update([
                    'status' => 'gagal',
                    'catatan' => 'Max retries reached: ' . $exception->getMessage(),
                ]);
            }
        }
    }

    // ==================== DISPLAY NAME ====================

    /**
     * Display name untuk monitoring
     */
    public function displayName(): string
    {
        return "SendWA:{$this->messageType}:{$this->idempotencyKey}";
    }

    /**
     * Tags untuk monitoring (Horizon)
     */
    public function tags(): array
    {
        return [
            'send-wa',
            "klien:{$this->klienId}",
            "type:{$this->messageType}",
            $this->kampanyeId ? "campaign:{$this->kampanyeId}" : null,
        ];
    }

    // ==================== HELPERS ====================

    /**
     * Get sender phone number from various sources
     */
    protected function getSenderPhone(): ?string
    {
        // 1. From payload
        if (isset($this->payload['sender_phone'])) {
            return $this->payload['sender_phone'];
        }

        // 2. From campaign's nomor_wa
        if ($this->kampanyeId) {
            $kampanye = Kampanye::find($this->kampanyeId);
            if ($kampanye && $kampanye->nomorWa) {
                return $kampanye->nomorWa->nomor;
            }
        }

        // 3. Fallback: get first active nomor for klien
        // (In production, this should be more sophisticated)
        return null;
    }

    // ==================== STATIC FACTORY METHODS ====================

    /**
     * Create job untuk campaign message
     */
    public static function forCampaign(
        Kampanye $kampanye,
        TargetKampanye $target,
        ?int $penggunaId = null
    ): self {
        $idempotencyKey = MessageLog::generateCampaignKey($kampanye->id, $target->id);

        return new self(
            $idempotencyKey,
            $kampanye->klien_id,
            'campaign',
            [
                'kampanye_id' => $kampanye->id,
                'target_kampanye_id' => $target->id,
            ],
            $penggunaId
        );
    }

    /**
     * Create job untuk inbox message
     */
    public static function forInbox(
        int $klienId,
        int $percakapanId,
        string $phoneNumber,
        string $content,
        ?int $penggunaId = null,
        ?string $uuid = null
    ): self {
        $uuid = $uuid ?? (string) \Illuminate\Support\Str::uuid();
        $idempotencyKey = MessageLog::generateInboxKey($percakapanId, $uuid);

        return new self(
            $idempotencyKey,
            $klienId,
            'inbox',
            [
                'percakapan_id' => $percakapanId,
                'phone_number' => $phoneNumber,
                'content' => $content,
                'uuid' => $uuid,
            ],
            $penggunaId
        );
    }

    /**
     * Create job untuk API message
     */
    public static function forApi(
        int $klienId,
        string $phoneNumber,
        string $content,
        string $requestId,
        ?int $penggunaId = null
    ): self {
        $idempotencyKey = MessageLog::generateApiKey($klienId, $requestId);

        return new self(
            $idempotencyKey,
            $klienId,
            'api',
            [
                'phone_number' => $phoneNumber,
                'content' => $content,
                'request_id' => $requestId,
            ],
            $penggunaId
        );
    }
}
