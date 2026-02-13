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
 * CleanupStuckMessagesJob - Scheduled Job untuk Reset Stuck Messages
 * 
 * Job ini dijalankan via scheduler untuk:
 * 1. Find messages yang stuck (status=sending > 5 menit)
 * 2. Reset ke status pending untuk retry
 * 
 * SCHEDULER:
 * ==========
 * Di app/Console/Kernel.php:
 * $schedule->job(new CleanupStuckMessagesJob())->everyFiveMinutes();
 * 
 * @author Senior Software Architect
 */
class CleanupStuckMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        Log::channel('whatsapp')->info('CleanupStuckMessagesJob: START');

        // Find stuck messages
        $stuckMessages = MessageLog::stuck()->get();

        if ($stuckMessages->isEmpty()) {
            Log::channel('whatsapp')->debug('CleanupStuckMessagesJob: No stuck messages');
            return;
        }

        $resetCount = 0;

        foreach ($stuckMessages as $message) {
            try {
                $message->resetStuckMessage();
                $resetCount++;
                
                Log::channel('whatsapp')->info('CleanupStuckMessagesJob: Reset stuck message', [
                    'message_log_id' => $message->id,
                    'idempotency_key' => $message->idempotency_key,
                    'stuck_duration_minutes' => $message->processing_started_at?->diffInMinutes(now()),
                ]);
            } catch (\Throwable $e) {
                Log::error('CleanupStuckMessagesJob: Error resetting', [
                    'message_log_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('whatsapp')->info('CleanupStuckMessagesJob: Completed', [
            'reset_count' => $resetCount,
        ]);
    }

    public function tags(): array
    {
        return ['cleanup-stuck'];
    }
}
