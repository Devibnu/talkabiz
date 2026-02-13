<?php

namespace App\Http\Controllers;

use App\Models\AbuseScore;
use App\Models\AbuseEvent;
use App\Models\Klien;
use App\Services\AbuseScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AbuseMonitorController extends Controller
{
    protected $abuseService;

    public function __construct(AbuseScoringService $abuseService)
    {
        $this->middleware(['auth', 'role:owner,super_admin']);
        $this->abuseService = $abuseService;
    }

    /**
     * Display Abuse Monitor Panel
     */
    public function index(Request $request)
    {
        $level = $request->get('level', 'all');
        $search = $request->get('search');
        
        // Build query
        $query = AbuseScore::with(['klien.user', 'klien.businessType'])
            ->orderBy('current_score', 'desc');
        
        // Filter by level
        if ($level !== 'all') {
            $query->byLevel($level);
        }
        
        // Search by klien name
        if ($search) {
            $query->whereHas('klien', function($q) use ($search) {
                $q->where('nama_perusahaan', 'like', "%{$search}%");
            });
        }
        
        $abuseScores = $query->paginate(20);
        
        // Get statistics
        $stats = $this->abuseService->getStatistics();
        
        // Get recent high-risk events
        $recentHighRiskEvents = AbuseEvent::with(['klien'])
            ->whereIn('severity', ['high', 'critical'])
            ->orderBy('detected_at', 'desc')
            ->limit(10)
            ->get();
        
        return view('abuse-monitor.index', compact(
            'abuseScores',
            'stats',
            'recentHighRiskEvents',
            'level',
            'search'
        ));
    }

    /**
     * Get klien abuse details (AJAX)
     */
    public function show($klienId)
    {
        try {
            $klien = Klien::with(['user', 'businessType'])->findOrFail($klienId);
            $abuseScore = $this->abuseService->getScore($klienId);
            
            if (!$abuseScore) {
                return response()->json([
                    'success' => false,
                    'message' => 'No abuse score found'
                ], 404);
            }
            
            // Get recent events
            $recentEvents = AbuseEvent::where('klien_id', $klienId)
                ->orderBy('detected_at', 'desc')
                ->limit(20)
                ->get()
                ->map(function($event) {
                    return [
                        'id' => $event->id,
                        'signal_type' => $event->signal_type,
                        'severity' => $event->severity,
                        'abuse_points' => $event->abuse_points,
                        'description' => $event->description,
                        'detected_at' => $event->detected_at->format('Y-m-d H:i:s'),
                        'evidence' => $event->evidence,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'klien' => [
                        'id' => $klien->id,
                        'nama_perusahaan' => $klien->nama_perusahaan,
                        'email' => $klien->user->email ?? '-',
                        'business_type' => $klien->businessType->name ?? '-',
                        'status' => $klien->status,
                    ],
                    'abuse_score' => [
                        'current_score' => $abuseScore->current_score,
                        'abuse_level' => $abuseScore->abuse_level,
                        'policy_action' => $abuseScore->policy_action,
                        'is_suspended' => $abuseScore->is_suspended,
                        'last_event_at' => $abuseScore->last_event_at ? $abuseScore->last_event_at->format('Y-m-d H:i:s') : null,
                        'days_since_last_event' => $abuseScore->daysSinceLastEvent(),
                        'level_label' => $abuseScore->getLevelLabel(),
                        'action_label' => $abuseScore->getActionLabel(),
                        'badge_color' => $abuseScore->getBadgeColor(),
                    ],
                    'recent_events' => $recentEvents,
                    'event_count' => AbuseEvent::where('klien_id', $klienId)->count(),
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get abuse details', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load abuse details'
            ], 500);
        }
    }

    /**
     * Reset abuse score
     */
    public function resetScore(Request $request, $klienId)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $klien = Klien::findOrFail($klienId);
            $reason = $request->input('reason');
            $adminId = auth()->id();
            $adminName = auth()->user()->name;

            DB::beginTransaction();

            // Reset score
            $success = $this->abuseService->resetScore($klienId, $reason);

            if (!$success) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reset score'
                ], 400);
            }

            // Log admin action
            Log::warning('Abuse score reset by admin', [
                'klien_id' => $klienId,
                'klien_name' => $klien->nama_perusahaan,
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'reason' => $reason,
                'ip' => $request->ip(),
            ]);

            // Create manual event log
            AbuseEvent::create([
                'klien_id' => $klienId,
                'rule_code' => 'manual_reset',
                'signal_type' => 'manual_review',
                'severity' => 'low',
                'abuse_points' => 0,
                'evidence' => [
                    'action' => 'reset_score',
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'ip' => $request->ip(),
                ],
                'description' => "Score reset by admin: {$reason}",
                'detection_source' => 'manual',
                'auto_action' => false,
                'admin_reviewed' => true,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'detected_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Abuse score has been reset successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reset abuse score', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Suspend klien due to abuse
     */
    public function suspendKlien(Request $request, $klienId)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $klien = Klien::findOrFail($klienId);
            $reason = $request->input('reason');
            $adminId = auth()->id();
            $adminName = auth()->user()->name;

            DB::beginTransaction();

            // Update klien status
            $klien->update([
                'status' => 'suspended',
            ]);

            // Update abuse score
            $abuseScore = $this->abuseService->getOrCreateScore($klienId);
            $abuseScore->update([
                'is_suspended' => true,
                'policy_action' => AbuseScore::ACTION_SUSPEND,
                'notes' => "Suspended by admin: {$reason}",
            ]);

            // Log admin action
            Log::critical('Klien suspended due to abuse', [
                'klien_id' => $klienId,
                'klien_name' => $klien->nama_perusahaan,
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'reason' => $reason,
                'ip' => $request->ip(),
            ]);

            // Create manual event log
            AbuseEvent::create([
                'klien_id' => $klienId,
                'rule_code' => 'manual_suspend',
                'signal_type' => 'manual_review',
                'severity' => 'critical',
                'abuse_points' => 50,
                'evidence' => [
                    'action' => 'manual_suspend',
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'ip' => $request->ip(),
                ],
                'description' => "Manually suspended by admin: {$reason}",
                'action_taken' => 'suspended',
                'detection_source' => 'manual',
                'auto_action' => false,
                'admin_reviewed' => true,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'detected_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Klien has been suspended successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to suspend klien', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve/unsuspend klien
     */
    public function approveKlien(Request $request, $klienId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $klien = Klien::findOrFail($klienId);
            $notes = $request->input('notes', 'Approved by admin');
            $adminId = auth()->id();
            $adminName = auth()->user()->name;

            DB::beginTransaction();

            // Update klien status
            $klien->update([
                'status' => 'aktif',
            ]);

            // Update abuse score
            $abuseScore = $this->abuseService->getOrCreateScore($klienId);
            
            // Determine policy based on current score
            $newLevel = $this->abuseService->determineLevel($abuseScore->current_score);
            $newPolicyAction = config("abuse.policy_actions.{$newLevel}", AbuseScore::ACTION_NONE);
            
            $abuseScore->update([
                'is_suspended' => false,
                'abuse_level' => $newLevel,
                'policy_action' => $newPolicyAction,
                'notes' => "Approved by admin: {$notes}",
            ]);

            // Log admin action
            Log::info('Klien approved/unsuspended', [
                'klien_id' => $klienId,
                'klien_name' => $klien->nama_perusahaan,
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'notes' => $notes,
                'ip' => $request->ip(),
            ]);

            // Create manual event log
            AbuseEvent::create([
                'klien_id' => $klienId,
                'rule_code' => 'manual_approve',
                'signal_type' => 'manual_review',
                'severity' => 'low',
                'abuse_points' => -10, // Reduce score slightly
                'evidence' => [
                    'action' => 'manual_approve',
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'ip' => $request->ip(),
                ],
                'description' => "Manually approved by admin: {$notes}",
                'action_taken' => 'approved',
                'detection_source' => 'manual',
                'auto_action' => false,
                'admin_reviewed' => true,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'detected_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Klien has been approved successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve klien', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get event details (AJAX)
     */
    public function getEvent($eventId)
    {
        try {
            $event = AbuseEvent::with('klien')->findOrFail($eventId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $event->id,
                    'klien_name' => $event->klien->nama_perusahaan ?? 'Unknown',
                    'signal_type' => $event->signal_type,
                    'severity' => $event->severity,
                    'abuse_points' => $event->abuse_points,
                    'description' => $event->description,
                    'evidence' => $event->evidence,
                    'detection_source' => $event->detection_source,
                    'action_taken' => $event->action_taken,
                    'detected_at' => $event->detected_at->format('Y-m-d H:i:s'),
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found'
            ], 404);
        }
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics()
    {
        try {
            $stats = $this->abuseService->getStatistics();
            
            // Additional stats
            $stats['auto_suspended'] = AbuseScore::suspended()->count();
            $stats['manual_reviews_needed'] = AbuseEvent::unreviewed()
                ->whereIn('severity', ['high', 'critical'])
                ->count();
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load statistics'
            ], 500);
        }
    }
}
