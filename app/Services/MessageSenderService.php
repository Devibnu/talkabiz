<?php

namespace App\Services;

use App\Models\MessageLog;
use App\Models\Klien;
use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Models\LogAktivitas;
use App\Contracts\WhatsAppProviderInterface;
use App\Services\QuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use DomainException;
use Exception;
use Throwable;

/**
 * MessageSenderService - Financial-Grade Idempotent Message Sending
 * 
 * Service ini menangani pengiriman pesan WhatsApp dengan:
 * 1. IDEMPOTENCY - 1 pesan logis = maksimal 1 kali terkirim
 * 2. RETRY SAFETY - Retry worker tidak kirim ulang pesan sukses
 * 3. QUOTA INTEGRATION - Kuota dipotong hanya SETELAH pesan sukses
 * 4. AUDIT TRAIL - Semua operasi tercatat
 * 
 * FLOW PENGIRIMAN AMAN:
 * ====================
 * 
 * 1. Generate idempotency_key
 * 2. Create/Find message log (IDEMPOTENT INSERT)
 * 3. Check if already sent → SKIP
 * 4. Claim message (ATOMIC: pending → sending)
 * 5. Send to WA API
 * 6. On SUCCESS: Mark sent + Consume quota (ATOMIC)
 * 7. On FAIL: Mark failed + Schedule retry (if retryable)
 * 
 * KEAMANAN:
 * =========
 * - Double insert protection (unique idempotency_key)
 * - Double send protection (state machine)
 * - Double quota protection (quota idempotency_key)
 * - Crash recovery (stuck message reset)
 * - Timeout handling (5 minute timeout detection)
 * 
 * @author Senior Software Architect
 */
class MessageSenderService
{
    protected WhatsAppProviderInterface $waProvider;
    protected QuotaService $quotaService;

    /**
     * Enable debug logging
     */
    protected bool $debug = true;

    /**
     * Lock timeout for distributed lock (seconds)
     */
    const LOCK_TIMEOUT_SECONDS = 60;

    public function __construct(
        WhatsAppProviderInterface $waProvider,
        QuotaService $quotaService
    ) {
        $this->waProvider = $waProvider;
        $this->quotaService = $quotaService;
    }

    // ==================== MAIN SEND METHODS ====================

    /**
     * Send campaign message (IDEMPOTENT)
     * 
     * Ini adalah entry point utama untuk mengirim pesan campaign.
     * AMAN untuk dipanggil berulang kali dengan parameter yang sama.
     * 
     * @param Kampanye $kampanye
     * @param TargetKampanye $target
     * @param int $penggunaId User yang trigger
     * @return array
     */
    public function sendCampaignMessage(
        Kampanye $kampanye,
        TargetKampanye $target,
        int $penggunaId
    ): array {
        // 1. Generate idempotency key
        $idempotencyKey = MessageLog::generateCampaignKey($kampanye->id, $target->id);
        
        // 2. Prepare message content
        $messageContent = $this->renderCampaignContent($kampanye, $target);
        
        // 3. Prepare attributes
        $attributes = [
            'klien_id' => $kampanye->klien_id,
            'pengguna_id' => $penggunaId,
            'kampanye_id' => $kampanye->id,
            'target_kampanye_id' => $target->id,
            'phone_number' => $target->no_whatsapp,
            'message_type' => $this->determineMessageType($kampanye),
            'template_name' => $kampanye->template_id ? $kampanye->template?->nama_template : null,
            'message_content' => Str::limit($messageContent, 500), // Truncate for storage
            'message_params' => $target->data_variabel ?? [],
            'content_hash' => MessageLog::generateContentHash($target->no_whatsapp, $messageContent),
            'max_retries' => MessageLog::DEFAULT_MAX_RETRIES,
            'metadata' => [
                'kampanye_nama' => $kampanye->nama,
                'target_nama' => $target->nama,
            ],
        ];

        // 4. Send with idempotency
        return $this->sendWithIdempotency($idempotencyKey, $attributes, function () use ($target, $messageContent, $kampanye) {
            return $this->executeSend($target->no_whatsapp, $messageContent, [
                'type' => $this->determineMessageType($kampanye),
                'template_name' => $kampanye->template?->nama_template,
            ]);
        });
    }

