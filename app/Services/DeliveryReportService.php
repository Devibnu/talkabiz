<?php

namespace App\Services;

use App\Models\MessageLog;
use App\Models\MessageEvent;
use App\Models\TargetKampanye;
use App\Models\PesanInbox;
use App\Models\Klien;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * DeliveryReportService - Idempotent Webhook Handler
 * 
 * Service ini menangani SEMUA webhook delivery report dari WABA/BSP.
 * 
 * PRINSIP UTAMA:
 * ==============
 * 1. IDEMPOTENT - Proses event berkali-kali = hasil sama
 * 2. APPEND-ONLY - Semua event disimpan, tidak ada yang di-delete
 * 3. ORDER-INDEPENDENT - Handle event yang datang tidak berurutan
 * 4. FINANCIAL-GRADE - Aman untuk billing & audit
 * 
 * FLOW WEBHOOK:
 * =============
 *     ┌─────────────────┐
 *     │ Webhook Masuk   │
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Parse & Validate│
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Check Idempotency│ ───► Duplicate? Return OK
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Find MessageLog │ ───► Not found? Store event only
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Validate State  │ ───► Out of order? Mark but process
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Update Status   │ (with locking)
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Store Event Log │ (append-only)
 *     └────────┬────────┘
 *              │
 *              ▼
 *     ┌─────────────────┐
 *     │ Trigger Actions │ (quota, notification, etc)
 *     └─────────────────┘
 * 
 * KUOTA RULES:
 * ============
 * - Kuota dipotong saat SEND (bukan di webhook)
 * - Webhook hanya UPDATE status
 * - Failed/Rejected TIDAK mengembalikan kuota
 * - Ini mencegah gaming sistem
 * 
 * @author Senior Software Architect
 */
class DeliveryReportService
{
    /**
     * Cache TTL untuk idempotency check (1 jam)
     */
    const IDEMPOTENCY_CACHE_TTL = 3600;

    /**
     * Maximum age of events to process (7 days)
     */
    const MAX_EVENT_AGE_DAYS = 7;

    // ==================== MAIN HANDLER ====================

