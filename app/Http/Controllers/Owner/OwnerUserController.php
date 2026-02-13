<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OwnerUserController
 * 
 * Controller untuk aksi OWNER terhadap User.
 * Semua aksi di sini memerlukan role owner dan dicatat ke audit_logs.
 * 
 * SECURITY:
 * - Semua endpoint dilindungi middleware EnsureOwner
 * - Owner action tidak bisa dibatalkan oleh user
 * - Semua aksi dicatat ke audit_logs dan owner.log
 * 
 * @package App\Http\Controllers\Owner
 */
class OwnerUserController extends Controller
{
    /**
     * List all users with status info
     * NOTE: User status is derived from klien.status, NOT users.status
     */
    public function index(Request $request)
    {
        $query = User::with(['klien', 'currentPlan'])
            ->select(['id', 'name', 'email', 'role', 'campaign_send_enabled', 
                      'current_plan_id', 'klien_id', 'created_at', 'last_login_at']);
        
        // Filter by klien status (NOT users.status)
        if ($request->filled('status')) {
            $status = $request->status;
            $query->whereHas('klien', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }
        
        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        $users = $query->orderBy('created_at', 'desc')
            ->paginate(20);
        
        // Stats - count based on klien.status
        $stats = [
            'total' => User::count(),
            'active' => User::whereHas('klien', fn($q) => $q->where('status', 'aktif'))
                           ->orWhereDoesntHave('klien')
                           ->count(),
            'banned' => User::whereHas('klien', fn($q) => $q->where('status', 'banned'))->count(),
            'suspended' => User::whereHas('klien', fn($q) => $q->where('status', 'suspended'))->count(),
        ];
        
        return view('owner.users.index', compact('users', 'stats'));
    }

    /**
     * Show user detail
     */
    public function show(User $user)
    {
        $user->load(['klien', 'currentPlan']);
        
        // Get user's WhatsApp connections
        $whatsappConnections = WhatsappConnection::where('klien_id', $user->klien_id)->get();
        
        // Get recent audit logs for this user
        $auditLogs = AuditLog::where('entity_type', 'user')
            ->where('entity_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
        
        return view('owner.users.show', compact('user', 'whatsappConnections', 'auditLogs'));
    }

    /**
     * Ban User
     * 
     * POST /owner/user/{id}/ban
     * 
     * Actions:
     * 1. Set user.status = BANNED
     * 2. Set campaign_send_enabled = false
     * 3. Disconnect all WhatsApp connections
     * 4. Stop all active campaigns
     * 5. Log to audit_logs and owner.log
     */
    public function ban(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason');
        
        // Prevent banning other owners
        if ($user->role === 'owner' || $user->role === 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa ban user dengan role owner/super_admin',
            ], 403);
        }
        
        DB::beginTransaction();
        
        try {
            $oldValues = [
                'status' => $user->status,
                'campaign_send_enabled' => $user->campaign_send_enabled,
            ];
            
            // 1. Ban user
            $user->status = 'banned';
            $user->campaign_send_enabled = false;
            $user->save();
            
            $newValues = [
                'status' => 'banned',
                'campaign_send_enabled' => false,
            ];
            
            // 2. Disconnect all WhatsApp connections
            $disconnectedWa = 0;
            if ($user->klien_id) {
                $disconnectedWa = WhatsappConnection::where('klien_id', $user->klien_id)
                    ->whereIn('status', ['connected', 'pending'])
                    ->update([
                        'status' => 'banned',
                        'disconnected_at' => now(),
                        'error_reason' => 'User banned by owner: ' . $reason,
                    ]);
            }
            
            // 3. Stop all active campaigns
            $stoppedCampaigns = 0;
            if ($user->klien_id) {
                $stoppedCampaigns = Campaign::where('klien_id', $user->klien_id)
                    ->whereIn('status', ['pending', 'running', 'scheduled'])
                    ->update([
                        'status' => 'stopped',
                        'error_message' => 'User banned by owner',
                    ]);
            }
            
            // 4. Record to audit_logs
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'actor_user_agent' => $request->userAgent(),
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'klien_id' => $user->klien_id,
                'action' => 'ban_user',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'context' => [
                    'reason' => $reason,
                    'disconnected_whatsapp' => $disconnectedWa,
                    'stopped_campaigns' => $stoppedCampaigns,
                ],
                'description' => "User {$user->email} banned by owner",
                'status' => 'success',
                'data_classification' => AuditLog::CLASS_CONFIDENTIAL,
            ]);
            
            // 5. Log to owner.log
            Log::channel('owner')->warning('USER_BANNED', [
                'owner_id' => $owner->id,
                'owner_email' => $owner->email,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'reason' => $reason,
                'disconnected_whatsapp' => $disconnectedWa,
                'stopped_campaigns' => $stoppedCampaigns,
                'ip' => $request->ip(),
                'timestamp' => now()->toIso8601String(),
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "User {$user->email} berhasil di-ban",
                'data' => [
                    'disconnected_whatsapp' => $disconnectedWa,
                    'stopped_campaigns' => $stoppedCampaigns,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('owner')->error('USER_BAN_FAILED', [
                'owner_id' => $owner->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal ban user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unban User
     * 
     * POST /owner/user/{id}/unban
     */
    public function unban(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason', 'Unbanned by owner');
        
        if ($user->status !== 'banned') {
            return response()->json([
                'success' => false,
                'message' => 'User tidak dalam status banned',
            ], 400);
        }
        
        DB::beginTransaction();
        
        try {
            $oldValues = ['status' => $user->status];
            
            // Unban user
            $user->status = 'active';
            $user->campaign_send_enabled = true;
            $user->save();
            
            // Reset WhatsApp connections to pending (user must reconnect)
            if ($user->klien_id) {
                WhatsappConnection::where('klien_id', $user->klien_id)
                    ->where('status', 'banned')
                    ->update([
                        'status' => 'pending',
                        'error_reason' => null,
                    ]);
            }
            
            // Record to audit_logs
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'action' => 'unban_user',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => ['status' => 'active'],
                'context' => ['reason' => $reason],
                'status' => 'success',
            ]);
            
            Log::channel('owner')->info('USER_UNBANNED', [
                'owner_id' => $owner->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "User {$user->email} berhasil di-unban",
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal unban user: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suspend User (temporary)
     * 
     * POST /owner/user/{id}/suspend
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason');
        
        if ($user->role === 'owner' || $user->role === 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa suspend owner/super_admin',
            ], 403);
        }
        
        DB::beginTransaction();
        
        try {
            $oldValues = ['status' => $user->status];
            
            $user->status = 'suspended';
            $user->campaign_send_enabled = false;
            $user->save();
            
            // Record audit
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'action' => 'suspend_user',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => ['status' => 'suspended'],
                'context' => ['reason' => $reason],
                'status' => 'success',
            ]);
            
            Log::channel('owner')->warning('USER_SUSPENDED', [
                'owner_id' => $owner->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "User {$user->email} berhasil di-suspend",
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal suspend user: ' . $e->getMessage(),
            ], 500);
        }
    }
}
