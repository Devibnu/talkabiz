<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RecipientComplaint;
use App\Models\Klien;
use App\Models\Kampanye;
use App\Models\User;
use App\Services\AbuseScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ComplaintMonitorController extends Controller
{
    protected $abuseScoringService;

    public function __construct(AbuseScoringService $abuseScoringService)
    {
        $this->middleware('auth');
        $this->middleware('role:owner,admin'); // Only owner/admin can access
        $this->abuseScoringService = $abuseScoringService;
    }

    /**
     * Display complaint monitor dashboard
     */
    public function index(Request $request)
    {
        // Build query with filters
        $query = RecipientComplaint::with(['klien', 'abuseEvent', 'processor'])
            ->orderBy('complaint_received_at', 'desc');

        // Filter by klien/user
        if ($request->filled('klien_id')) {
            $query->where('klien_id', $request->klien_id);
        }

        // Filter by recipient phone
        if ($request->filled('recipient_phone')) {
            $query->where('recipient_phone', 'LIKE', '%' . $request->recipient_phone . '%');
        }

        // Filter by complaint type
        if ($request->filled('complaint_type')) {
            $query->where('complaint_type', $request->complaint_type);
        }

        // Filter by severity
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        // Filter by status (processed/unprocessed)
        if ($request->filled('status')) {
            if ($request->status === 'processed') {
                $query->where('is_processed', true);
            } elseif ($request->status === 'unprocessed') {
                $query->where('is_processed', false);
            }
        }

        // Filter by provider
        if ($request->filled('provider_name')) {
            $query->where('provider_name', $request->provider_name);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('complaint_received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('complaint_received_at', '<=', $request->date_to);
        }

        // Search across multiple fields
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('recipient_phone', 'LIKE', "%{$search}%")
                  ->orWhere('recipient_name', 'LIKE', "%{$search}%")
                  ->orWhere('message_id', 'LIKE', "%{$search}%")
                  ->orWhere('complaint_reason', 'LIKE', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->get('per_page', 25);
        $complaints = $query->paginate($perPage)->withQueryString();

        // Statistics
        $stats = $this->getComplaintStatistics($request);

        // Get filter options
        $kliens = Klien::select('id', 'nama_perusahaan', 'email')
            ->orderBy('nama_perusahaan')
            ->get();

        $providers = RecipientComplaint::select('provider_name')
            ->whereNotNull('provider_name')
            ->distinct()
            ->pluck('provider_name');

        return view('complaint-monitor.index', compact(
            'complaints',
            'stats',
            'kliens',
            'providers'
        ));
    }

    /**
     * Get complaint statistics
     */
    protected function getComplaintStatistics(Request $request)
    {
        $query = RecipientComplaint::query();

        // Apply same filters as main query
        if ($request->filled('klien_id')) {
            $query->where('klien_id', $request->klien_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('complaint_received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('complaint_received_at', '<=', $request->date_to);
        }

        return [
            'total_complaints' => $query->count(),
            'total_unprocessed' => (clone $query)->where('is_processed', false)->count(),
            'total_critical' => (clone $query)->where('severity', 'critical')->count(),
            'total_high' => (clone $query)->where('severity', 'high')->count(),
            
            'by_type' => (clone $query)
                ->select('complaint_type', DB::raw('COUNT(*) as count'))
                ->groupBy('complaint_type')
                ->pluck('count', 'complaint_type')
                ->toArray(),
            
            'by_severity' => (clone $query)
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            
            'today_complaints' => (clone $query)
                ->whereDate('complaint_received_at', Carbon::today())
                ->count(),
            
            'this_week_complaints' => (clone $query)
                ->whereBetween('complaint_received_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])
                ->count(),
            
            'this_month_complaints' => (clone $query)
                ->whereMonth('complaint_received_at', Carbon::now()->month)
                ->whereYear('complaint_received_at', Carbon::now()->year)
                ->count(),
            
            'affected_kliens' => (clone $query)
                ->distinct('klien_id')
                ->count('klien_id'),
            
            'unique_recipients' => (clone $query)
                ->distinct('recipient_phone')
                ->count('recipient_phone'),
            
            'total_score_impact' => (clone $query)->sum('abuse_score_impact'),
            
            'top_kliens' => DB::table('recipient_complaints')
                ->select('klien_id', DB::raw('COUNT(*) as complaint_count'))
                ->when($request->filled('date_from'), function($q) use ($request) {
                    $q->whereDate('complaint_received_at', '>=', $request->date_from);
                })
                ->when($request->filled('date_to'), function($q) use ($request) {
                    $q->whereDate('complaint_received_at', '<=', $request->date_to);
                })
                ->groupBy('klien_id')
                ->orderByDesc('complaint_count')
                ->limit(5)
                ->get()
                ->map(function($item) {
                    $klien = Klien::find($item->klien_id);
                    return [
                        'klien_id' => $item->klien_id,
                        'klien_name' => $klien ? $klien->nama_perusahaan : 'Unknown',
                        'complaint_count' => $item->complaint_count,
                    ];
                }),
            
            'hourly_distribution' => (clone $query)
                ->whereDate('complaint_received_at', '>=', Carbon::now()->subDays(7))
                ->select(DB::raw('HOUR(complaint_received_at) as hour'), DB::raw('COUNT(*) as count'))
                ->groupBy('hour')
                ->orderBy('hour')
                ->pluck('count', 'hour')
                ->toArray(),
        ];
    }

    /**
     * Show complaint detail
     */
    public function show($id)
    {
        $complaint = RecipientComplaint::with(['klien', 'abuseEvent', 'processor'])
            ->findOrFail($id);

        // Get related complaints (same recipient or same klien)
        $relatedByRecipient = RecipientComplaint::where('recipient_phone', $complaint->recipient_phone)
            ->where('id', '!=', $id)
            ->orderBy('complaint_received_at', 'desc')
            ->limit(10)
            ->get();

        $relatedByKlien = RecipientComplaint::where('klien_id', $complaint->klien_id)
            ->where('id', '!=', $id)
            ->orderBy('complaint_received_at', 'desc')
            ->limit(10)
            ->get();

        // Get klien statistics
        $klienStats = null;
        if ($complaint->klien) {
            $klienStats = $this->abuseScoringService->getComplaintStats($complaint->klien_id, 30);
        }

        return view('complaint-monitor.show', compact(
            'complaint',
            'relatedByRecipient',
            'relatedByKlien',
            'klienStats'
        ));
    }

    /**
     * Suspend klien/user
     */
    public function suspendKlien(Request $request, $complaintId)
    {
        $request->validate([
            'suspension_days' => 'required|integer|min:1|max:365',
            'suspension_reason' => 'required|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $complaint = RecipientComplaint::findOrFail($complaintId);
            $klien = $complaint->klien;

            if (!$klien) {
                return response()->json([
                    'success' => false,
                    'message' => 'Klien not found'
                ], 404);
            }

            // Check if already suspended
            if ($klien->status === 'temp_suspended' && $klien->suspended_until > now()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Klien is already suspended until ' . $klien->suspended_until->format('Y-m-d H:i:s')
                ], 400);
            }

            // Suspend klien
            $suspendedUntil = now()->addDays($request->suspension_days);
            $klien->update([
                'status' => 'temp_suspended',
                'suspended_until' => $suspendedUntil,
            ]);

            // Mark complaint as processed
            $complaint->markAsProcessed(Auth::id(), 'klien_suspended');
            $complaint->update([
                'action_notes' => $request->suspension_reason,
            ]);

            // Create abuse event
            $this->abuseScoringService->recordEvent(
                $klien->id,
                'manual_suspension',
                100, // High score for manual suspension
                'Manual suspension by ' . Auth::user()->name . ' due to complaint #' . $complaint->id,
                [
                    'complaint_id' => $complaint->id,
                    'suspension_days' => $request->suspension_days,
                    'suspension_reason' => $request->suspension_reason,
                    'suspended_until' => $suspendedUntil->toIso8601String(),
                ]
            );

            // Log action
            Log::warning('Klien manually suspended via complaint monitor', [
                'klien_id' => $klien->id,
                'complaint_id' => $complaint->id,
                'suspended_by' => Auth::id(),
                'suspended_until' => $suspendedUntil,
                'reason' => $request->suspension_reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Klien {$klien->nama_perusahaan} suspended until {$suspendedUntil->format('Y-m-d H:i')}",
                'suspended_until' => $suspendedUntil->format('Y-m-d H:i:s'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to suspend klien', [
                'complaint_id' => $complaintId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend klien: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Block recipient phone number
     */
    public function blockRecipient(Request $request, $complaintId)
    {
        $request->validate([
            'block_reason' => 'required|string|max:1000',
            'block_globally' => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            $complaint = RecipientComplaint::findOrFail($complaintId);

            // TODO: Implement recipient blacklist table/mechanism
            // For now, we'll mark the complaint and create an abuse event

            $complaint->markAsProcessed(Auth::id(), 'recipient_blocked');
            $complaint->update([
                'action_notes' => $request->block_reason,
            ]);

            // Create abuse event
            if ($complaint->klien) {
                $this->abuseScoringService->recordEvent(
                    $complaint->klien_id,
                    'recipient_blocked',
                    50,
                    'Recipient ' . $complaint->recipient_phone . ' blocked by ' . Auth::user()->name,
                    [
                        'complaint_id' => $complaint->id,
                        'recipient_phone' => $complaint->recipient_phone,
                        'block_reason' => $request->block_reason,
                        'block_globally' => $request->get('block_globally', false),
                    ]
                );
            }

            // Log action
            Log::info('Recipient blocked via complaint monitor', [
                'complaint_id' => $complaint->id,
                'recipient_phone' => $complaint->recipient_phone,
                'blocked_by' => Auth::id(),
                'reason' => $request->block_reason,
                'global' => $request->get('block_globally', false),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Recipient {$complaint->recipient_phone} has been blocked",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to block recipient', [
                'complaint_id' => $complaintId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to block recipient: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark complaint as processed
     */
    public function markAsProcessed(Request $request, $complaintId)
    {
        $request->validate([
            'action_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $complaint = RecipientComplaint::findOrFail($complaintId);

            if ($complaint->is_processed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Complaint is already processed'
                ], 400);
            }

            $complaint->markAsProcessed(
                Auth::id(),
                'manually_reviewed'
            );

            if ($request->filled('action_notes')) {
                $complaint->update(['action_notes' => $request->action_notes]);
            }

            Log::info('Complaint marked as processed', [
                'complaint_id' => $complaint->id,
                'processed_by' => Auth::id(),
                'action_notes' => $request->action_notes,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Complaint marked as processed',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to mark complaint as processed', [
                'complaint_id' => $complaintId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process complaint: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dismiss complaint (false positive)
     */
    public function dismissComplaint(Request $request, $complaintId)
    {
        $request->validate([
            'dismiss_reason' => 'required|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            $complaint = RecipientComplaint::findOrFail($complaintId);

            $complaint->markAsProcessed(Auth::id(), 'dismissed_false_positive');
            $complaint->update([
                'action_notes' => 'DISMISSED: ' . $request->dismiss_reason,
            ]);

            // If klien has abuse score, consider reducing it
            if ($complaint->klien && $complaint->abuse_score_impact > 0) {
                $score = $this->abuseScoringService->getOrCreateScore($complaint->klien_id);
                
                // Reduce score by half of the complaint's impact (punishment for false positive reporting)
                $reductionAmount = $complaint->abuse_score_impact / 2;
                $newScore = max(0, $score->score - $reductionAmount);
                
                $score->update(['score' => $newScore]);

                Log::info('Abuse score reduced due to dismissed complaint', [
                    'klien_id' => $complaint->klien_id,
                    'complaint_id' => $complaint->id,
                    'reduction_amount' => $reductionAmount,
                    'new_score' => $newScore,
                ]);
            }

            Log::info('Complaint dismissed as false positive', [
                'complaint_id' => $complaint->id,
                'dismissed_by' => Auth::id(),
                'reason' => $request->dismiss_reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Complaint dismissed successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to dismiss complaint', [
                'complaint_id' => $complaintId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dismiss complaint: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics via AJAX
     */
    public function getStatistics(Request $request)
    {
        $stats = $this->getComplaintStatistics($request);
        return response()->json($stats);
    }

    /**
     * Export complaints to CSV
     */
    public function export(Request $request)
    {
        // Build query with same filters as index
        $query = RecipientComplaint::with(['klien', 'abuseEvent', 'processor'])
            ->orderBy('complaint_received_at', 'desc');

        // Apply all filters (copy from index method)
        if ($request->filled('klien_id')) {
            $query->where('klien_id', $request->klien_id);
        }
        if ($request->filled('recipient_phone')) {
            $query->where('recipient_phone', 'LIKE', '%' . $request->recipient_phone . '%');
        }
        if ($request->filled('complaint_type')) {
            $query->where('complaint_type', $request->complaint_type);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('status')) {
            if ($request->status === 'processed') {
                $query->where('is_processed', true);
            } elseif ($request->status === 'unprocessed') {
                $query->where('is_processed', false);
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('complaint_received_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('complaint_received_at', '<=', $request->date_to);
        }

        $complaints = $query->limit(5000)->get(); // Limit for performance

        $filename = 'complaints_export_' . now()->format('Y-m-d_His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($complaints) {
            $file = fopen('php://output', 'w');
            
            // CSV Headers
            fputcsv($file, [
                'ID',
                'Klien',
                'Recipient Phone',
                'Complaint Type',
                'Severity',
                'Source',
                'Provider',
                'Message ID',
                'Reason',
                'Score Impact',
                'Status',
                'Action Taken',
                'Processed By',
                'Received At',
                'Processed At',
            ]);

            // CSV Rows
            foreach ($complaints as $complaint) {
                fputcsv($file, [
                    $complaint->id,
                    $complaint->klien ? $complaint->klien->nama_perusahaan : 'N/A',
                    $complaint->recipient_phone,
                    $complaint->getTypeDisplayName(),
                    ucfirst($complaint->severity),
                    ucfirst(str_replace('_', ' ', $complaint->complaint_source)),
                    $complaint->provider_name ?? 'N/A',
                    $complaint->message_id ?? 'N/A',
                    substr($complaint->complaint_reason ?? '', 0, 100),
                    $complaint->abuse_score_impact,
                    $complaint->is_processed ? 'Processed' : 'Unprocessed',
                    $complaint->action_taken ?? 'N/A',
                    $complaint->processor ? $complaint->processor->name : 'N/A',
                    $complaint->complaint_received_at->format('Y-m-d H:i:s'),
                    $complaint->processed_at ? $complaint->processed_at->format('Y-m-d H:i:s') : 'N/A',
                ]);
            }

            fclose($file);
        };

        Log::info('Complaints exported', [
            'exported_by' => Auth::id(),
            'count' => $complaints->count(),
            'filters' => $request->except('_token'),
        ]);

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk actions
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:mark_processed,dismiss,suspend_klien',
            'complaint_ids' => 'required|array|min:1',
            'complaint_ids.*' => 'exists:recipient_complaints,id',
            'reason' => 'required_if:action,suspend_klien,dismiss|string|max:1000',
            'suspension_days' => 'required_if:action,suspend_klien|integer|min:1|max:365',
        ]);

        DB::beginTransaction();
        try {
            $complaints = RecipientComplaint::whereIn('id', $request->complaint_ids)->get();
            $successCount = 0;
            $errors = [];

            foreach ($complaints as $complaint) {
                try {
                    switch ($request->action) {
                        case 'mark_processed':
                            if (!$complaint->is_processed) {
                                $complaint->markAsProcessed(Auth::id(), 'bulk_processed');
                                $successCount++;
                            }
                            break;

                        case 'dismiss':
                            if (!$complaint->is_processed) {
                                $complaint->markAsProcessed(Auth::id(), 'bulk_dismissed');
                                $complaint->update(['action_notes' => 'BULK DISMISSED: ' . $request->reason]);
                                $successCount++;
                            }
                            break;

                        case 'suspend_klien':
                            if ($complaint->klien && $complaint->klien->status !== 'temp_suspended') {
                                $suspendedUntil = now()->addDays($request->suspension_days);
                                $complaint->klien->update([
                                    'status' => 'temp_suspended',
                                    'suspended_until' => $suspendedUntil,
                                ]);
                                $complaint->markAsProcessed(Auth::id(), 'bulk_suspended');
                                $complaint->update(['action_notes' => 'BULK SUSPENDED: ' . $request->reason]);
                                $successCount++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Complaint #{$complaint->id}: {$e->getMessage()}";
                }
            }

            Log::info('Bulk action performed on complaints', [
                'action' => $request->action,
                'performed_by' => Auth::id(),
                'total_complaints' => count($request->complaint_ids),
                'success_count' => $successCount,
                'errors' => $errors,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$successCount} complaints",
                'errors' => $errors,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk action failed', [
                'action' => $request->action,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bulk action failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
