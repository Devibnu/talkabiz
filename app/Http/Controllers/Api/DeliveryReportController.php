<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageEvent;
use App\Models\MessageLog;
use App\Services\DeliveryReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * DeliveryReportController - Audit & Analytics API
 * 
 * Controller ini menyediakan API untuk:
 * 1. Query delivery reports
 * 2. Export untuk audit
 * 3. Dispute evidence
 * 4. Analytics & SLA tracking
 * 
 * PENTING UNTUK DISPUTE:
 * ======================
 * - Semua data di message_events adalah APPEND-ONLY
 * - Raw payload tersimpan untuk bukti
 * - Signature tersimpan untuk validasi
 * - Timestamp dari provider (bukan server)
 * 
 * @author Senior Software Architect
 */
class DeliveryReportController extends Controller
{
    protected DeliveryReportService $service;

    public function __construct(DeliveryReportService $service)
    {
        $this->service = $service;
    }

    // ==================== MESSAGE EVENTS ====================

    /**
     * Get events for a specific message
     */
    public function getMessageEvents(Request $request, string $messageIdOrKey): JsonResponse
    {
        $klienId = $request->user()->klien_id;

        // Find by provider_message_id or idempotency_key
        $messageLog = MessageLog::where('klien_id', $klienId)
            ->where(function ($q) use ($messageIdOrKey) {
                $q->where('provider_message_id', $messageIdOrKey)
                  ->orWhere('idempotency_key', $messageIdOrKey);
            })
            ->first();

        if (!$messageLog) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        $events = MessageEvent::where('message_log_id', $messageLog->id)
            ->orderBy('event_timestamp', 'asc')
            ->get()
            ->map(fn ($e) => $this->formatEvent($e));

        return response()->json([
            'success' => true,
            'data' => [
                'message' => [
                    'id' => $messageLog->id,
                    'idempotency_key' => $messageLog->idempotency_key,
                    'provider_message_id' => $messageLog->provider_message_id,
                    'phone_number' => $messageLog->phone_number,
                    'status' => $messageLog->status,
                    'sent_at' => $messageLog->sent_at?->toISOString(),
                    'delivered_at' => $messageLog->delivered_at?->toISOString(),
                    'read_at' => $messageLog->read_at?->toISOString(),
                ],
                'events' => $events,
                'timeline' => $this->buildTimeline($events),
            ],
        ]);
    }

    /**
     * Get recent events for klien
     */
    public function getRecentEvents(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id;
        $limit = min($request->input('limit', 50), 200);
        $eventType = $request->input('event_type');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = MessageEvent::where('klien_id', $klienId)
            ->orderBy('event_timestamp', 'desc');

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        if ($startDate) {
            $query->where('event_timestamp', '>=', Carbon::parse($startDate));
        }

        if ($endDate) {
            $query->where('event_timestamp', '<=', Carbon::parse($endDate));
        }

        $events = $query->limit($limit)->get()->map(fn ($e) => $this->formatEvent($e));

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    // ==================== DELIVERY STATISTICS ====================

    /**
     * Get delivery statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id;
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))
            : now()->startOfDay();
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : now()->endOfDay();

        $stats = $this->service->getDeliveryStats($klienId, $startDate, $endDate);
        $avgTimes = $this->service->getAverageDeliveryTimes($klienId, $startDate);

        return response()->json([
            'success' => true,
            'data' => array_merge($stats, $avgTimes),
            'period' => [
                'start' => $startDate->toISOString(),
                'end' => $endDate->toISOString(),
            ],
        ]);
    }

    /**
     * Get hourly statistics
     */
    public function getHourlyStats(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id;
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : now();

        $hourlyStats = MessageEvent::where('klien_id', $klienId)
            ->whereDate('event_timestamp', $date)
            ->where('status_changed', true)
            ->selectRaw('HOUR(event_timestamp) as hour, event_type, COUNT(*) as count')
            ->groupBy('hour', 'event_type')
            ->get();

        // Transform to hourly buckets
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[$h] = [
                'hour' => $h,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
            ];
        }

        foreach ($hourlyStats as $stat) {
            if (isset($result[$stat->hour])) {
                $result[$stat->hour][$stat->event_type] = $stat->count;
            }
        }

        return response()->json([
            'success' => true,
            'data' => array_values($result),
            'date' => $date->toDateString(),
        ]);
    }

    // ==================== DISPUTE & AUDIT ====================