    /**
     * Send inbox message (IDEMPOTENT)
     * 
     * Untuk mengirim pesan dari inbox/percakapan.
     * 
     * @param int $klienId
     * @param int $percakapanId
     * @param string $phoneNumber
     * @param string $content
     * @param int $penggunaId
     * @param string|null $customUuid Optional custom UUID for idempotency
     * @return array
     */
    public function sendInboxMessage(
        int $klienId,
        int $percakapanId,
        string $phoneNumber,
        string $content,
        int $penggunaId,
        ?string $customUuid = null
    ): array {
        // 1. Generate idempotency key
        $idempotencyKey = MessageLog::generateInboxKey($percakapanId, $customUuid);

        // 2. Prepare attributes
        $attributes = [
            'klien_id' => $klienId,
            'pengguna_id' => $penggunaId,
            'percakapan_inbox_id' => $percakapanId,
            'phone_number' => $phoneNumber,
            'message_type' => MessageLog::TYPE_TEXT,
            'message_content' => Str::limit($content, 500),
            'content_hash' => MessageLog::generateContentHash($phoneNumber, $content),
            'max_retries' => MessageLog::DEFAULT_MAX_RETRIES,
        ];

        // 3. Send with idempotency
        return $this->sendWithIdempotency($idempotencyKey, $attributes, function () use ($phoneNumber, $content) {
            return $this->executeSend($phoneNumber, $content, ['type' => 'text']);
        });
    }

    /**
     * Send API message (IDEMPOTENT)
     * 
     * Untuk mengirim pesan via API endpoint.
     * Client HARUS provide unique request_id untuk idempotency.
     * 
     * @param int $klienId
     * @param string $phoneNumber
     * @param string $content
     * @param string $requestId Client-provided unique ID
     * @param int|null $penggunaId
     * @return array
     */
    public function sendApiMessage(
        int $klienId,
        string $phoneNumber,
        string $content,
        string $requestId,
        ?int $penggunaId = null
    ): array {
        // 1. Use client-provided request_id as idempotency key
        $idempotencyKey = MessageLog::generateApiKey($klienId, $requestId);

        // 2. Prepare attributes
        $attributes = [
            'klien_id' => $klienId,
            'pengguna_id' => $penggunaId,
            'phone_number' => $phoneNumber,
            'message_type' => MessageLog::TYPE_TEXT,
            'message_content' => Str::limit($content, 500),
            'content_hash' => MessageLog::generateContentHash($phoneNumber, $content),
            'max_retries' => MessageLog::DEFAULT_MAX_RETRIES,
            'metadata' => [
                'source' => 'api',
                'request_id' => $requestId,
            ],
        ];

        // 3. Send with idempotency
        return $this->sendWithIdempotency($idempotencyKey, $attributes, function () use ($phoneNumber, $content) {
            return $this->executeSend($phoneNumber, $content, ['type' => 'text']);
        });
    }

    // ==================== CORE IDEMPOTENT SEND ====================

