<?php

namespace App\Services;

use App\Models\BillingEvent;
use App\Models\BillingUsageDaily;
use App\Models\MessageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * BillingAggregatorService
 * 
 * Service untuk agregasi billing data.
 * Dijalankan via scheduled job atau manual.
 * 
 * FUNCTIONS:
 * ==========
 * 1. Aggregate billing_events ke billing_usage_daily
 * 2. Recalculate daily summaries
 * 3. Generate reports
 */
class BillingAggregatorService
{
    protected MetaCostService $metaCostService;

    public function __construct(MetaCostService $metaCostService)
    {
        $this->metaCostService = $metaCostService;
    }

    /**
     * Aggregate billing events for a specific date
     * 
     * Useful for recalculating or fixing data
     */
    public function aggregateForDate(Carbon $date): array
    {
        $results = [
            'date' => $date->format('Y-m-d'),
            'processed' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        // Get all billing events for the date grouped by klien and category
        $events = BillingEvent::query()
            ->whereDate('created_at', $date)
            ->where('direction', 'outbound')
            ->where('is_duplicate', false)
            ->select('klien_id', 'message_category')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(meta_cost) as total_meta_cost')
            ->selectRaw('SUM(sell_price) as total_revenue')
            ->selectRaw('SUM(profit) as total_profit')
            ->selectRaw("SUM(CASE WHEN trigger_event = 'sent' THEN 1 ELSE 0 END) as sent_count")
            ->selectRaw("SUM(CASE WHEN trigger_event = 'delivered' THEN 1 ELSE 0 END) as delivered_count")
            ->groupBy('klien_id', 'message_category')
            ->get();

        foreach ($events as $event) {
            try {
                $daily = BillingUsageDaily::updateOrCreate(
                    [
                        'klien_id' => $event->klien_id,
                        'usage_date' => $date,
                        'message_category' => $event->message_category,
                    ],
                    [
                        'messages_sent' => $event->sent_count,
                        'messages_delivered' => $event->delivered_count,
                        'billable_count' => $event->count,
                        'total_meta_cost' => $event->total_meta_cost,
                        'total_revenue' => $event->total_revenue,
                        'total_profit' => $event->total_profit,
                        'margin_percentage' => $event->total_revenue > 0 
                            ? ($event->total_profit / $event->total_revenue) * 100 
                            : 0,
                        'last_aggregated_at' => now(),
                        'aggregation_count' => DB::raw('aggregation_count + 1'),
                    ]
                );

                $results['processed'] += $event->count;
                $results['updated']++;

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'klien_id' => $event->klien_id,
                    'category' => $event->message_category,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::channel('billing')->info('Daily aggregation completed', $results);

        return $results;
    }

    /**
     * Aggregate for today
     */
    public function aggregateToday(): array
    {
        return $this->aggregateForDate(today());
    }

    /**
     * Aggregate for date range
     */
    public function aggregateForRange(Carbon $from, Carbon $to): array
    {
        $results = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'days_processed' => 0,
            'total_records' => 0,
            'errors' => [],
        ];

        $current = $from->copy();
        while ($current->lte($to)) {
            $dayResult = $this->aggregateForDate($current);
            $results['days_processed']++;
            $results['total_records'] += $dayResult['updated'];
            if (!empty($dayResult['errors'])) {
                $results['errors'] = array_merge($results['errors'], $dayResult['errors']);
            }
            $current->addDay();
        }

        return $results;
    }

    /**
     * Process fallback billing for sent messages without delivered callback
     * 
     * Messages that were sent but didn't receive delivered callback
     * after X minutes should be billed on 'sent' event.
     */
    public function processFallbackBilling(int $minutesThreshold = 30): array
    {
        $results = [
            'processed' => 0,
            'billed' => 0,
            'errors' => [],
        ];

        // Find sent events without corresponding delivered that are older than threshold
        $sentEvents = MessageEvent::query()
            ->where('event_type', MessageEvent::EVENT_SENT)
            ->where('created_at', '<', now()->subMinutes($minutesThreshold))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('billing_events')
                    ->whereColumn('billing_events.provider_message_id', 'message_events.provider_message_id');
            })
            ->limit(1000) // Process in batches
            ->get();

        foreach ($sentEvents as $event) {
            try {
                $context = [
                    'klien_id' => $event->klien_id,
                    'message_category' => $event->metadata['category'] ?? 'marketing',
                    'fallback_billing' => true,
                ];

                $result = $this->metaCostService->processMessageEvent($event, $context);
                
                $results['processed']++;
                if ($result['billed']) {
                    $results['billed']++;
                }

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::channel('billing')->info('Fallback billing completed', $results);

        return $results;
    }

    /**
     * Get monthly billing report for klien
     */
    public function getMonthlyReport(int $klienId, int $year, int $month): array
    {
        $data = BillingUsageDaily::query()
            ->where('klien_id', $klienId)
            ->whereYear('usage_date', $year)
            ->whereMonth('usage_date', $month)
            ->orderBy('usage_date')
            ->get();

        $byCategory = $data->groupBy('message_category');

        return [
            'klien_id' => $klienId,
            'period' => sprintf('%d-%02d', $year, $month),
            'summary' => [
                'total_messages' => $data->sum('billable_count'),
                'total_sent' => $data->sum('messages_sent'),
                'total_delivered' => $data->sum('messages_delivered'),
                'total_failed' => $data->sum('messages_failed'),
                'total_meta_cost' => $data->sum('total_meta_cost'),
                'total_revenue' => $data->sum('total_revenue'),
                'total_profit' => $data->sum('total_profit'),
                'avg_margin' => $data->avg('margin_percentage') ?? 0,
            ],
            'by_category' => $byCategory->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'count' => $items->sum('billable_count'),
                    'meta_cost' => $items->sum('total_meta_cost'),
                    'revenue' => $items->sum('total_revenue'),
                    'profit' => $items->sum('total_profit'),
                ];
            })->toArray(),
            'daily_breakdown' => $data->groupBy(fn($item) => $item->usage_date->format('Y-m-d'))
                ->map(function ($items, $date) {
                    return [
                        'date' => $date,
                        'count' => $items->sum('billable_count'),
                        'revenue' => $items->sum('total_revenue'),
                        'profit' => $items->sum('total_profit'),
                    ];
                })->values()->toArray(),
        ];
    }

    /**
     * Get invoiceable records (not yet invoiced)
     */
    public function getInvoiceableRecords(int $klienId, ?Carbon $upToDate = null): array
    {
        $query = BillingUsageDaily::query()
            ->where('klien_id', $klienId)
            ->where('is_invoiced', false);

        if ($upToDate) {
            $query->where('usage_date', '<=', $upToDate);
        }

        $records = $query->orderBy('usage_date')->get();

        return [
            'klien_id' => $klienId,
            'record_count' => $records->count(),
            'date_range' => [
                'from' => $records->min('usage_date')?->format('Y-m-d'),
                'to' => $records->max('usage_date')?->format('Y-m-d'),
            ],
            'totals' => [
                'messages' => $records->sum('billable_count'),
                'revenue' => $records->sum('total_revenue'),
                'meta_cost' => $records->sum('total_meta_cost'),
                'profit' => $records->sum('total_profit'),
            ],
            'records' => $records->toArray(),
        ];
    }

    /**
     * Mark records as invoiced
     */
    public function markAsInvoiced(array $recordIds, int $invoiceId): int
    {
        return BillingUsageDaily::whereIn('id', $recordIds)
            ->update([
                'is_invoiced' => true,
                'invoice_id' => $invoiceId,
                'invoiced_at' => now(),
            ]);
    }
}