    /**
     * Get audit trail for a message (for dispute resolution)
     */
    public function getAuditTrail(Request $request, string $messageId): JsonResponse
    {
        $klienId = $request->user()->klien_id;

        $messageLog = MessageLog::where('klien_id', $klienId)
            ->where(function ($q) use ($messageId) {
                $q->where('provider_message_id', $messageId)
                  ->orWhere('idempotency_key', $messageId)
                  ->orWhere('id', $messageId);
            })
            ->first();

        if (!$messageLog) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        // Get all events with raw payload
        $events = MessageEvent::where('message_log_id', $messageLog->id)
            ->orderBy('event_timestamp', 'asc')
            ->get();

        // Get webhook receipts for forensics
        $receipts = DB::table('webhook_receipts')
            ->where('message_event_id', $messageLog->id)
            ->orWhere('raw_body', 'like', '%' . $messageLog->provider_message_id . '%')
            ->orderBy('received_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'message_log' => [
                    'id' => $messageLog->id,
                    'idempotency_key' => $messageLog->idempotency_key,
                    'provider_message_id' => $messageLog->provider_message_id,
                    'phone_number' => $messageLog->phone_number,
                    'template_name' => $messageLog->template_name,
                    'status' => $messageLog->status,
                    'status_detail' => $messageLog->status_detail,
                    'provider_name' => $messageLog->provider_name,
                    'error_code' => $messageLog->error_code,
                    'error_message' => $messageLog->error_message,
                    'quota_consumed' => $messageLog->quota_consumed,
                    'message_cost' => $messageLog->message_cost,
                    'created_at' => $messageLog->created_at?->toISOString(),
                    'sending_at' => $messageLog->sending_at?->toISOString(),
                    'sent_at' => $messageLog->sent_at?->toISOString(),
                    'delivered_at' => $messageLog->delivered_at?->toISOString(),
                    'read_at' => $messageLog->read_at?->toISOString(),
                    'failed_at' => $messageLog->failed_at?->toISOString(),
                ],
                'events' => $events->map(function ($e) {
                    return [
                        'id' => $e->id,
                        'event_type' => $e->event_type,
                        'event_id' => $e->event_id,
                        'event_timestamp' => $e->event_timestamp?->toISOString(),
                        'status_before' => $e->status_before,
                        'status_after' => $e->status_after,
                        'status_changed' => $e->status_changed,
                        'error_code' => $e->error_code,
                        'error_message' => $e->error_message,
                        'is_duplicate' => $e->is_duplicate,
                        'is_out_of_order' => $e->is_out_of_order,
                        'process_result' => $e->process_result,
                        'process_note' => $e->process_note,
                        'raw_payload' => $e->raw_payload, // Full payload for audit
                        'webhook_signature' => $e->webhook_signature,
                        'received_at' => $e->received_at?->toISOString(),
                        'processed_at' => $e->processed_at?->toISOString(),
                    ];
                }),
                'webhook_receipts' => $receipts->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'provider' => $r->provider,
                        'source_ip' => $r->source_ip,
                        'signature' => $r->signature,
                        'signature_valid' => $r->signature_valid,
                        'response_code' => $r->response_code,
                        'received_at' => $r->received_at,
                    ];
                }),
            ],
            'disclaimer' => 'This audit trail is for dispute resolution. All webhook payloads are stored immutably.',
        ]);
    }

    /**
     * Export delivery report for date range (CSV)
     */
    public function exportReport(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $klienId = $request->user()->klien_id;
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : now()->subDays(7);
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : now();

        $filename = "delivery_report_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($klienId, $startDate, $endDate) {
            $handle = fopen('php://output', 'w');

            // Header
            fputcsv($handle, [
                'Event ID',
                'Provider Message ID',
                'Phone Number',
                'Event Type',
                'Event Timestamp',
                'Status Before',
                'Status After',
                'Status Changed',
                'Error Code',
                'Error Message',
                'Delivery Time (s)',
                'Read Time (s)',
                'Received At',
            ]);

            // Stream data
            MessageEvent::where('klien_id', $klienId)
                ->whereBetween('event_timestamp', [$startDate, $endDate])
                ->orderBy('event_timestamp', 'asc')
                ->chunk(1000, function ($events) use ($handle) {
                    foreach ($events as $event) {
                        fputcsv($handle, [
                            $event->id,
                            $event->provider_message_id,
                            $event->phone_number,
                            $event->event_type,
                            $event->event_timestamp?->toISOString(),
                            $event->status_before,
                            $event->status_after,
                            $event->status_changed ? 'Yes' : 'No',
                            $event->error_code,
                            $event->error_message,
                            $event->delivery_time_seconds,
                            $event->read_time_seconds,
                            $event->received_at?->toISOString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    // ==================== SLA TRACKING ====================

    /**
     * Get SLA metrics
     */
    public function getSlaMetrics(Request $request): JsonResponse
    {
        $klienId = $request->user()->klien_id;
        $period = $request->input('period', '7d');
        
        $startDate = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        // Calculate metrics
        $totalMessages = MessageLog::where('klien_id', $klienId)
            ->where('created_at', '>=', $startDate)
            ->count();

        $successMessages = MessageLog::where('klien_id', $klienId)
            ->where('created_at', '>=', $startDate)
            ->whereIn('status', [
                MessageLog::STATUS_SENT,
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
            ])
            ->count();

        $deliveredMessages = MessageLog::where('klien_id', $klienId)
            ->where('created_at', '>=', $startDate)
            ->whereIn('status', [
                MessageLog::STATUS_DELIVERED,
                MessageLog::STATUS_READ,
            ])
            ->count();

        $readMessages = MessageLog::where('klien_id', $klienId)
            ->where('created_at', '>=', $startDate)
            ->where('status', MessageLog::STATUS_READ)
            ->count();

        // Timing percentiles
        $deliveryTimes = MessageEvent::where('klien_id', $klienId)
            ->where('event_type', MessageEvent::EVENT_DELIVERED)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('delivery_time_seconds')
            ->pluck('delivery_time_seconds')
            ->sort()
            ->values();

        $p50 = $this->percentile($deliveryTimes, 50);
        $p95 = $this->percentile($deliveryTimes, 95);
        $p99 = $this->percentile($deliveryTimes, 99);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'start_date' => $startDate->toISOString(),
                'metrics' => [
                    'total_messages' => $totalMessages,
                    'success_rate' => $totalMessages > 0 ? round(($successMessages / $totalMessages) * 100, 2) : 0,
                    'delivery_rate' => $totalMessages > 0 ? round(($deliveredMessages / $totalMessages) * 100, 2) : 0,
                    'read_rate' => $totalMessages > 0 ? round(($readMessages / $totalMessages) * 100, 2) : 0,
                ],
                'delivery_time' => [
                    'p50_seconds' => $p50,
                    'p95_seconds' => $p95,
                    'p99_seconds' => $p99,
                ],
            ],
        ]);
    }

    // ==================== HELPERS ====================

    protected function formatEvent(MessageEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'event_timestamp' => $event->event_timestamp?->toISOString(),
            'status_before' => $event->status_before,
            'status_after' => $event->status_after,
            'status_changed' => $event->status_changed,
            'error_code' => $event->error_code,
            'error_message' => $event->error_message,
            'is_success' => $event->isSuccessEvent(),
            'process_result' => $event->process_result,
            'delivery_time_seconds' => $event->delivery_time_seconds,
            'read_time_seconds' => $event->read_time_seconds,
            'received_at' => $event->received_at?->toISOString(),
        ];
    }

    protected function buildTimeline(iterable $events): array
    {
        $timeline = [];
        
        foreach ($events as $event) {
            $timeline[] = [
                'timestamp' => $event['event_timestamp'] ?? null,
                'event' => $event['event_type'] ?? 'unknown',
                'icon' => $this->getEventIcon($event['event_type'] ?? ''),
                'label' => $this->getEventLabel($event['event_type'] ?? ''),
            ];
        }

        return $timeline;
    }

    protected function getEventIcon(string $eventType): string
    {
        return match ($eventType) {
            MessageEvent::EVENT_SENT => '📤',
            MessageEvent::EVENT_DELIVERED => '✅',
            MessageEvent::EVENT_READ => '👁️',
            MessageEvent::EVENT_FAILED => '❌',
            MessageEvent::EVENT_REJECTED => '🚫',
            MessageEvent::EVENT_EXPIRED => '⏰',
            default => '❓',
        };
    }

    protected function getEventLabel(string $eventType): string
    {
        return match ($eventType) {
            MessageEvent::EVENT_SENT => 'Terkirim ke Server',
            MessageEvent::EVENT_DELIVERED => 'Sampai ke HP',
            MessageEvent::EVENT_READ => 'Dibaca',
            MessageEvent::EVENT_FAILED => 'Gagal',
            MessageEvent::EVENT_REJECTED => 'Ditolak',
            MessageEvent::EVENT_EXPIRED => 'Expired',
            default => 'Unknown',
        };
    }

    protected function percentile($collection, int $percentile): float
    {
        if ($collection->isEmpty()) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * $collection->count()) - 1;
        $index = max(0, min($index, $collection->count() - 1));

        return round($collection[$index] ?? 0, 2);
    }
}