    /**
     * Handle delivery report webhook
     * 
     * @param array $payload Parsed webhook payload
     * @param string $providerName Provider name (gupshup, meta, twilio)
     * @param string|null $signature Webhook signature for validation
     * @return array Result with status and details
     */
    public function handleDeliveryReport(
        array $payload,
        string $providerName = 'waba',
        ?string $signature = null
    ): array {
        $receivedAt = now();

        try {
            // 1. Parse webhook payload (provider-agnostic)
            $parsed = $this->parseWebhookPayload($payload, $providerName);
            
            if (!$parsed['valid']) {
                return [
                    'success' => false,
                    'reason' => 'invalid_payload',
                    'message' => $parsed['error'] ?? 'Invalid payload format',
                ];
            }

            // 2. Check event age (reject very old events)
            if ($this->isEventTooOld($parsed['event_timestamp'])) {
                return $this->createIgnoredResult('event_too_old', 'Event older than 7 days', $parsed);
            }

            // 3. Check idempotency (using event_id or composite key)
            $idempotencyKey = $this->generateIdempotencyKey($parsed);
            
            if ($this->isDuplicateEvent($idempotencyKey)) {
                return $this->createIgnoredResult('duplicate', 'Event already processed', $parsed);
            }

            // 4. Find MessageLog by provider_message_id
            $messageLog = $this->findMessageLog($parsed['provider_message_id']);
            
            // 5. Process event
            return DB::transaction(function () use ($parsed, $messageLog, $idempotencyKey, $providerName, $signature, $receivedAt) {
                // Prepare event data
                $eventData = [
                    'message_log_id' => $messageLog?->id,
                    'klien_id' => $messageLog?->klien_id ?? $this->findKlienId($parsed),
                    'provider_message_id' => $parsed['provider_message_id'],
                    'provider_name' => $providerName,
                    'event_type' => $parsed['event_type'],
                    'event_id' => $parsed['event_id'],
                    'event_timestamp' => $parsed['event_timestamp'],
                    'phone_number' => $parsed['phone_number'] ?? null,
                    'error_code' => $parsed['error_code'] ?? null,
                    'error_message' => $parsed['error_message'] ?? null,
                    'raw_payload' => $parsed['raw_payload'] ?? null,
                    'webhook_signature' => $signature,
                    'received_at' => $receivedAt,
                ];

                // 6. Process based on whether we found MessageLog
                if ($messageLog) {
                    $result = $this->processEventWithMessageLog($messageLog, $parsed, $eventData);
                } else {
                    // Store event anyway for future reconciliation
                    $result = $this->processOrphanEvent($parsed, $eventData);
                }

                // 7. Store event (append-only)
                $event = MessageEvent::create(array_merge($eventData, $result['event_updates']));

                // 8. Mark idempotency key as processed
                $this->markEventProcessed($idempotencyKey);

                return array_merge($result, [
                    'success' => true,
                    'event_id' => $event->id,
                ]);
            });

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('DeliveryReportService: Error processing webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'reason' => 'processing_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    // ==================== EVENT PROCESSING ====================

    /**
     * Process event when MessageLog is found
     */
    protected function processEventWithMessageLog(
        MessageLog $messageLog,
        array $parsed,
        array &$eventData
    ): array {
        $eventType = $parsed['event_type'];
        $statusBefore = $messageLog->status;
        $statusChanged = false;
        $processNote = null;

        // Lock row for update
        $messageLog = MessageLog::where('id', $messageLog->id)->lockForUpdate()->first();

        // Validate state transition
        $canTransition = $this->canTransitionState($messageLog->status, $eventType);
        
        if (!$canTransition['allowed']) {
            $processNote = $canTransition['reason'];
            $eventData['is_out_of_order'] = true;
            
            // Still store event but don't change status
            return [
                'action' => 'ignored',
                'reason' => $canTransition['reason'],
                'status_changed' => false,
                'event_updates' => [
                    'status_before' => $statusBefore,
                    'status_after' => $statusBefore,
                    'status_changed' => false,
                    'is_out_of_order' => true,
                    'process_result' => MessageEvent::RESULT_IGNORED,
                    'process_note' => $processNote,
                    'processed_at' => now(),
                ],
            ];
        }

        // Apply state transition
        $statusAfter = $this->applyStateTransition($messageLog, $eventType, $parsed);
        $statusChanged = ($statusAfter !== $statusBefore);

        // Calculate timing metrics
        $timingMetrics = $this->calculateTimingMetrics($messageLog, $eventType);

        // Update related records (TargetKampanye, PesanInbox)
        if ($statusChanged) {
            $this->updateRelatedRecords($messageLog, $eventType, $parsed);
        }

        return [
            'action' => 'processed',
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'status_changed' => $statusChanged,
            'event_updates' => [
                'status_before' => $statusBefore,
                'status_after' => $statusAfter,
                'status_changed' => $statusChanged,
                'is_out_of_order' => false,
                'process_result' => MessageEvent::RESULT_PROCESSED,
                'process_note' => $statusChanged ? 'Status updated' : 'No change needed',
                'delivery_time_seconds' => $timingMetrics['delivery_time'] ?? null,
                'read_time_seconds' => $timingMetrics['read_time'] ?? null,
                'processed_at' => now(),
            ],
        ];
    }

    /**
     * Process orphan event (MessageLog not found)
     * Store for later reconciliation
     */
    protected function processOrphanEvent(array $parsed, array &$eventData): array
    {
        Log::channel('whatsapp')->warning('DeliveryReportService: Orphan event', [
            'provider_message_id' => $parsed['provider_message_id'],
            'event_type' => $parsed['event_type'],
        ]);

        return [
            'action' => 'stored',
            'reason' => 'message_log_not_found',
            'status_changed' => false,
            'event_updates' => [
                'status_before' => null,
                'status_after' => null,
                'status_changed' => false,
                'process_result' => MessageEvent::RESULT_PROCESSED,
                'process_note' => 'Orphan event - stored for reconciliation',
                'processed_at' => now(),
            ],
        ];
    }

    // ==================== STATE MACHINE ====================

    /**
     * Check if state transition is allowed
     * 
     * RULES:
     * 1. Status tidak boleh mundur (delivered tidak bisa jadi sent)
     * 2. Status read/rejected/expired adalah final
     * 3. sent bisa ke delivered/read/failed
     * 4. delivered bisa ke read
     * 5. failed bisa ke sent (retry berhasil)
     */
    protected function canTransitionState(string $currentStatus, string $eventType): array
    {
        // Map event to MessageLog status
        $targetStatus = MessageEvent::mapToMessageLogStatus($eventType);

        // Define valid transitions
        $validTransitions = [
            MessageLog::STATUS_PENDING => [
                MessageLog::STATUS_SENT,
                MessageLog::STATUS_FAILED,
            ],
            MessageLog::STATUS_SENDING => [
                MessageLog::STATUS_SENT,
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
                MessageLog::STATUS_FAILED,
            ],
            MessageLog::STATUS_SENT => [
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
                // NOT failed - sent is final success
            ],
            MessageLog::STATUS_DELIVERED => [
                MessageLog::STATUS_READ,
                // NOT failed - delivered is after sent
            ],
            MessageLog::STATUS_READ => [
                // Final - no transitions allowed
            ],
            MessageLog::STATUS_FAILED => [
                // Retry bisa sukses
                MessageLog::STATUS_SENT,
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
            ],
            MessageLog::STATUS_EXPIRED => [
                // Final - no transitions allowed
            ],
        ];

        $allowed = $validTransitions[$currentStatus] ?? [];

        if (in_array($targetStatus, $allowed)) {
            return ['allowed' => true];
        }

        // Check if it's same status (idempotent)
        if ($currentStatus === $targetStatus) {
            return [
                'allowed' => false,
                'reason' => 'same_status',
            ];
        }

        // Check if it's backward transition
        $currentLevel = $this->getStatusLevel($currentStatus);
        $targetLevel = $this->getStatusLevel($targetStatus);

        if ($targetLevel < $currentLevel) {
            return [
                'allowed' => false,
                'reason' => 'backward_transition',
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'invalid_transition',
        ];
    }

    /**
     * Get status level for comparison
     */
    protected function getStatusLevel(string $status): int
    {
        return match ($status) {
            MessageLog::STATUS_PENDING => 0,
            MessageLog::STATUS_SENDING => 1,
            MessageLog::STATUS_FAILED => 1, // Same level as sending, can go to sent
            MessageLog::STATUS_SENT => 2,
            MessageLog::STATUS_DELIVERED => 3,
            MessageLog::STATUS_READ => 4,
            MessageLog::STATUS_EXPIRED => 5,
            default => 0,
        };
    }

    /**
     * Apply state transition to MessageLog
     */
    protected function applyStateTransition(
        MessageLog $messageLog,
        string $eventType,
        array $parsed
    ): string {
        switch ($eventType) {
            case MessageEvent::EVENT_SENT:
                $messageLog->update([
                    'status' => MessageLog::STATUS_SENT,
                    'sent_at' => $parsed['event_timestamp'] ?? now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);
                break;

            case MessageEvent::EVENT_DELIVERED:
                $messageLog->update([
                    'status' => MessageLog::STATUS_DELIVERED,
                    'delivered_at' => $parsed['event_timestamp'] ?? now(),
                ]);
                break;

            case MessageEvent::EVENT_READ:
                $messageLog->update([
                    'status' => MessageLog::STATUS_READ,
                    'read_at' => $parsed['event_timestamp'] ?? now(),
                ]);
                break;

            case MessageEvent::EVENT_FAILED:
                // Only update if not already successful
                if (!$messageLog->isSuccessfullySent()) {
                    $isRetryable = MessageEvent::isRetryableError($parsed['error_code'] ?? '');
                    
                    $messageLog->transitionToFailed(
                        $parsed['error_code'] ?? 'UNKNOWN',
                        $parsed['error_message'] ?? 'Unknown error',
                        $isRetryable
                    );
                }
                break;

            case MessageEvent::EVENT_REJECTED:
                // Rejected is permanent
                if (!$messageLog->isSuccessfullySent()) {
                    $messageLog->transitionToFailed(
                        $parsed['error_code'] ?? 'REJECTED',
                        $parsed['error_message'] ?? 'Message rejected',
                        false // Not retryable
                    );
                }
                break;

            case MessageEvent::EVENT_EXPIRED:
                $messageLog->transitionToExpired(
                    $parsed['error_message'] ?? 'Message expired'
                );
                break;
        }

        return $messageLog->status;
    }

    /**
     * Update related records (TargetKampanye, PesanInbox)
     */
    protected function updateRelatedRecords(
        MessageLog $messageLog,
        string $eventType,
        array $parsed
    ): void {
        // Update TargetKampanye if campaign message
        if ($messageLog->target_kampanye_id) {
            $statusMap = [
                MessageEvent::EVENT_SENT => 'terkirim',
                MessageEvent::EVENT_DELIVERED => 'delivered',
                MessageEvent::EVENT_READ => 'dibaca',
                MessageEvent::EVENT_FAILED => 'gagal',
                MessageEvent::EVENT_REJECTED => 'gagal',
                MessageEvent::EVENT_EXPIRED => 'expired',
            ];

            TargetKampanye::where('id', $messageLog->target_kampanye_id)->update([
                'status' => $statusMap[$eventType] ?? 'terkirim',
                'waktu_terkirim' => $messageLog->sent_at,
            ]);
        }

        // Update PesanInbox if inbox message
        if ($messageLog->percakapan_inbox_id) {
            $statusMap = [
                MessageEvent::EVENT_SENT => 'terkirim',
                MessageEvent::EVENT_DELIVERED => 'delivered',
                MessageEvent::EVENT_READ => 'dibaca',
                MessageEvent::EVENT_FAILED => 'gagal',
                MessageEvent::EVENT_REJECTED => 'gagal',
                MessageEvent::EVENT_EXPIRED => 'expired',
            ];

            PesanInbox::where('message_log_id', $messageLog->id)->update([
                'status' => $statusMap[$eventType] ?? 'terkirim',
                'waktu_dibaca' => $eventType === MessageEvent::EVENT_READ ? now() : null,
            ]);
        }
    }

    // ==================== PAYLOAD PARSING ====================

    /**
     * Parse webhook payload (provider-agnostic)
     */
    protected function parseWebhookPayload(array $payload, string $provider): array
    {
        return match ($provider) {
            'gupshup' => $this->parseGupshupPayload($payload),
            'meta', 'cloud_api' => $this->parseMetaCloudApiPayload($payload),
            'twilio' => $this->parseTwilioPayload($payload),
            default => $this->parseGenericPayload($payload),
        };
    }

    /**
     * Parse Gupshup payload
     */
    protected function parseGupshupPayload(array $payload): array
    {
        $eventPayload = $payload['payload'] ?? $payload;
        
        $messageId = $eventPayload['gsId'] ?? $eventPayload['id'] ?? null;
        $eventType = $this->normalizeEventType($eventPayload['type'] ?? null);
        $timestamp = $eventPayload['timestamp'] ?? time();

        // Error info
        $errorPayload = $eventPayload['payload'] ?? [];
        $errorCode = $errorPayload['code'] ?? null;
        $errorMessage = $errorPayload['reason'] ?? null;

        if (!$messageId || !$eventType) {
            return ['valid' => false, 'error' => 'Missing message_id or event_type'];
        }

        return [
            'valid' => true,
            'provider_message_id' => $messageId,
            'event_type' => $eventType,
            'event_id' => $payload['eventId'] ?? $messageId . '_' . $eventType,
            'event_timestamp' => Carbon::createFromTimestamp($timestamp),
            'phone_number' => $eventPayload['destination'] ?? null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'raw_payload' => $payload,
        ];
    }

    /**
     * Parse Meta Cloud API payload
     */
    protected function parseMetaCloudApiPayload(array $payload): array
    {
        // Meta sends nested structure
        $entry = $payload['entry'][0] ?? [];
        $changes = $entry['changes'][0] ?? [];
        $value = $changes['value'] ?? [];
        $statuses = $value['statuses'][0] ?? [];

        $messageId = $statuses['id'] ?? null;
        $status = $statuses['status'] ?? null;
        $timestamp = $statuses['timestamp'] ?? time();
        $recipientId = $statuses['recipient_id'] ?? null;

        // Error info
        $errors = $statuses['errors'][0] ?? [];
        $errorCode = $errors['code'] ?? null;
        $errorTitle = $errors['title'] ?? null;

        if (!$messageId || !$status) {
            return ['valid' => false, 'error' => 'Missing message_id or status'];
        }

        return [
            'valid' => true,
            'provider_message_id' => $messageId,
            'event_type' => $this->normalizeEventType($status),
            'event_id' => $messageId . '_' . $status . '_' . $timestamp,
            'event_timestamp' => Carbon::createFromTimestamp($timestamp),
            'phone_number' => $recipientId,
            'error_code' => $errorCode ? (string)$errorCode : null,
            'error_message' => $errorTitle,
            'raw_payload' => $payload,
        ];
    }

    /**
     * Parse Twilio payload
     */
    protected function parseTwilioPayload(array $payload): array
    {
        $messageId = $payload['MessageSid'] ?? null;
        $status = $payload['MessageStatus'] ?? null;
        
        if (!$messageId || !$status) {
            return ['valid' => false, 'error' => 'Missing MessageSid or MessageStatus'];
        }

        return [
            'valid' => true,
            'provider_message_id' => $messageId,
            'event_type' => $this->normalizeEventType($status),
            'event_id' => $messageId . '_' . $status,
            'event_timestamp' => now(),
            'phone_number' => $payload['To'] ?? null,
            'error_code' => $payload['ErrorCode'] ?? null,
            'error_message' => $payload['ErrorMessage'] ?? null,
            'raw_payload' => $payload,
        ];
    }

    /**
     * Parse generic payload
     */
    protected function parseGenericPayload(array $payload): array
    {
        $messageId = $payload['message_id'] ?? $payload['id'] ?? null;
        $status = $payload['status'] ?? $payload['event'] ?? null;

        if (!$messageId || !$status) {
            return ['valid' => false, 'error' => 'Missing message_id or status'];
        }

        return [
            'valid' => true,
            'provider_message_id' => $messageId,
            'event_type' => $this->normalizeEventType($status),
            'event_id' => $payload['event_id'] ?? $messageId . '_' . $status,
            'event_timestamp' => isset($payload['timestamp']) 
                ? Carbon::parse($payload['timestamp']) 
                : now(),
            'phone_number' => $payload['phone'] ?? $payload['to'] ?? null,
            'error_code' => $payload['error_code'] ?? null,
            'error_message' => $payload['error_message'] ?? $payload['error'] ?? null,
            'raw_payload' => $payload,
        ];
    }

    /**
     * Normalize event type from various providers
     */
    protected function normalizeEventType(?string $status): ?string
    {
        if (!$status) return null;

        $status = strtolower($status);

        // Normalize various provider statuses
        $mapping = [
            // Success statuses
            'sent' => MessageEvent::EVENT_SENT,
            'enqueued' => MessageEvent::EVENT_SENT,
            'submitted' => MessageEvent::EVENT_SENT,
            'accepted' => MessageEvent::EVENT_SENT,
            
            'delivered' => MessageEvent::EVENT_DELIVERED,
            
            'read' => MessageEvent::EVENT_READ,
            'seen' => MessageEvent::EVENT_READ,
            
            // Failure statuses
            'failed' => MessageEvent::EVENT_FAILED,
            'undelivered' => MessageEvent::EVENT_FAILED,
            'error' => MessageEvent::EVENT_FAILED,
            
            'rejected' => MessageEvent::EVENT_REJECTED,
            'blocked' => MessageEvent::EVENT_REJECTED,
            
            'expired' => MessageEvent::EVENT_EXPIRED,
            'deleted' => MessageEvent::EVENT_EXPIRED,
        ];

        return $mapping[$status] ?? MessageEvent::EVENT_FAILED;
    }

    // ==================== IDEMPOTENCY ====================

    /**
     * Generate idempotency key for event
     */
    protected function generateIdempotencyKey(array $parsed): string
    {
        // Use event_id if available, otherwise composite
        if (!empty($parsed['event_id'])) {
            return 'webhook_event:' . $parsed['event_id'];
        }

        return 'webhook_event:' . $parsed['provider_message_id'] . ':' . $parsed['event_type'];
    }

    /**
     * Check if event is duplicate
     */
    protected function isDuplicateEvent(string $idempotencyKey): bool
    {
        // Check cache first (fast)
        if (Cache::has($idempotencyKey)) {
            return true;
        }

        // Check database (slower but more reliable)
        $exists = MessageEvent::where('event_id', str_replace('webhook_event:', '', $idempotencyKey))
            ->exists();

        if ($exists) {
            // Populate cache
            Cache::put($idempotencyKey, true, self::IDEMPOTENCY_CACHE_TTL);
        }

        return $exists;
    }

    /**
     * Mark event as processed
     */
    protected function markEventProcessed(string $idempotencyKey): void
    {
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_CACHE_TTL);
    }

    // ==================== HELPERS ====================

    /**
     * Find MessageLog by provider_message_id
     */
    protected function findMessageLog(string $providerMessageId): ?MessageLog
    {
        return MessageLog::where('provider_message_id', $providerMessageId)->first();
    }

    /**
     * Find klien_id from phone number or other means
     */
    protected function findKlienId(array $parsed): ?int
    {
        // Try to find from phone number or other data
        // This is for orphan events
        return null;
    }

    /**
     * Check if event is too old
     */
    protected function isEventTooOld(Carbon $eventTimestamp): bool
    {
        return $eventTimestamp->diffInDays(now()) > self::MAX_EVENT_AGE_DAYS;
    }

    /**
     * Calculate timing metrics
     */
    protected function calculateTimingMetrics(MessageLog $messageLog, string $eventType): array
    {
        $metrics = [];

        if ($eventType === MessageEvent::EVENT_DELIVERED && $messageLog->sent_at) {
            $metrics['delivery_time'] = $messageLog->sent_at->diffInSeconds(now());
        }

        if ($eventType === MessageEvent::EVENT_READ && $messageLog->delivered_at) {
            $metrics['read_time'] = $messageLog->delivered_at->diffInSeconds(now());
        }

        return $metrics;
    }

    /**
     * Create ignored result
     */
    protected function createIgnoredResult(string $reason, string $message, array $parsed): array
    {
        Log::channel('whatsapp')->info('DeliveryReportService: Event ignored', [
            'reason' => $reason,
            'provider_message_id' => $parsed['provider_message_id'] ?? null,
            'event_type' => $parsed['event_type'] ?? null,
        ]);

        return [
            'success' => true, // Return true to prevent provider retry
            'action' => 'ignored',
            'reason' => $reason,
            'message' => $message,
        ];
    }

    // ==================== RECONCILIATION ====================

    /**
     * Reconcile orphan events with MessageLogs
     * Run this periodically to link orphan events
     */
    public function reconcileOrphanEvents(): int
    {
        $count = 0;

        $orphanEvents = MessageEvent::whereNull('message_log_id')
            ->where('created_at', '>=', now()->subDays(1))
            ->get();

        foreach ($orphanEvents as $event) {
            $messageLog = $this->findMessageLog($event->provider_message_id);
            
            if ($messageLog) {
                $event->update([
                    'message_log_id' => $messageLog->id,
                    'klien_id' => $messageLog->klien_id,
                    'process_note' => 'Reconciled: ' . now()->toISOString(),
                ]);
                $count++;
            }
        }

        Log::info('DeliveryReportService: Reconciled orphan events', ['count' => $count]);

        return $count;
    }

    // ==================== STATISTICS ====================

    /**
     * Get delivery statistics for klien
     */
    public function getDeliveryStats(int $klienId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfDay();
        $endDate = $endDate ?? now()->endOfDay();

        $stats = MessageEvent::where('klien_id', $klienId)
            ->whereBetween('event_timestamp', [$startDate, $endDate])
            ->where('status_changed', true)
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type')
            ->toArray();

        $total = array_sum($stats);
        $delivered = ($stats[MessageEvent::EVENT_DELIVERED] ?? 0) + ($stats[MessageEvent::EVENT_READ] ?? 0);
        $failed = ($stats[MessageEvent::EVENT_FAILED] ?? 0) + ($stats[MessageEvent::EVENT_REJECTED] ?? 0);

        return [
            'total' => $total,
            'sent' => $stats[MessageEvent::EVENT_SENT] ?? 0,
            'delivered' => $stats[MessageEvent::EVENT_DELIVERED] ?? 0,
            'read' => $stats[MessageEvent::EVENT_READ] ?? 0,
            'failed' => $stats[MessageEvent::EVENT_FAILED] ?? 0,
            'rejected' => $stats[MessageEvent::EVENT_REJECTED] ?? 0,
            'expired' => $stats[MessageEvent::EVENT_EXPIRED] ?? 0,
            'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 2) : 0,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get average delivery times
     */
    public function getAverageDeliveryTimes(int $klienId, ?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->subDays(7);

        $avgDelivery = MessageEvent::where('klien_id', $klienId)
            ->where('event_type', MessageEvent::EVENT_DELIVERED)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('delivery_time_seconds')
            ->avg('delivery_time_seconds');

        $avgRead = MessageEvent::where('klien_id', $klienId)
            ->where('event_type', MessageEvent::EVENT_READ)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('read_time_seconds')
            ->avg('read_time_seconds');

        return [
            'avg_delivery_seconds' => round($avgDelivery ?? 0, 2),
            'avg_read_seconds' => round($avgRead ?? 0, 2),
        ];
    }
}
