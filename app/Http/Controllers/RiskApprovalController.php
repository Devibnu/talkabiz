<?php

namespace App\Http\Controllers;

use App\Models\Klien;
use App\Services\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RiskApprovalController extends Controller
{
    protected $approvalService;

    public function __construct(ApprovalService $approvalService)
    {
        $this->middleware(['auth', 'role:owner,super_admin']);
        $this->approvalService = $approvalService;
    }

    /**
     * Display Risk Approval Panel
     */
    public function index(Request $request)
    {
        $status = $request->get('status', 'pending');
        
        // Get klien based on status filter
        $query = Klien::with(['user', 'businessType'])
            ->orderBy('created_at', 'desc');
        
        switch ($status) {
            case 'pending':
                $query->pending();
                break;
            case 'approved':
                $query->approved();
                break;
            case 'rejected':
                $query->rejected();
                break;
            case 'suspended':
                $query->suspended();
                break;
            default:
                $query->pending();
        }
        
        $klienList = $query->paginate(20);
        
        // Get statistics
        $stats = [
            'pending' => Klien::pending()->count(),
            'approved' => Klien::approved()->count(),
            'rejected' => Klien::rejected()->count(),
            'suspended' => Klien::suspended()->count(),
        ];
        
        // Get recent approval actions (last 10)
        $recentActions = DB::table('approval_logs')
            ->join('klien', 'approval_logs.klien_id', '=', 'klien.id')
            ->join('users', 'approval_logs.actor_id', '=', 'users.id')
            ->select(
                'approval_logs.*',
                'klien.nama_perusahaan',
                'users.name as actor_name'
            )
            ->orderBy('approval_logs.created_at', 'desc')
            ->limit(10)
            ->get();
        
        return view('risk-approval.index', compact('klienList', 'stats', 'recentActions', 'status'));
    }

    /**
     * Get klien details (AJAX)
     */
    public function show($id)
    {
        try {
            $klien = Klien::with(['user', 'businessType', 'approvalLogs.actor'])
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $klien->id,
                    'nama_perusahaan' => $klien->nama_perusahaan,
                    'email' => $klien->user->email ?? '-',
                    'phone' => $klien->user->phone ?? '-',
                    'business_type' => $klien->businessType->nama ?? '-',
                    'risk_level' => $klien->businessType->risk_level ?? '-',
                    'status' => $klien->status,
                    'approval_status' => $klien->approval_status,
                    'approval_status_label' => $klien->getApprovalStatusLabel(),
                    'approval_badge_color' => $klien->getApprovalBadgeColor(),
                    'created_at' => $klien->created_at->format('d M Y H:i'),
                    'approved_at' => $klien->approved_at ? $klien->approved_at->format('d M Y H:i') : null,
                    'approval_notes' => $klien->approval_notes,
                    'can_send_messages' => $klien->canSendMessages(),
                    'approval_history' => $klien->approvalLogs->map(function($log) {
                        return [
                            'action' => $log->action,
                            'status_from' => $log->status_from,
                            'status_to' => $log->status_to,
                            'actor' => $log->actor->name ?? 'System',
                            'reason' => $log->reason,
                            'created_at' => $log->created_at->format('d M Y H:i'),
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Approve klien
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $klien = Klien::findOrFail($id);
            $adminId = auth()->id();
            $reason = $request->input('notes', 'Approved by owner');

            $success = $this->approvalService->approve($klien, $adminId, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Klien berhasil disetujui'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyetujui klien'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error approving klien', [
                'klien_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject klien
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ], [
            'notes.required' => 'Alasan penolakan wajib diisi'
        ]);

        try {
            $klien = Klien::findOrFail($id);
            $adminId = auth()->id();
            $reason = $request->input('notes');

            $success = $this->approvalService->reject($klien, $adminId, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Klien berhasil ditolak'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal menolak klien'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error rejecting klien', [
                'klien_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suspend klien
     */
    public function suspend(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string|max:1000',
        ], [
            'notes.required' => 'Alasan suspend wajib diisi'
        ]);

        try {
            $klien = Klien::findOrFail($id);
            $adminId = auth()->id();
            $reason = $request->input('notes');

            $success = $this->approvalService->suspend($klien, $adminId, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Klien berhasil disuspend'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal suspend klien'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error suspending klien', [
                'klien_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivate klien
     */
    public function reactivate(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $klien = Klien::findOrFail($id);
            $adminId = auth()->id();
            $reason = $request->input('notes', 'Reactivated by owner');

            $success = $this->approvalService->reactivate($klien, $adminId, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Klien berhasil diaktifkan kembali'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengaktifkan klien'
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error reactivating klien', [
                'klien_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }
}
