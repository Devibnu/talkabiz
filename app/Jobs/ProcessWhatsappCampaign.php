<?php

namespace App\Jobs;

use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappConnection;
use App\Services\GupshupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * ProcessWhatsappCampaign
 * 
 * DEPRECATED: This job BYPASSES saldo protection!
 * 
 * ⚠️  WARNING: DO NOT USE THIS JOB IN PRODUCTION
 * ⚠️  This job does not use MessageDispatchService
 * ⚠️  It bypasses atomic saldo deduction
 * ⚠️  It uses hardcoded message costs
 * ⚠️  It can cause saldo abuse and inconsistencies
 * 
 * USE: ProcessWhatsappCampaignProtected instead
 * 
 * This job is preserved only for:
 * - Legacy compatibility testing
 * - Emergency fallback (with admin approval)
 * - Understanding old implementation
 */

class ProcessWhatsappCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public WhatsappCampaign $campaign;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(WhatsappCampaign $campaign)
    {
        $this->campaign = $campaign;
    }

    /**
     * Execute the job.
     * 
     * DEPRECATED: This method BYPASSES saldo protection!
     */
    public function handle(): void
    {
        // CRITICAL WARNING: This job bypasses MessageDispatchService saldo protection
        Log::warning('SECURITY ALERT: Using LEGACY ProcessWhatsappCampaign job', [
            'campaign_id' => $this->campaign->id,
            'message' => 'This job BYPASSES saldo protection. Use ProcessWhatsappCampaignProtected instead.',
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ]);

        $campaign = $this->campaign->fresh();

        // Check if campaign should continue
        if (!$this->shouldContinue($campaign)) {
            Log::info('WA Campaign job stopped', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);
            return;
        }

        // Get connection
        $connection = WhatsappConnection::where('klien_id', $campaign->klien_id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            Log::error('WA Campaign: Connection not available', [
                'campaign_id' => $campaign->id,
            ]);
            $campaign->pause();
            return;
        }

        $gupshup = GupshupService::forConnection($connection);
        $template = $campaign->template;

        if (!$template || !$template->isApproved()) {
            Log::error('WA Campaign: Template not available or not approved', [
                'campaign_id' => $campaign->id,
                'template_id' => $campaign->template_id,
            ]);
            $campaign->cancel();
            return;
        }

        // Process pending recipients in batches
        $batchSize = 100;
        $rateLimit = $campaign->rate_limit_per_second;
        $delayMicroseconds = 1000000 / $rateLimit;

        $pendingRecipients = $campaign->recipients()
            ->pending()
            ->take($batchSize)
            ->get();

        if ($pendingRecipients->isEmpty()) {
            // All recipients processed
            $this->completeCampaign($campaign);
            return;
        }

        Log::info('WA Campaign: Processing batch', [
            'campaign_id' => $campaign->id,
            'batch_size' => $pendingRecipients->count(),
            'rate_limit' => $rateLimit,
        ]);

        foreach ($pendingRecipients as $recipient) {
            // Check if campaign is still running
            $campaign->refresh();
            if (!$this->shouldContinue($campaign)) {
                return;
            }

            // Mark as queued
            $recipient->markAsQueued();

            try {
                // Build template parameters for this recipient
                $params = $this->buildTemplateParams($recipient, $campaign);

                // Send message via Gupshup
                $response = $gupshup->sendTemplateMessage(
                    destination: $recipient->phone_number,
                    templateId: $template->template_id,
                    params: $params,
                    klienId: $campaign->klien_id,
                    campaignId: $campaign->id
                );

                if (isset($response['messageId'])) {
                    $recipient->markAsSent(
                        $response['messageId'],
                        WhatsappCampaign::COST_PER_MESSAGE
                    );
                    $campaign->increment('sent_count');
                } else {
                    throw new Exception($response['message'] ?? 'No message ID returned');
                }

            } catch (Exception $e) {
                Log::error('WA Campaign: Failed to send message', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'phone' => $recipient->phone_number,
                    'error' => $e->getMessage(),
                ]);

                $recipient->markAsFailed('SEND_ERROR', $e->getMessage());
                $campaign->increment('failed_count');
            }

            // Rate limiting
            usleep((int) $delayMicroseconds);
        }

        // Check if more recipients to process
        $remainingCount = $campaign->recipients()->pending()->count();
        
        if ($remainingCount > 0) {
            // Dispatch next batch
            self::dispatch($campaign)->delay(now()->addSeconds(2));
        } else {
            $this->completeCampaign($campaign);
        }
    }

    /**
     * Check if campaign should continue processing
     */
    protected function shouldContinue(WhatsappCampaign $campaign): bool
    {
        return $campaign->status === WhatsappCampaign::STATUS_RUNNING;
    }

    /**
     * Complete the campaign
     */
    protected function completeCampaign(WhatsappCampaign $campaign): void
    {
        $campaign->complete();
        $campaign->updateActualCost();

        Log::info('WA Campaign completed', [
            'campaign_id' => $campaign->id,
            'sent' => $campaign->sent_count,
            'failed' => $campaign->failed_count,
            'actual_cost' => $campaign->actual_cost,
        ]);
    }

    /**
     * Build template parameters for a recipient
     */
    protected function buildTemplateParams(WhatsappCampaignRecipient $recipient, WhatsappCampaign $campaign): array
    {
        $contact = $recipient->contact;
        $mappings = $campaign->template_variables ?? [];
        $params = [];

        // Default variable replacements
        $variables = [
            'name' => $contact->name ?? 'Pelanggan',
            'phone' => $contact->phone_number,
            'email' => $contact->email ?? '',
        ];

        // Add custom fields
        if ($contact->custom_fields) {
            $variables = array_merge($variables, $contact->custom_fields);
        }

        // Apply mappings
        foreach ($mappings as $index => $field) {
            $params[] = $variables[$field] ?? '';
        }

        // If no mappings, use sequential custom values
        if (empty($params) && isset($mappings['values'])) {
            $params = $mappings['values'];
        }

        return $params;
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('WA Campaign job failed', [
            'campaign_id' => $this->campaign->id,
            'error' => $exception->getMessage(),
        ]);

        $this->campaign->pause();
    }
}
