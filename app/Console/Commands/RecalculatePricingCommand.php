<?php

namespace App\Console\Commands;

use App\Jobs\RecalculatePricing;
use App\Models\PricingLog;
use App\Models\PricingSetting;
use App\Services\AutoPricingService;
use Illuminate\Console\Command;

/**
 * Artisan Command: Recalculate Pricing
 * 
 * Usage:
 * - php artisan pricing:recalculate              // Sync recalculation
 * - php artisan pricing:recalculate --queue      // Dispatch to queue
 * - php artisan pricing:recalculate --preview    // Preview only, don't apply
 * - php artisan pricing:recalculate --cost=400   // Update cost and recalculate
 */
class RecalculatePricingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pricing:recalculate 
        {--queue : Dispatch to queue instead of sync}
        {--preview : Preview calculation without applying}
        {--cost= : Set new cost and trigger recalculation}
        {--reason= : Reason for manual trigger}';

    /**
     * The console command description.
     */
    protected $description = 'Recalculate dynamic pricing based on current conditions';

    /**
     * Execute the console command.
     */
    public function handle(AutoPricingService $pricingService): int
    {
        $useQueue = $this->option('queue');
        $preview = $this->option('preview');
        $newCost = $this->option('cost');
        $reason = $this->option('reason') ?? 'Manual trigger via artisan';

        $this->info('Auto Pricing Recalculation');
        $this->line('==========================');

        // Handle cost update
        if ($newCost) {
            $newCost = (float) $newCost;
            $this->info("Updating cost to: Rp {$newCost}");
            
            if ($preview) {
                $this->warn("Preview mode - cost will not be updated");
            } else {
                $result = $pricingService->onCostChange($newCost, 'manual', $reason);
                return $this->displayResult($result);
            }
        }

        // Queue dispatch
        if ($useQueue && !$preview) {
            RecalculatePricing::dispatch(PricingLog::TRIGGER_MANUAL, $reason);
            $this->info('Job dispatched to queue.');
            return 0;
        }

        // Show current state
        $settings = PricingSetting::get();
        $this->table(
            ['Current Setting', 'Value'],
            [
                ['Cost per Message', 'Rp ' . number_format($settings->base_cost_per_message, 0)],
                ['Current Price', 'Rp ' . number_format($settings->current_price_per_message, 0)],
                ['Current Margin', number_format(PricingSetting::getCurrentMargin(), 2) . '%'],
                ['Target Margin', $settings->target_margin_percent . '%'],
                ['Auto Adjust', $settings->auto_adjust_enabled ? 'Enabled' : 'Disabled'],
            ]
        );

        // Calculate
        $this->newLine();
        $this->info('Calculating new price...');

        if ($preview) {
            $result = $pricingService->previewPrice();
            $this->warn('PREVIEW MODE - Price will NOT be applied');
        } else {
            $result = $pricingService->calculatePrice(PricingLog::TRIGGER_MANUAL, $reason);
        }

        return $this->displayResult($result);
    }

    /**
     * Display calculation result
     */
    protected function displayResult(array $result): int
    {
        $this->newLine();
        $this->info('Input Values:');
        $this->table(
            ['Input', 'Value'],
            [
                ['Cost', 'Rp ' . number_format($result['inputs']['cost'], 0)],
                ['Health Score', $result['inputs']['health_score']],
                ['Health Status', strtoupper($result['inputs']['health_status'])],
                ['Delivery Rate', $result['inputs']['delivery_rate'] . '%'],
                ['Daily Volume', number_format($result['inputs']['daily_volume'])],
                ['Target Margin', $result['inputs']['target_margin'] . '%'],
            ]
        );

        $this->newLine();
        $this->info('Calculation Breakdown:');
        $this->table(
            ['Step', 'Value'],
            [
                ['Base Price (cost × margin)', 'Rp ' . number_format($result['calculations']['base_price'], 0)],
                ['Health Adjustment', '+' . $result['calculations']['health_adjustment'] . '%'],
                ['Volume Adjustment', '+' . $result['calculations']['volume_adjustment'] . '%'],
                ['Cost Adjustment', '+' . $result['calculations']['cost_adjustment'] . '%'],
                ['Raw Calculated Price', 'Rp ' . number_format($result['calculations']['raw_price'], 0)],
                ['Smoothed Price', 'Rp ' . number_format($result['calculations']['smoothed_price'], 0)],
                ['Guardrail Applied', $result['calculations']['guardrail_applied'] ? 'Yes' : 'No'],
                ['Guardrail Reason', $result['calculations']['guardrail_reason'] ?? '-'],
            ]
        );

        $this->newLine();
        $this->info('Result:');
        
        $changeColor = $result['result']['price_change_percent'] > 0 ? 'yellow' : 
                       ($result['result']['price_change_percent'] < 0 ? 'green' : 'white');
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Previous Price', 'Rp ' . number_format($result['result']['previous_price'], 0)],
                ['New Price', 'Rp ' . number_format($result['result']['new_price'], 0)],
                ['Price Change', ($result['result']['price_change_percent'] >= 0 ? '+' : '') . $result['result']['price_change_percent'] . '%'],
                ['Actual Margin', $result['result']['actual_margin_percent'] . '%'],
            ]
        );

        if ($result['should_block']) {
            $this->newLine();
            $this->error('⚠️  SENDING BLOCKED: Health status CRITICAL with block_on_critical enabled');
        }

        $this->newLine();
        $this->info('Done!');

        return 0;
    }
}
