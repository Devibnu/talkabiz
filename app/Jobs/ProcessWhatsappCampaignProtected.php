<?php

namespace App\Jobs;

use App\Models\WhatsappCampaign;
use App\Models\WhatsappConnection;
use App\Services\Message\MessageDispatchService;
use App\Services\Message\MessageDispatchRequest;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * ProcessWhatsappCampaignProtected
 * 
 * NEW: Job yang menggunakan MessageDispatchService dengan strict saldo protection.
 * Menggantikan ProcessWhatsappCampaign yang legacy.
 * 
 * ATURAN MUTLAK:
 * 1. Semua pengiriman HARUS melalui MessageDispatchService
 * 2. Tidak ada bypass saldo
 * 3. Atomic transaction dengan row locking
 * 4. Hard stop jika saldo tidak cukup
 * 
 * PERBEDAAN dengan job lama:
 * - Tidak ada hardcoded cost
 * - Tidak ada manual saldo checking
 * - Tidak ada loop sending individual messages
 * - Atomic batch processing via MessageDispatchService
 */
class ProcessWhatsappCampaignProtected implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public WhatsappCampaign $campaign;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2; // Reduced because saldo failures don't need retry

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(WhatsappCampaign $campaign)
    {
        $this->campaign = $campaign;
    }

    /**
     * Execute the job with STRICT saldo protection
     */
    public function handle(MessageDispatchService $messageDispatch): void
    {
        $campaign = $this->campaign->fresh();

        // Pre-execution validation
        if (!$this->shouldContinue($campaign)) {
            Log::info('Protected WA Campaign job stopped', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);
            return;
        }

        if (!$this->validateCampaignPrerequisites($campaign)) {
            return; // Already logged inside validation method
        }

        try {
            Log::info('Protected WA Campaign: Executing via MessageDispatch', [
                'campaign_id' => $campaign->id,
                'klien_id' => $campaign->klien_id,
                'status' => $campaign->status,
            ]);

            // Execute entire campaign via MessageDispatchService
            $result = $this->executeCampaignViaMDS($campaign, $messageDispatch);
            
            // Update campaign from results
            $this->updateCampaignFromResult($campaign, $result);

            Log::info('Protected WA Campaign COMPLETED', [
                'campaign_id' => $campaign->id,
                'sent' => $result->totalSent,
                'failed' => $result->totalFailed,
                'cost' => $result->totalCost,
                'transaction_code' => $result->transactionCode,
                'success_rate' => $result->getSuccessRate(),
            ]);

        } catch (InsufficientBalanceException $e) {
            // HARD STOP: Saldo tidak cukup
            $campaign->update([
                'status' => WhatsappCampaign::STATUS_PAUSED,
                'last_error' => $e->getMessage(),
                'paused_at' => now(),
            ]);

            Log::error('Protected WA Campaign BLOCKED - Insufficient Balance', [
                'campaign_id' => $campaign->id,
                'klien_id' => $campaign->klien_id,
                'required' => $e->getRequiredAmount(),
                'current' => $e->getCurrentBalance(),
                'shortage' => $e->getShortageAmount(),
            ]);

            // TODO: Send notification to client about insufficient balance
            // NotificationService::sendLowBalanceAlert($campaign->klien_id, $e);

        } catch (Exception $e) {
            // Other failures
            $campaign->update([
                'status' => WhatsappCampaign::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'failed_at' => now(),
            ]);

            Log::error('Protected WA Campaign FAILED', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw for job failure handling
        }
    }

    /**
     * Execute campaign via MessageDispatchService
     */
    protected function executeCampaignViaMDS(
        WhatsappCampaign $campaign, 
        MessageDispatchService $messageDispatch
    ): \App\Services\Message\MessageDispatchResult {
        
        // Get all pending recipients
        $recipients = $campaign->recipients()
            ->with('contact')
            ->where('status', 'pending')
            ->get()
            ->map(function ($recipient) {
                return [
                    'phone' => $recipient->phone_number,
                    'contact_id' => $recipient->contact_id,
                    'name' => $recipient->contact?->name ?? 'Customer',
                    'recipient_id' => $recipient->id
                ];
            })
            ->toArray();

        if (empty($recipients)) {
            throw new Exception('No pending recipients found for campaign');
        }

        // Get template and build message content
        $template = $campaign->template;
        if (!$template || !$template->isApproved()) {
            throw new Exception('Campaign template not available or not approved');
        }

        $messageContent = $this->buildMessageContent($template, $campaign->template_variables ?? []);

        // Create dispatch request
        $dispatchRequest = MessageDispatchRequest::fromCampaign(
            userId: $campaign->klien->user_id,
            recipients: $recipients,
            messageContent: $messageContent,
            campaignId: (string) $campaign->id
        );

        // Mark campaign as running
        $campaign->update(['status' => WhatsappCampaign::STATUS_RUNNING]);

        // Execute via MessageDispatchService (atomic saldo deduction)
        return $messageDispatch->dispatch($dispatchRequest);
    }

    /**
     * Validate campaign prerequisites
     */
    protected function validateCampaignPrerequisites(WhatsappCampaign $campaign): bool
    {
        // Check connection
        $connection = WhatsappConnection::where('klien_id', $campaign->klien_id)->first();
        if (!$connection || !$connection->isConnected()) {
            Log::error('Protected WA Campaign: Connection not available', [
                'campaign_id' => $campaign->id,
            ]);
            $campaign->update([
                'status' => WhatsappCampaign::STATUS_PAUSED,
                'last_error' => 'WhatsApp connection not available'
            ]);
            return false;
        }

        // Check template
        $template = $campaign->template;
        if (!$template || !$template->isApproved()) {
            Log::error('Protected WA Campaign: Template not available or not approved', [
                'campaign_id' => $campaign->id,
                'template_id' => $campaign->template_id,
            ]);
            $campaign->update([
                'status' => WhatsappCampaign::STATUS_CANCELLED,
                'last_error' => 'Template not available or not approved'
            ]);
            return false;
        }

        return true;
    }

    /**
     * Build message content from template
     */
    protected function buildMessageContent($template, array $variables = []): string
    {
        $content = $template->content ?? $template->template_text ?? '';

        // Replace variables in template
        foreach ($variables as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
        }

        return $content;
    }

    /**
     * Update campaign from dispatch result
     */
    protected function updateCampaignFromResult(
        WhatsappCampaign $campaign, 
        \App\Services\Message\MessageDispatchResult $result
    ): void {
        // Determine final status
        $status = $result->success 
            ? ($result->totalFailed > 0 ? WhatsappCampaign::STATUS_PARTIAL : WhatsappCampaign::STATUS_COMPLETED)
            : WhatsappCampaign::STATUS_FAILED;

        // Update campaign
        $campaign->update([
            'status' => $status,
            'sent_count' => $result->totalSent,
            'failed_count' => $result->totalFailed,
            'actual_cost' => $result->totalCost,
            'completed_at' => now(),
            'last_error' => $result->success ? null : 'Some messages failed to send',
            'transaction_code' => $result->transactionCode,
        ]);

        // Update recipient statuses from results
        foreach ($result->sentResults as $sentResult) {
            $recipient = $campaign->recipients()
                ->where('phone_number', $sentResult['recipient'])
                ->first();

            if ($recipient) {
                $recipient->update([
                    'status' => $sentResult['status'] === 'sent' ? 'sent' : 'failed',
                    'whatsapp_message_id' => $sentResult['message_id'],
                    'error' => $sentResult['error'],
                    'sent_at' => $sentResult['sent_at'],
                    'cost' => $sentResult['status'] === 'sent' ? ($result->totalCost / max($result->totalSent, 1)) : 0,
                ]);
            }
        }
    }

    /**
     * Check if campaign should continue processing
     */
    protected function shouldContinue(WhatsappCampaign $campaign): bool
    {
        return $campaign->status === WhatsappCampaign::STATUS_RUNNING 
            || $campaign->status === WhatsappCampaign::STATUS_SCHEDULED;
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Protected WA Campaign job failed', [
            'campaign_id' => $this->campaign->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->campaign->update([
            'status' => WhatsappCampaign::STATUS_FAILED,
            'last_error' => 'Job failed: ' . $exception->getMessage(),
            'failed_at' => now(),
        ]);
    }
}