<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Klien;
use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerClientController extends Controller
{
    /**
     * List all clients (Klien/UMKM)
     */
    public function index(Request $request)
    {
        $query = Klien::with(['user', 'dompet', 'whatsappConnections'])
            ->withCount(['whatsappConnections' => function($q) {
                // Safe count - returns 0 if no records
            }]);

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_perusahaan', 'like', "%{$search}%")
                  ->orWhere('nama_pemilik', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('no_whatsapp', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by WA connection status
        if ($waStatus = $request->get('wa_status')) {
            $query->whereHas('whatsappConnections', function ($q) use ($waStatus) {
                $q->where('status', $waStatus);
            });
        }

        // Filter by plan
        if ($planId = $request->get('plan_id')) {
            $query->whereHas('user', function ($q) use ($planId) {
                $q->where('current_plan_id', $planId);
            });
        }

        $clients = $query->orderBy('created_at', 'desc')->paginate(20);

        // Stats for filter badges
        $stats = [
            'total' => Klien::count(),
            'aktif' => Klien::where('status', 'aktif')->count(),
            'pending' => Klien::where('status', 'pending')->count(),
            'suspend' => Klien::where('status', 'suspend')->count(),
        ];

        return view('owner.clients.index', compact('clients', 'stats'));
    }

    /**
     * Show client detail
     */
    public function show(Klien $klien)
    {
        $klien->load([
            'user.currentPlan',
            'dompet',
            'whatsappConnections' => fn($q) => $q->orderBy('created_at', 'desc'),
        ]);

        // Alias for view consistency (Owner Panel uses $client)
        $client = $klien;

        // Activity log for this client
        $activityLogs = ActivityLog::where(function ($q) use ($klien) {
            $q->where('subject_type', Klien::class)
              ->where('subject_id', $klien->id);
        })->orWhere(function ($q) use ($klien) {
            $q->where('user_id', $klien->user?->id);
        })
        ->orderBy('created_at', 'desc')
        ->limit(50)
        ->get();

        // Message stats
        $messageStats = DB::table('message_logs')
            ->where('klien_id', $klien->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as `read`,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return view('owner.clients.show', compact('client', 'activityLogs', 'messageStats'));
    }

    /**
     * Approve client (dari pending → aktif)
     */
    public function approve(Request $request, Klien $klien)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($klien, $request) {
            $oldStatus = $klien->status;

            $klien->update([
                'status' => 'aktif',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Update user if exists
            if ($klien->user) {
                $klien->user->update([
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                ]);
            }

            // Log the action
            ActivityLog::log(
                'client_approved',
                "Client approved by owner. Notes: " . ($request->notes ?? '-'),
                $klien,
                $klien->user?->id,
                auth()->id(),
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'aktif',
                    'notes' => $request->notes,
                ]
            );
        });

        return back()->with('success', "Client {$klien->nama_perusahaan} berhasil diapprove.");
    }

    /**
     * Suspend client
     */
    public function suspend(Request $request, Klien $klien)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($klien, $request) {
            $oldStatus = $klien->status;

            $klien->update([
                'status' => 'suspend',
                'suspended_at' => now(),
                'suspended_by' => auth()->id(),
                'suspension_reason' => $request->reason,
            ]);

            // Disconnect all WhatsApp connections
            $klien->whatsappConnections()->update([
                'status' => WhatsappConnection::STATUS_DISCONNECTED,
            ]);

            // Log the action
            ActivityLog::log(
                'client_suspended',
                "Client suspended. Reason: {$request->reason}",
                $klien,
                $klien->user?->id,
                auth()->id(),
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'suspend',
                    'reason' => $request->reason,
                ]
            );
        });

        return back()->with('success', "Client {$klien->nama_perusahaan} berhasil disuspend.");
    }

    /**
     * Activate client (dari suspend → aktif)
     */
    public function activate(Request $request, Klien $klien)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($klien, $request) {
            $oldStatus = $klien->status;

            $klien->update([
                'status' => 'aktif',
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
            ]);

            // Log the action
            ActivityLog::log(
                'client_activated',
                "Client reactivated by owner. Notes: " . ($request->notes ?? '-'),
                $klien,
                $klien->user?->id,
                auth()->id(),
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'aktif',
                    'notes' => $request->notes,
                ]
            );
        });

        return back()->with('success', "Client {$klien->nama_perusahaan} berhasil diaktifkan kembali.");
    }

    /**
     * Reset client quota
     */
    public function resetQuota(Request $request, Klien $klien)
    {
        $request->validate([
            'quota_type' => 'required|in:daily,monthly,all',
        ]);

        $user = $klien->user;

        if (!$user) {
            return back()->with('error', 'Client tidak memiliki user account.');
        }

        $updateData = [];
        $quotaType = $request->quota_type;

        if ($quotaType === 'daily' || $quotaType === 'all') {
            $updateData['messages_sent_daily'] = 0;
            $updateData['daily_reset_date'] = today();
        }

        if ($quotaType === 'monthly' || $quotaType === 'all') {
            $updateData['messages_sent_monthly'] = 0;
            $updateData['monthly_reset_date'] = today();
        }

        $user->update($updateData);

        // Log the action
        ActivityLog::log(
            'quota_reset',
            "Quota reset by owner. Type: {$quotaType}",
            $klien,
            $user->id,
            auth()->id(),
            ['quota_type' => $quotaType]
        );

        return back()->with('success', "Quota berhasil direset.");
    }
}