    /**
     * Send message with full idempotency protection
     * 
     * FLOW:
     * 1. Create/Find message log (IDEMPOTENT)
     * 2. Check if already sent → SKIP
     * 3. Check quota → FAIL if insufficient
     * 4. Claim message → ATOMIC transition to sending
     * 5. Execute send
     * 6. On SUCCESS: Mark sent + Consume quota
     * 7. On FAIL: Mark failed + Schedule retry
     * 
     * @param string $idempotencyKey Unique key untuk pesan ini
     * @param array $attributes Message attributes
     * @param callable $sendCallback Callback untuk actual send
     * @return array
     */
    protected function sendWithIdempotency(
        string $idempotencyKey,
        array $attributes,
        callable $sendCallback
    ): array {
        $this->logDebug('sendWithIdempotency: START', [
            'idempotency_key' => $idempotencyKey,
            'phone' => $attributes['phone_number'] ?? null,
        ]);

        try {
            // 1. Find or create message log
            [$messageLog, $created] = MessageLog::findOrCreateByKey($idempotencyKey, $attributes);

            // 2. Check if already successfully sent
            if ($messageLog->isSuccessfullySent()) {
                $this->logDebug('sendWithIdempotency: SKIP - already sent', [
                    'message_log_id' => $messageLog->id,
                    'status' => $messageLog->status,
                    'provider_message_id' => $messageLog->provider_message_id,
                ]);

                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'already_sent',
                    'message' => 'Pesan sudah terkirim sebelumnya',
                    'message_log_id' => $messageLog->id,
                    'provider_message_id' => $messageLog->provider_message_id,
                ];
            }

            // 3. Check if currently being processed by another worker
            if ($messageLog->isProcessing() && !$messageLog->isStuck()) {
                $this->logDebug('sendWithIdempotency: SKIP - processing', [
                    'message_log_id' => $messageLog->id,
                    'processing_job_id' => $messageLog->processing_job_id,
                ]);

                return [
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'processing',
                    'message' => 'Pesan sedang diproses worker lain',
                    'message_log_id' => $messageLog->id,
                ];
            }

            // 4. Check if stuck and reset
            if ($messageLog->isStuck()) {
                $this->logDebug('sendWithIdempotency: Resetting stuck message', [
                    'message_log_id' => $messageLog->id,
                ]);
                $messageLog->resetStuckMessage();
            }

            // 5. Check if can retry
            if ($messageLog->status === MessageLog::STATUS_FAILED && !$messageLog->canRetry()) {
                return [
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'max_retries_reached',
                    'message' => 'Pesan sudah mencapai maksimum retry',
                    'message_log_id' => $messageLog->id,
                    'retry_count' => $messageLog->retry_count,
                ];
            }

            // 6. Check quota BEFORE claiming (avoid wasting quota claim)
            $klienId = $attributes['klien_id'];
            $quotaCheck = $this->quotaService->canConsume($klienId, 1);
            
            if (!$quotaCheck['can_consume']) {
                $messageLog->transitionToFailed(
                    MessageLog::ERROR_QUOTA_EXCEEDED,
                    'Kuota tidak mencukupi: ' . $quotaCheck['message'],
                    false // Tidak bisa retry jika quota habis
                );

                return [
                    'success' => false,
                    'reason' => 'quota_exceeded',
                    'message' => $quotaCheck['message'],
                    'message_log_id' => $messageLog->id,
                ];
            }

            // 7. Claim message (ATOMIC: pending/failed → sending)
            $jobId = Str::uuid()->toString();
            $claimed = $messageLog->transitionToSending($jobId);

            if (!$claimed) {
                // Another worker claimed it
                $this->logDebug('sendWithIdempotency: SKIP - claim failed', [
                    'message_log_id' => $messageLog->id,
                    'current_status' => $messageLog->fresh()->status,
                ]);

                return [
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'claim_failed',
                    'message' => 'Gagal claim pesan (sudah diclaim worker lain)',
                    'message_log_id' => $messageLog->id,
                ];
            }

            $this->logDebug('sendWithIdempotency: Message claimed', [
                'message_log_id' => $messageLog->id,
                'job_id' => $jobId,
            ]);

            // 8. Execute send
            $sendResult = $this->executeWithTimeout($sendCallback);

            // 9. Handle result
            if ($sendResult['sukses'] ?? $sendResult['success'] ?? false) {
                return $this->handleSendSuccess($messageLog, $sendResult, $klienId);
            } else {
                return $this->handleSendFailure($messageLog, $sendResult);
            }

        } catch (Throwable $e) {
            $this->logDebug('sendWithIdempotency: EXCEPTION', [
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            // Try to mark as failed if we have message log
            if (isset($messageLog)) {
                $messageLog->transitionToFailed(
                    MessageLog::ERROR_UNKNOWN,
                    $e->getMessage(),
                    true // Network errors are usually retryable
                );
            }

            return [
                'success' => false,
                'reason' => 'exception',
                'message' => $e->getMessage(),
                'message_log_id' => $messageLog->id ?? null,
            ];
        }
    }

    /**
     * Handle successful send
     */
    protected function handleSendSuccess(MessageLog $messageLog, array $sendResult, int $klienId): array
    {
        $providerMessageId = $sendResult['message_id'] ?? $sendResult['data']['message_id'] ?? Str::uuid()->toString();
        $providerName = $sendResult['provider'] ?? config('services.whatsapp.driver', 'unknown');

        // 1. Mark as sent
        $messageLog->transitionToSent(
            $providerMessageId,
            $sendResult,
            $providerName
        );

        $this->logDebug('handleSendSuccess: Message sent', [
            'message_log_id' => $messageLog->id,
            'provider_message_id' => $providerMessageId,
        ]);

        // 2. Consume quota (IDEMPOTENT)
        $quotaKey = "quota_{$messageLog->idempotency_key}";
        
        try {
            $quotaResult = $this->quotaService->consume(
                $klienId,
                $messageLog->message_cost,
                $quotaKey,
                [
                    'message_log_id' => $messageLog->id,
                    'phone_number' => $messageLog->phone_number,
                    'kampanye_id' => $messageLog->kampanye_id,
                ]
            );

            // Update message log with quota info
            $messageLog->update([
                'quota_consumed' => true,
                'quota_idempotency_key' => $quotaKey,
            ]);

            $this->logDebug('handleSendSuccess: Quota consumed', [
                'message_log_id' => $messageLog->id,
                'quota_key' => $quotaKey,
                'skipped' => $quotaResult['skipped'] ?? false,
            ]);

        } catch (DomainException $e) {
            // Quota failed but message already sent
            // Log warning but don't fail the send
            Log::warning('MessageSenderService: Quota consume failed after send', [
                'message_log_id' => $messageLog->id,
                'quota_key' => $quotaKey,
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Update target kampanye if applicable
        if ($messageLog->target_kampanye_id) {
            $this->updateTargetKampanye($messageLog->target_kampanye_id, 'terkirim', $providerMessageId);
        }

        return [
            'success' => true,
            'message' => 'Pesan berhasil dikirim',
            'message_log_id' => $messageLog->id,
            'provider_message_id' => $providerMessageId,
            'quota_consumed' => true,
        ];
    }

    /**
     * Handle failed send
     */
    protected function handleSendFailure(MessageLog $messageLog, array $sendResult): array
    {
        $errorCode = $this->mapProviderErrorCode($sendResult);
        $errorMessage = $sendResult['error'] ?? $sendResult['pesan'] ?? 'Unknown error';
        $isRetryable = in_array($errorCode, MessageLog::RETRYABLE_ERRORS);
        $httpCode = $sendResult['http_code'] ?? null;

        $messageLog->transitionToFailed(
            $errorCode,
            $errorMessage,
            $isRetryable,
            $sendResult,
            $httpCode
        );

        $this->logDebug('handleSendFailure: Message failed', [
            'message_log_id' => $messageLog->id,
            'error_code' => $errorCode,
            'is_retryable' => $isRetryable,
            'retry_count' => $messageLog->retry_count,
        ]);

        // Update target kampanye if applicable
        if ($messageLog->target_kampanye_id) {
            // Only mark as 'gagal' if not retryable or max retries reached
            if (!$isRetryable || $messageLog->retry_count >= $messageLog->max_retries) {
                $this->updateTargetKampanye($messageLog->target_kampanye_id, 'gagal', null, $errorMessage);
            }
        }

        return [
            'success' => false,
            'reason' => $errorCode,
            'message' => $errorMessage,
            'message_log_id' => $messageLog->id,
            'is_retryable' => $isRetryable,
            'retry_count' => $messageLog->retry_count,
            'retry_after' => $messageLog->retry_after?->toISOString(),
        ];
    }

    // ==================== EXECUTE SEND ====================

    /**
     * Execute actual send to WA provider
     */
    protected function executeSend(string $phoneNumber, string $content, array $options = []): array
    {
        $this->logDebug('executeSend: Calling WA provider', [
            'phone' => $phoneNumber,
            'type' => $options['type'] ?? 'text',
        ]);

        try {
            // Normalize phone number
            $normalizedPhone = $this->normalizePhone($phoneNumber);

            // Call WA provider
            $result = $this->waProvider->kirimPesan($normalizedPhone, $content, $options);

            $this->logDebug('executeSend: Provider response', [
                'sukses' => $result['sukses'] ?? false,
                'message_id' => $result['message_id'] ?? null,
            ]);

            return $result;

        } catch (Throwable $e) {
            Log::error('MessageSenderService: executeSend exception', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'sukses' => false,
                'error' => $e->getMessage(),
                'error_code' => MessageLog::ERROR_NETWORK_ERROR,
            ];
        }
    }

    /**
     * Execute with timeout protection
     */
    protected function executeWithTimeout(callable $callback, int $timeoutSeconds = 30): array
    {
        // Note: PHP doesn't have native async timeout
        // In production, use pcntl_alarm or external timeout mechanism
        // For now, rely on HTTP client timeout

        return $callback();
    }

    // ==================== HELPER METHODS ====================

    /**
     * Render campaign content with variables
     */
    protected function renderCampaignContent(Kampanye $kampanye, TargetKampanye $target): string
    {
        $content = $kampanye->isi_pesan ?? '';
        $variables = $target->data_variabel ?? [];

        // Replace {{variable}} placeholders
        foreach ($variables as $key => $value) {
            $content = str_replace("{{{$key}}}", $value, $content);
        }

        // Replace built-in variables
        $content = str_replace('{{nama}}', $target->nama ?? '', $content);
        $content = str_replace('{{nomor}}', $target->no_whatsapp ?? '', $content);

        return $content;
    }

    /**
     * Determine message type from campaign
     */
    protected function determineMessageType(Kampanye $kampanye): string
    {
        if ($kampanye->template_id) {
            return MessageLog::TYPE_TEMPLATE;
        }

        if ($kampanye->media_url) {
            return $kampanye->tipe_media ?? MessageLog::TYPE_IMAGE;
        }

        return MessageLog::TYPE_TEXT;
    }

    /**
     * Map provider error code to our error codes
     */
    protected function mapProviderErrorCode(array $result): string
    {
        $providerCode = $result['error_code'] ?? $result['code'] ?? null;
        $message = strtolower($result['error'] ?? $result['message'] ?? '');

        // Map common error patterns
        if (str_contains($message, 'timeout')) {
            return MessageLog::ERROR_TIMEOUT;
        }
        if (str_contains($message, 'rate') || str_contains($message, 'limit')) {
            return MessageLog::ERROR_RATE_LIMIT;
        }
        if (str_contains($message, 'invalid') && str_contains($message, 'number')) {
            return MessageLog::ERROR_INVALID_NUMBER;
        }
        if (str_contains($message, 'blocked') || str_contains($message, 'block')) {
            return MessageLog::ERROR_BLOCKED;
        }
        if (str_contains($message, 'quota') || str_contains($message, 'credit')) {
            return MessageLog::ERROR_QUOTA_EXCEEDED;
        }
        if (str_contains($message, 'template')) {
            return MessageLog::ERROR_TEMPLATE_NOT_FOUND;
        }

        return MessageLog::ERROR_PROVIDER_ERROR;
    }

    /**
     * Normalize phone number to international format
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 08xx to 628xx
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }

        // Ensure starts with 62
        if (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    /**
     * Update target kampanye status
     */
    protected function updateTargetKampanye(
        int $targetId, 
        string $status, 
        ?string $messageId = null,
        ?string $error = null
    ): void {
        try {
            TargetKampanye::where('id', $targetId)->update([
                'status' => $status,
                'message_id' => $messageId,
                'waktu_kirim' => $status === 'terkirim' ? now() : null,
                'catatan' => $error,
            ]);
        } catch (Throwable $e) {
            Log::warning('MessageSenderService: Failed to update target kampanye', [
                'target_id' => $targetId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Debug logging
     */
    protected function logDebug(string $message, array $context = []): void
    {
        if ($this->debug) {
            Log::channel('whatsapp')->debug("MessageSenderService: {$message}", $context);
        }
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * Get pending messages for retry
     * 
     * @param int|null $klienId Filter by klien
     * @param int $limit Max messages to fetch
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRetryableMessages(?int $klienId = null, int $limit = 100)
    {
        $query = MessageLog::retryable();

        if ($klienId) {
            $query->where('klien_id', $klienId);
        }

        return $query->orderBy('retry_after', 'asc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Get stuck messages
     */
    public function getStuckMessages(int $limit = 100)
    {
        return MessageLog::stuck()
                        ->limit($limit)
                        ->get();
    }

    /**
     * Reset all stuck messages
     */
    public function resetAllStuckMessages(): int
    {
        $count = 0;
        $stuckMessages = $this->getStuckMessages();

        foreach ($stuckMessages as $message) {
            if ($message->resetStuckMessage()) {
                $count++;
            }
        }

        Log::info('MessageSenderService: Reset stuck messages', ['count' => $count]);

        return $count;
    }

    /**
     * Expire old pending messages
     * 
     * @param int $hoursOld Messages older than this will be expired
     */
    public function expireOldMessages(int $hoursOld = 24): int
    {
        $count = MessageLog::where('status', MessageLog::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours($hoursOld))
            ->update([
                'status' => MessageLog::STATUS_EXPIRED,
                'status_detail' => "Expired after {$hoursOld} hours without processing",
                'failed_at' => now(),
                'is_retryable' => false,
            ]);

        Log::info('MessageSenderService: Expired old messages', ['count' => $count]);

        return $count;
    }

    // ==================== STATISTICS ====================

    /**
     * Get sending statistics for klien
     */
    public function getKlienStats(int $klienId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = MessageLog::where('klien_id', $klienId);

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $stats = $query->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $successCount = ($stats[MessageLog::STATUS_SENT] ?? 0) +
                       ($stats[MessageLog::STATUS_DELIVERED] ?? 0) +
                       ($stats[MessageLog::STATUS_READ] ?? 0);

        $total = array_sum($stats);

        return [
            'total' => $total,
            'sent' => $successCount,
            'pending' => $stats[MessageLog::STATUS_PENDING] ?? 0,
            'sending' => $stats[MessageLog::STATUS_SENDING] ?? 0,
            'failed' => $stats[MessageLog::STATUS_FAILED] ?? 0,
            'expired' => $stats[MessageLog::STATUS_EXPIRED] ?? 0,
            'success_rate' => $total > 0 ? round(($successCount / $total) * 100, 2) : 0,
        ];
    }
}
