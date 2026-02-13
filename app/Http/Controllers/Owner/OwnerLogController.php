<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerLogController extends Controller
{
    /**
     * Log overview
     */
    public function index()
    {
        // Stats
        // NOTE: webhook_logs doesn't have 'status' column. Use 'processed' and 'error_message' instead.
        $stats = [
            'activity_total' => ActivityLog::count(),
            'webhooks_today' => DB::table('webhook_logs')->whereDate('created_at', today())->count(),
            'webhooks_failed' => DB::table('webhook_logs')
                ->whereDate('created_at', today())
                ->where(function ($q) {
                    $q->where('processed', false)
                      ->orWhereNotNull('error_message');
                })
                ->count(),
            'messages_today' => DB::table('message_logs')->whereDate('created_at', today())->count(),
        ];

        // Recent activity logs
        $activityLogs = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        // Recent webhook logs
        $webhookLogs = DB::table('webhook_logs')
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->map(function ($log) {
                $log->created_at = \Carbon\Carbon::parse($log->created_at);
                return $log;
            });

        // Recent message logs
        $messageLogs = DB::table('message_logs')
            ->leftJoin('klien', 'message_logs.klien_id', '=', 'klien.id')
            ->select([
                'message_logs.*',
                'klien.nama_perusahaan',
                'klien.id as client_id',
            ])
            ->orderBy('message_logs.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                $log->client = $log->nama_perusahaan ? (object)[
                    'id' => $log->client_id,
                    'nama_perusahaan' => $log->nama_perusahaan
                ] : null;
                $log->created_at = \Carbon\Carbon::parse($log->created_at);
                return $log;
            });

        return view('owner.logs.index', compact('stats', 'activityLogs', 'webhookLogs', 'messageLogs'));
    }

    /**
     * Activity log with filters
     */
    public function activity(Request $request)
    {
        $query = ActivityLog::with('user')
            ->orderBy('created_at', 'desc');

        // Search filter
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by user
        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        // Filter by date range
        if ($from = $request->get('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate(50);

        // Get users for filter dropdown
        $users = \App\Models\User::select('id', 'name')->orderBy('name')->get();

        return view('owner.logs.activity', compact('logs', 'users'));
    }

    /**
     * Webhook logs
     */
    public function webhooks(Request $request)
    {
        // NOTE: webhook_logs is for payment gateway webhooks (Midtrans, Xendit)
        // It does NOT have connection_id - no relation to whatsapp_connections
        $query = DB::table('webhook_logs')
            ->select([
                'webhook_logs.*',
            ])
            ->orderBy('webhook_logs.created_at', 'desc');

        // Filter by gateway
        if ($gateway = $request->get('gateway')) {
            $query->where('webhook_logs.gateway', $gateway);
        }

        // Filter by event type
        if ($eventType = $request->get('event_type')) {
            $query->where('webhook_logs.event_type', $eventType);
        }

        // Filter by status (use 'processed' column instead of non-existent 'status')
        if ($status = $request->get('status')) {
            if ($status === 'success') {
                $query->where('webhook_logs.processed', true)
                      ->whereNull('webhook_logs.error_message');
            } elseif ($status === 'failed') {
                $query->where(function ($q) {
                    $q->where('webhook_logs.processed', false)
                      ->orWhereNotNull('webhook_logs.error_message');
                });
            }
        }

        // Filter by date
        if ($from = $request->get('from')) {
            $query->whereDate('webhook_logs.created_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('webhook_logs.created_at', '<=', $to);
        }

        // Filter by order_id or external_id
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('webhook_logs.order_id', 'like', "%{$search}%")
                  ->orWhere('webhook_logs.external_id', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(50)->through(function ($log) {
            $log->created_at = \Carbon\Carbon::parse($log->created_at);
            $log->payload = json_decode($log->payload ?? '{}');
            // Derive status from processed and error_message
            $log->status = (!empty($log->error_message) || !$log->processed) ? 'failed' : 'success';
            return $log;
        });

        // Stats - use 'processed' and 'error_message' instead of 'status'
        $stats = [
            'total' => DB::table('webhook_logs')->count(),
            'success' => DB::table('webhook_logs')
                ->where('processed', true)
                ->whereNull('error_message')
                ->count(),
            'failed' => DB::table('webhook_logs')
                ->where(function ($q) {
                    $q->where('processed', false)
                      ->orWhereNotNull('error_message');
                })
                ->count(),
        ];

        return view('owner.logs.webhooks', compact('logs', 'stats'));
    }

    /**
     * Message logs
     */
    public function messages(Request $request)
    {
        $query = DB::table('message_logs')
            ->leftJoin('klien', 'message_logs.klien_id', '=', 'klien.id')
            ->select([
                'message_logs.*',
                'klien.nama_perusahaan',
                'klien.id as client_id',
            ])
            ->orderBy('message_logs.created_at', 'desc');

        // Search filter
        if ($search = $request->get('search')) {
            $query->where('message_logs.recipient_number', 'like', "%{$search}%");
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('message_logs.status', $status);
        }

        // Filter by type
        if ($type = $request->get('type')) {
            $query->where('message_logs.message_type', $type);
        }

        // Filter by date
        if ($date = $request->get('date')) {
            $query->whereDate('message_logs.created_at', $date);
        }

        // Filter by klien
        if ($klienId = $request->get('klien_id')) {
            $query->where('message_logs.klien_id', $klienId);
        }

        $logs = $query->paginate(50)->through(function ($log) {
            $log->client = $log->nama_perusahaan ? (object)[
                'id' => $log->client_id,
                'nama_perusahaan' => $log->nama_perusahaan
            ] : null;
            $log->created_at = \Carbon\Carbon::parse($log->created_at);
            $log->delivered_at = $log->delivered_at ? \Carbon\Carbon::parse($log->delivered_at) : null;
            return $log;
        });

        // Stats
        $stats = [
            'total' => DB::table('message_logs')->count(),
            'delivered' => DB::table('message_logs')->where('status', 'delivered')->count(),
            'read' => DB::table('message_logs')->where('status', 'read')->count(),
            'failed' => DB::table('message_logs')->where('status', 'failed')->count(),
        ];

        return view('owner.logs.messages', compact('logs', 'stats'));
    }
}
