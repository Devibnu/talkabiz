<?php

namespace App\Jobs;

use App\Models\ChaosExperiment;
use App\Services\ChaosWhatsAppMockProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * SIMULATE CHAOS WEBHOOK JOB
 * =============================================================================
 * 
 * Simulates incoming webhooks for chaos testing:
 * - Quality downgrade webhooks
 * - Delayed status updates
 * - Duplicate webhooks
 * - Out-of-order webhooks
 * 
 * =============================================================================
 */
class SimulateChaosWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $webhookType;
    private array $config;
    private ?int $experimentId;

    public function __construct(string $webhookType, array $config = [], ?int $experimentId = null)
    {
        $this->webhookType = $webhookType;
        $this->config = $config;
        $this->experimentId = $experimentId;
        $this->onQueue('chaos');
    }

    public function handle(): void
    {
        if (app()->environment('production')) {
            Log::channel('chaos')->critical("BLOCKED: Webhook simulation in production");
            return;
        }

        Log::channel('chaos')->info("Simulating chaos webhook", [
            'type' => $this->webhookType,
            'config' => $this->config
        ]);

        switch ($this->webhookType) {
            case 'quality_downgrade':
                $this->simulateQualityDowngrade();
                break;

            case 'status_update':
                $this->simulateStatusUpdate();
                break;

            case 'duplicate':
                $this->simulateDuplicateWebhook();
                break;

            case 'delayed':
                $this->simulateDelayedWebhook();
                break;

            case 'replay':
                $this->simulateReplayWebhooks();
                break;
        }

        // Log to experiment if exists
        if ($this->experimentId) {
            $experiment = ChaosExperiment::find($this->experimentId);
            $experiment?->logEvent('webhook_simulated', "Simulated {$this->webhookType} webhook");
        }
    }

    private function simulateQualityDowngrade(): void
    {
        $rating = $this->config['quality_rating'] ?? 'RED';
        $payload = ChaosWhatsAppMockProvider::getQualityDowngradeWebhook($rating);

        $this->sendToWebhookEndpoint($payload);
    }

    private function simulateStatusUpdate(): void
    {
        $messageId = $this->config['message_id'] ?? 'wamid.chaos_' . uniqid();
        $status = $this->config['status'] ?? 'failed';
        
        $payload = ChaosWhatsAppMockProvider::getMockStatusWebhook($messageId, $status);

        $this->sendToWebhookEndpoint($payload);
    }

    private function simulateDuplicateWebhook(): void
    {
        $messageId = $this->config['message_id'] ?? 'wamid.chaos_duplicate_' . uniqid();
        $count = $this->config['duplicate_count'] ?? 5;
        
        $payload = ChaosWhatsAppMockProvider::getMockStatusWebhook($messageId, 'delivered');

        // Send same webhook multiple times
        for ($i = 0; $i < $count; $i++) {
            $this->sendToWebhookEndpoint($payload);
            usleep(100000); // 100ms between duplicates
        }
    }

    private function simulateDelayedWebhook(): void
    {
        $delaySeconds = $this->config['delay_seconds'] ?? 300;
        
        // Sleep to simulate delay
        sleep($delaySeconds);

        $messageId = $this->config['message_id'] ?? 'wamid.chaos_delayed_' . uniqid();
        $payload = ChaosWhatsAppMockProvider::getMockStatusWebhook($messageId, 'delivered');

        $this->sendToWebhookEndpoint($payload);
    }

    private function simulateReplayWebhooks(): void
    {
        $count = $this->config['replay_count'] ?? 5;
        $interval = $this->config['replay_interval_seconds'] ?? 10;

        // Get some random message IDs
        $messageIds = [];
        for ($i = 0; $i < 3; $i++) {
            $messageIds[] = 'wamid.chaos_replay_' . uniqid();
        }

        // Replay each one multiple times
        for ($i = 0; $i < $count; $i++) {
            foreach ($messageIds as $messageId) {
                $payload = ChaosWhatsAppMockProvider::getMockStatusWebhook($messageId, 'delivered');
                $this->sendToWebhookEndpoint($payload);
            }
            
            if ($i < $count - 1) {
                sleep($interval);
            }
        }
    }

    private function sendToWebhookEndpoint(array $payload): void
    {
        $webhookUrl = config('app.url') . '/api/webhook/whatsapp';

        try {
            Http::timeout(30)
                ->withHeaders([
                    'X-Chaos-Injection' => 'true',
                    'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'chaos_test')
                ])
                ->post($webhookUrl, $payload);

            Log::channel('chaos')->debug("Chaos webhook sent", [
                'url' => $webhookUrl,
                'type' => $this->webhookType
            ]);

        } catch (\Exception $e) {
            Log::channel('chaos')->error("Failed to send chaos webhook", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
