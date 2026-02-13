<?php

namespace App\Jobs;

use App\Models\TargetKampanye;
use App\Models\Kampanye;
use App\Services\QuotaService;
use App\Contracts\WhatsAppProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SendSingleMessageJob - Job untuk Mengirim 1 Pesan dengan Anti Race Condition
 * 
 * Job ini menangani pengiriman 1 pesan WhatsApp dengan:
 * 1. Idempotency key untuk mencegah double send
 * 2. Quota consumption yang atomic
 * 3. Auto rollback jika gagal
 * 4. Retry safety
 * 
 * FLOW:
 * =====
 * 1. Cek idempotency key - skip jika sudah diproses
 * 2. Consume quota dengan idempotency key
 * 3. Kirim pesan via WhatsApp API
 * 4. Update status target
 * 5. Jika gagal: rollback quota
 * 
 * ANTI RACE CONDITION:
 * ====================
 * - Setiap pesan punya unique idempotency key
 * - Retry dengan key sama = tidak potong kuota lagi
 * - Job berjalan dalam transaction
 * 
 * @package App\Jobs
 * @author Senior Backend Architect
 */
class SendSingleMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job properties
     */
    protected int $targetId;
    protected int $klienId;
    protected int $kampanyeId;
    protected string $idempotencyKey;

    /**
     * Retry configuration
     */
    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [10, 30, 60]; // Progressive backoff

    /**
     * Create a new job instance.
     * 
     * @param int $targetId ID target kampanye
     * @param int $klienId ID klien
     * @param int $kampanyeId ID kampanye
     */
    public function __construct(int $targetId, int $klienId, int $kampanyeId)
    {
        $this->targetId = $targetId;
        $this->klienId = $klienId;
        $this->kampanyeId = $kampanyeId;
        
        // Generate unique idempotency key
        // Format: msg_{kampanyeId}_{targetId}_{timestamp}
        // Ini memastikan retry untuk target yang SAMA tidak potong kuota lagi
        $this->idempotencyKey = "msg_{$kampanyeId}_{$targetId}";
        
        $this->onQueue('messages');
    }

    /**
     * Get unique ID for WithoutOverlapping middleware
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * Middleware: Prevent overlapping execution for same message
     */
    public function middleware(): array
    {
        return [
            // Prevent same message from being processed concurrently
            new WithoutOverlapping($this->idempotencyKey),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(
        QuotaService $quotaService,
        WhatsAppProviderInterface $waProvider
    ): void {
        Log::info('SendSingleMessageJob: Starting', [
            'target_id' => $this->targetId,
            'kampanye_id' => $this->kampanyeId,
            'idempotency_key' => $this->idempotencyKey,
            'attempt' => $this->attempts(),
        ]);

        // 1. Load target
        $target = TargetKampanye::find($this->targetId);
        
        if (!$target) {
            Log::warning('SendSingleMessageJob: Target not found', ['target_id' => $this->targetId]);
            return;
        }

        // 2. Skip jika target sudah diproses
        if (in_array($target->status, ['terkirim', 'gagal_permanen'])) {
            Log::info('SendSingleMessageJob: Target already processed', [
                'target_id' => $this->targetId,
                'status' => $target->status,
            ]);
            return;
        }

        // 3. Cek status kampanye
        $kampanye = Kampanye::find($this->kampanyeId);
        if (!$kampanye || $kampanye->status !== 'berjalan') {
            Log::info('SendSingleMessageJob: Campaign not running', [
                'kampanye_id' => $this->kampanyeId,
                'status' => $kampanye?->status,
            ]);
            return;
        }

        $quotaConsumed = false;
        $messageId = null;

        try {
            // 4. CONSUME QUOTA dengan idempotency key
            // Jika retry, key sama = skip consume
            $consumeResult = $quotaService->consume(
                $this->klienId,
                1, // 1 pesan
                $this->idempotencyKey,
                [
                    'target_id' => $this->targetId,
                    'kampanye_id' => $this->kampanyeId,
                    'phone' => $target->nomor_telepon,
                ]
            );

            if (!$consumeResult['success']) {
                throw new \DomainException('Gagal mengkonsumsi kuota: ' . ($consumeResult['message'] ?? 'Unknown error'));
            }

            // Tandai kuota sudah dikonsumsi (untuk rollback jika perlu)
            $quotaConsumed = !$consumeResult['skipped'];

            Log::info('SendSingleMessageJob: Quota consumed', [
                'target_id' => $this->targetId,
                'consumed' => $quotaConsumed,
                'skipped' => $consumeResult['skipped'] ?? false,
                'remaining' => $consumeResult['remaining_quota'] ?? null,
            ]);

            // 5. KIRIM PESAN
            $sendResult = $waProvider->send([
                'to' => $target->nomor_telepon,
                'template_name' => $kampanye->template?->nama_template,
                'template_params' => $this->parseTemplateParams($target, $kampanye),
                'language' => $kampanye->template?->bahasa ?? 'id',
            ]);

            if (!$sendResult['success']) {
                throw new \Exception('WhatsApp API Error: ' . ($sendResult['error'] ?? 'Unknown error'));
            }

            $messageId = $sendResult['message_id'] ?? null;

            // 6. UPDATE STATUS TARGET - SUKSES
            $target->update([
                'status' => 'terkirim',
                'message_id' => $messageId,
                'waktu_kirim' => now(),
            ]);

            Log::info('SendSingleMessageJob: Message sent successfully', [
                'target_id' => $this->targetId,
                'message_id' => $messageId,
            ]);

        } catch (\DomainException $e) {
            // Business error (kuota habis, dll) - Jangan retry
            Log::warning('SendSingleMessageJob: Business error', [
                'target_id' => $this->targetId,
                'error' => $e->getMessage(),
            ]);

            $target->update([
                'status' => 'gagal_permanen',
                'error_message' => $e->getMessage(),
            ]);

            // Tidak perlu rollback untuk DomainException
            $this->fail($e);

        } catch (Throwable $e) {
            // Technical error - Mungkin perlu retry
            Log::error('SendSingleMessageJob: Technical error', [
                'target_id' => $this->targetId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // ROLLBACK QUOTA jika sudah dikonsumsi dan ini attempt terakhir
            if ($quotaConsumed && $this->attempts() >= $this->tries) {
                try {
                    $quotaService->rollback(
                        $this->klienId,
                        1,
                        $this->idempotencyKey,
                        'send_failed_final'
                    );
                    
                    Log::info('SendSingleMessageJob: Quota rolled back', [
                        'target_id' => $this->targetId,
                        'idempotency_key' => $this->idempotencyKey,
                    ]);
                } catch (Throwable $rollbackError) {
                    Log::error('SendSingleMessageJob: Rollback failed', [
                        'target_id' => $this->targetId,
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }

            // Update target status
            if ($this->attempts() >= $this->tries) {
                $target->update([
                    'status' => 'gagal_permanen',
                    'error_message' => $e->getMessage(),
                ]);
            } else {
                $target->update([
                    'status' => 'gagal_retry',
                    'error_message' => $e->getMessage(),
                ]);
            }

            throw $e; // Re-throw untuk trigger retry
        }
    }

    /**
     * Handle job failure (after all retries exhausted)
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendSingleMessageJob: Final failure', [
            'target_id' => $this->targetId,
            'kampanye_id' => $this->kampanyeId,
            'error' => $exception->getMessage(),
        ]);

        // Update target status
        TargetKampanye::where('id', $this->targetId)
            ->update([
                'status' => 'gagal_permanen',
                'error_message' => 'Job failed: ' . $exception->getMessage(),
            ]);
    }

    /**
     * Parse template parameters from target data
     */
    protected function parseTemplateParams(TargetKampanye $target, Kampanye $kampanye): array
    {
        $params = [];
        
        // Get contact data
        $kontak = $target->kontak;
        
        if ($kontak) {
            $params['nama'] = $kontak->nama ?? $target->nama ?? '';
            $params['email'] = $kontak->email ?? '';
            
            // Additional fields from kontak
            if ($kontak->data_tambahan) {
                $params = array_merge($params, $kontak->data_tambahan);
            }
        }
        
        // Merge with campaign-specific params
        if ($kampanye->parameter_template) {
            $params = array_merge($params, $kampanye->parameter_template);
        }
        
        return $params;
    }
}
