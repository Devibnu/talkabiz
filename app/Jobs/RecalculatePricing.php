<?php

namespace App\Jobs;

use App\Models\PricingLog;
use App\Services\AutoPricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Recalculate Pricing
 * 
 * Job untuk menghitung ulang harga berdasarkan kondisi saat ini.
 * Dapat di-trigger oleh scheduler, cost change, atau health drop.
 * 
 * Dispatch:
 * - RecalculatePricing::dispatch() // Scheduled recalculation
 * - RecalculatePricing::dispatch('cost_change', 'Gupshup rate update')
 * - RecalculatePricing::dispatch('health_drop', 'Critical health detected')
 */
class RecalculatePricing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $triggerType;
    public ?string $triggerReason;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $triggerType = PricingLog::TRIGGER_SCHEDULED,
        ?string $triggerReason = null
    ) {
        $this->triggerType = $triggerType;
        $this->triggerReason = $triggerReason;
    }

    /**
     * Execute the job.
     */
    public function handle(AutoPricingService $pricingService): void
    {
        Log::info('RecalculatePricing job started', [
            'trigger' => $this->triggerType,
            'reason' => $this->triggerReason,
        ]);

        try {
            $result = $pricingService->calculatePrice(
                $this->triggerType,
                $this->triggerReason,
                true // Apply the new price
            );

            Log::info('RecalculatePricing completed', [
                'previous_price' => $result['result']['previous_price'],
                'new_price' => $result['result']['new_price'],
                'change' => $result['result']['price_change_percent'] . '%',
                'margin' => $result['result']['actual_margin_percent'] . '%',
            ]);

        } catch (\Exception $e) {
            Log::error('RecalculatePricing job failed', [
                'trigger' => $this->triggerType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Will trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RecalculatePricing job finally failed', [
            'trigger' => $this->triggerType,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'auto-pricing',
            'trigger:' . $this->triggerType,
        ];
    }
}
