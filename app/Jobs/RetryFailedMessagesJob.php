<?php

namespace App\Jobs;

use App\Models\MessageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RetryFailedMessagesJob - Scheduled Job untuk Retry Failed Messages
 * 
 * Job ini dijalankan via scheduler untuk:
 * 1. Find messages yang bisa di-retry (status=failed, is_retryable=true, retry_after <= now)
 * 2. Dispatch SendWhatsappMessageJob untuk setiap message
 * 
 * SCHEDULER:
 * ==========
 * Di app/Console/Kernel.php:
 * $schedule->job(new RetryFailedMessagesJob())->everyMinute();
 * 
 * @author Senior Software Architect
 */
class RetryFailedMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    /**
     * Batch size per run
     */
    protected int $batchSize;

    /**
     * Filter by klien_id (optional)
     */
    protected ?int $klienId;

    public function __construct(int $batchSize = 100, ?int $klienId = null)
    {
        $this->batchSize = $batchSize;
        $this->klienId = $klienId;
    }

    public function handle(): void
    {
        Log::channel('whatsapp')->info('RetryFailedMessagesJob: START', [
            'batch_size' => $this->batchSize,
            'klien_id' => $this->klienId,
        ]);

        // Find retryable messages
        $query = MessageLog::retryable();
        
        if ($this->klienId) {
            $query->where('klien_id', $this->klienId);
        }

        $messages = $query->orderBy('retry_after', 'asc')
                         ->limit($this->batchSize)
                         ->get();

        if ($messages->isEmpty()) {
            Log::channel('whatsapp')->debug('RetryFailedMessagesJob: No retryable messages');
            return;
        }

        Log::channel('whatsapp')->info('RetryFailedMessagesJob: Found messages to retry', [
            'count' => $messages->count(),
        ]);

        $dispatchedCount = 0;

        foreach ($messages as $message) {
            try {
                // Determine message type
                $messageType = 'api';
                $payload = [];

                if ($message->kampanye_id && $message->target_kampanye_id) {
                    $messageType = 'campaign';
                    $payload = [
                        'kampanye_id' => $message->kampanye_id,
                        'target_kampanye_id' => $message->target_kampanye_id,
                    ];
                } elseif ($message->percakapan_inbox_id) {
                    $messageType = 'inbox';
                    $payload = [
                        'percakapan_id' => $message->percakapan_inbox_id,
                        'phone_number' => $message->phone_number,
                        'content' => $message->message_content,
                        'uuid' => explode('_', $message->idempotency_key)[3] ?? null,
                    ];
                } else {
                    $payload = [
                        'phone_number' => $message->phone_number,
                        'content' => $message->message_content,
                        'request_id' => explode('_', $message->idempotency_key)[3] ?? null,
                    ];
                }

                // Dispatch retry job
                SendWhatsappMessageJob::dispatch(
                    $message->idempotency_key,
                    $message->klien_id,
                    $messageType,
                    $payload,
                    $message->pengguna_id
                )->onQueue('whatsapp-retry');

                $dispatchedCount++;

            } catch (\Throwable $e) {
                Log::error('RetryFailedMessagesJob: Error dispatching retry', [
                    'message_log_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('whatsapp')->info('RetryFailedMessagesJob: Dispatched', [
            'count' => $dispatchedCount,
        ]);
    }

    public function tags(): array
    {
        return ['retry-messages', $this->klienId ? "klien:{$this->klienId}" : 'all'];
    }
}
