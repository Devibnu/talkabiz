<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappConnection;
use App\Services\GupshupService;
use App\Services\WaBlastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OwnerWhatsAppController extends Controller
{
    protected GupshupService $gupshupService;
    protected WaBlastService $waBlastService;

    public function __construct(GupshupService $gupshupService, WaBlastService $waBlastService)
    {
        $this->gupshupService = $gupshupService;
        $this->waBlastService = $waBlastService;
    }

    /**
     * List all WhatsApp connections
     */
    public function index(Request $request)
    {
        $query = WhatsappConnection::with(['klien'])
            ->orderBy('created_at', 'desc');

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%")
                  ->orWhereHas('klien', fn($kq) => $kq->where('nama_perusahaan', 'like', "%{$search}%"));
            });
        }

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by provider
        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }

        $connections = $query->paginate(20);

        // Stats
        $stats = [
            'total' => WhatsappConnection::count(),
            'connected' => WhatsappConnection::where('status', WhatsappConnection::STATUS_CONNECTED)->count(),
            'pending' => WhatsappConnection::where('status', WhatsappConnection::STATUS_PENDING)->count(),
            'failed' => WhatsappConnection::where('status', WhatsappConnection::STATUS_FAILED)->count(),
            'disconnected' => WhatsappConnection::where('status', WhatsappConnection::STATUS_DISCONNECTED)->count(),
        ];

        return view('owner.whatsapp.index', compact('connections', 'stats'));
    }

    /**
     * Show connection detail
     */
    public function show(WhatsappConnection $connection)
    {
        $connection->load(['klien.user']);

        // Activity log for this connection
        $activityLogs = ActivityLog::where('subject_type', WhatsappConnection::class)
            ->where('subject_id', $connection->id)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        // Recent webhooks for this connection
        $recentWebhooks = \DB::table('webhook_logs')
            ->where('payload', 'like', "%{$connection->phone_number}%")
            ->orWhere('payload', 'like', "%{$connection->gupshup_app_id}%")
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return view('owner.whatsapp.show', compact('connection', 'activityLogs', 'recentWebhooks'));
    }

    /**
     * Force status to CONNECTED (manual override)
     */
    public function forceConnect(Request $request, WhatsappConnection $connection)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $oldStatus = $connection->status;

        $connection->update([
            'status' => WhatsappConnection::STATUS_CONNECTED,
            'connected_at' => now(),
            'metadata' => array_merge($connection->metadata ?? [], [
                'force_connected_by' => auth()->id(),
                'force_connected_at' => now()->toIso8601String(),
                'force_connected_reason' => $request->reason,
            ]),
        ]);

        // Log
        ActivityLog::log(
            'wa_force_connected',
            "WhatsApp force connected by owner. Reason: " . ($request->reason ?? '-'),
            $connection,
            $connection->klien?->user?->id,
            auth()->id(),
            [
                'old_status' => $oldStatus,
                'new_status' => WhatsappConnection::STATUS_CONNECTED,
                'reason' => $request->reason,
            ]
        );

        return back()->with('success', "Status WhatsApp berhasil diubah ke CONNECTED.");
    }

    /**
     * Force status to FAILED (manual override)
     */
    public function forceFail(Request $request, WhatsappConnection $connection)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $oldStatus = $connection->status;

        $connection->markAsFailed($request->reason);

        // Add owner info to metadata
        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], [
                'force_failed_by' => auth()->id(),
                'force_failed_at' => now()->toIso8601String(),
            ]),
        ]);

        // Log
        ActivityLog::log(
            'wa_force_failed',
            "WhatsApp force failed by owner. Reason: {$request->reason}",
            $connection,
            $connection->klien?->user?->id,
            auth()->id(),
            [
                'old_status' => $oldStatus,
                'new_status' => WhatsappConnection::STATUS_FAILED,
                'reason' => $request->reason,
            ]
        );

        return back()->with('success', "Status WhatsApp berhasil diubah ke FAILED.");
    }

    /**
     * Force status to PENDING (reset for re-verification)
     */
    public function forcePending(Request $request, WhatsappConnection $connection)
    {
        $oldStatus = $connection->status;

        $connection->markAsPending();

        // Add owner info to metadata
        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], [
                'force_pending_by' => auth()->id(),
                'force_pending_at' => now()->toIso8601String(),
            ]),
        ]);

        // Log
        ActivityLog::log(
            'wa_force_pending',
            "WhatsApp reset to pending by owner for re-verification",
            $connection,
            $connection->klien?->user?->id,
            auth()->id(),
            [
                'old_status' => $oldStatus,
                'new_status' => WhatsappConnection::STATUS_PENDING,
            ]
        );

        return back()->with('success', "Status WhatsApp berhasil direset ke PENDING.");
    }

    /**
     * Disconnect WhatsApp
     */
    public function disconnect(Request $request, WhatsappConnection $connection)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $oldStatus = $connection->status;

        $connection->markAsDisconnected();

        // Add owner info to metadata
        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], [
                'disconnected_by' => auth()->id(),
                'disconnected_at' => now()->toIso8601String(),
                'disconnection_reason' => $request->reason,
            ]),
        ]);

        // Log
        ActivityLog::log(
            'wa_disconnected',
            "WhatsApp disconnected by owner. Reason: {$request->reason}",
            $connection,
            $connection->klien?->user?->id,
            auth()->id(),
            [
                'old_status' => $oldStatus,
                'new_status' => WhatsappConnection::STATUS_DISCONNECTED,
                'reason' => $request->reason,
            ]
        );

        return back()->with('success', "WhatsApp berhasil di-disconnect.");
    }

    /**
     * Re-trigger verification (send to Gupshup again)
     */
    public function reVerify(Request $request, WhatsappConnection $connection)
    {
        // Guard: Klien ID must exist
        abort_if(!$connection->klien_id, 400, 'Klien ID missing - cannot re-verify');

        try {
            // Reset to pending first
            $connection->markAsPending();

            // Try to re-register with Gupshup (requires 3 parameters)
            $result = $this->gupshupService->registerPhoneNumber(
                $connection->phone_number,
                $connection->business_name,
                $connection->klien_id
            );

            if ($result['success']) {
                $connection->update([
                    'gupshup_app_id' => $result['app_id'] ?? $connection->gupshup_app_id,
                    'metadata' => array_merge($connection->metadata ?? [], [
                        're_verify_triggered_by' => auth()->id(),
                        're_verify_triggered_at' => now()->toIso8601String(),
                        're_verify_response' => $result,
                    ]),
                ]);

                // Log
                ActivityLog::log(
                    'wa_re_verify_triggered',
                    "WhatsApp re-verification triggered by owner",
                    $connection,
                    $connection->klien?->user?->id,
                    auth()->id(),
                    ['gupshup_response' => $result]
                );

                return back()->with('success', "Re-verification berhasil di-trigger. Tunggu webhook dari Gupshup.");
            } else {
                return back()->with('error', "Re-verification gagal: " . ($result['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            Log::error('Owner re-verify WhatsApp failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', "Re-verification error: " . $e->getMessage());
        }
    }

    /**
     * Force Disconnect WhatsApp
     * 
     * POST /owner/whatsapp/{id}/force-disconnect
     * 
     * Actions:
     * 1. Update status to FORCE_DISCONNECTED
     * 2. Set reason = "owner_action"
     * 3. Call Gupshup API to suspend/disconnect
     * 4. Log to audit_logs and owner.log
     */
    public function forceDisconnect(Request $request, WhatsappConnection $connection): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason');
        
        DB::beginTransaction();
        
        try {
            $oldValues = [
                'status' => $connection->status,
                'error_reason' => $connection->error_reason,
            ];
            
            // 1. Update connection status
            $connection->status = 'force_disconnected';
            $connection->disconnected_at = now();
            $connection->error_reason = 'owner_action: ' . $reason;
            $connection->metadata = array_merge($connection->metadata ?? [], [
                'force_disconnected_by' => $owner->id,
                'force_disconnected_at' => now()->toIso8601String(),
                'force_disconnected_reason' => $reason,
            ]);
            $connection->save();
            
            // 2. Try to call Gupshup API to suspend (optional, may fail)
            $gupshupResult = null;
            try {
                if ($connection->gupshup_app_id) {
                    $gupshupResult = $this->gupshupService->suspendNumber($connection->phone_number);
                }
            } catch (\Exception $e) {
                Log::channel('owner')->warning('Gupshup suspend API failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
                $gupshupResult = ['error' => $e->getMessage()];
            }
            
            // 2.5. Stop all active campaigns
            $stoppedCampaigns = 0;
            if ($connection->klien_id) {
                $stoppedCampaigns = $this->waBlastService->stopAllCampaignsByOwner(
                    $connection->klien_id,
                    $owner->id,
                    'whatsapp_force_disconnected: ' . $reason
                );
            }
            
            // 3. Record to audit_logs
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'actor_user_agent' => $request->userAgent(),
                'entity_type' => 'whatsapp',
                'entity_id' => $connection->id,
                'klien_id' => $connection->klien_id,
                'action' => 'force_disconnect',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => [
                    'status' => 'force_disconnected',
                    'error_reason' => 'owner_action: ' . $reason,
                ],
                'context' => [
                    'reason' => $reason,
                    'gupshup_result' => $gupshupResult,
                    'phone_number' => $connection->phone_number,
                ],
                'description' => "WhatsApp {$connection->phone_number} force disconnected by owner",
                'status' => 'success',
                'data_classification' => AuditLog::CLASS_CONFIDENTIAL,
            ]);
            
            // 4. Log to owner.log
            Log::channel('owner')->warning('WHATSAPP_FORCE_DISCONNECTED', [
                'owner_id' => $owner->id,
                'owner_email' => $owner->email,
                'connection_id' => $connection->id,
                'phone_number' => $connection->phone_number,
                'klien_id' => $connection->klien_id,
                'reason' => $reason,
                'gupshup_result' => $gupshupResult,
                'stopped_campaigns' => $stoppedCampaigns,
                'ip' => $request->ip(),
                'timestamp' => now()->toIso8601String(),
            ]);
            
            // Also log to ActivityLog for backward compatibility
            ActivityLog::log(
                'wa_force_disconnected',
                "WhatsApp force disconnected by owner. Reason: {$reason}",
                $connection,
                $connection->klien?->user?->id,
                $owner->id,
                ['reason' => $reason, 'stopped_campaigns' => $stoppedCampaigns]
            );
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "WhatsApp {$connection->phone_number} berhasil di-force disconnect",
                'data' => [
                    'stopped_campaigns' => $stoppedCampaigns,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('owner')->error('WHATSAPP_FORCE_DISCONNECT_FAILED', [
                'owner_id' => $owner->id,
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal force disconnect: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ban WhatsApp Number
     * 
     * POST /owner/whatsapp/{id}/ban
     * 
     * Actions:
     * 1. Update whatsapp_connections.status = BANNED
     * 2. Update user.status = SUSPENDED
     * 3. User tidak bisa reconnect
     * 4. Stop semua campaign
     * 5. Log to audit_logs and owner.log
     */
    public function banWhatsapp(Request $request, WhatsappConnection $connection): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
            'suspend_user' => 'boolean',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason');
        $suspendUser = $request->boolean('suspend_user', true);
        
        DB::beginTransaction();
        
        try {
            $oldValues = [
                'connection_status' => $connection->status,
            ];
            
            // 1. Ban WhatsApp connection
            $connection->status = 'banned';
            $connection->disconnected_at = now();
            $connection->error_reason = 'Banned by owner: ' . $reason;
            $connection->metadata = array_merge($connection->metadata ?? [], [
                'banned_by' => $owner->id,
                'banned_at' => now()->toIso8601String(),
                'banned_reason' => $reason,
            ]);
            $connection->save();
            
            $newValues = [
                'connection_status' => 'banned',
            ];
            
            // 2. Optionally suspend the user
            $userSuspended = false;
            $user = null;
            if ($suspendUser && $connection->klien_id) {
                $user = User::where('klien_id', $connection->klien_id)->first();
                if ($user && !in_array($user->role, ['owner', 'super_admin'])) {
                    $oldValues['user_status'] = $user->status;
                    $user->status = 'suspended';
                    $user->campaign_send_enabled = false;
                    $user->save();
                    $newValues['user_status'] = 'suspended';
                    $userSuspended = true;
                }
            }
            
            // 3. Stop all active campaigns (using WaBlastService)
            $stoppedCampaigns = 0;
            if ($connection->klien_id) {
                // Stop WhatsApp campaigns via WaBlastService
                $stoppedCampaigns = $this->waBlastService->stopAllCampaignsByOwner(
                    $connection->klien_id,
                    $owner->id,
                    'whatsapp_banned: ' . $reason
                );
                
                // Also stop old Campaign model (if exists)
                Campaign::where('klien_id', $connection->klien_id)
                    ->whereIn('status', ['pending', 'running', 'scheduled'])
                    ->update([
                        'status' => 'stopped',
                        'error_message' => 'WhatsApp banned by owner',
                    ]);
            }
            
            // 4. Record to audit_logs
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'actor_user_agent' => $request->userAgent(),
                'entity_type' => 'whatsapp',
                'entity_id' => $connection->id,
                'klien_id' => $connection->klien_id,
                'action' => 'ban_whatsapp',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'context' => [
                    'reason' => $reason,
                    'phone_number' => $connection->phone_number,
                    'user_suspended' => $userSuspended,
                    'stopped_campaigns' => $stoppedCampaigns,
                ],
                'description' => "WhatsApp {$connection->phone_number} banned by owner",
                'status' => 'success',
                'data_classification' => AuditLog::CLASS_CONFIDENTIAL,
            ]);
            
            // 5. Log to owner.log
            Log::channel('owner')->warning('WHATSAPP_BANNED', [
                'owner_id' => $owner->id,
                'owner_email' => $owner->email,
                'connection_id' => $connection->id,
                'phone_number' => $connection->phone_number,
                'klien_id' => $connection->klien_id,
                'reason' => $reason,
                'user_suspended' => $userSuspended,
                'user_id' => $user?->id,
                'stopped_campaigns' => $stoppedCampaigns,
                'ip' => $request->ip(),
                'timestamp' => now()->toIso8601String(),
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "WhatsApp {$connection->phone_number} berhasil di-ban",
                'data' => [
                    'user_suspended' => $userSuspended,
                    'stopped_campaigns' => $stoppedCampaigns,
                ],
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('owner')->error('WHATSAPP_BAN_FAILED', [
                'owner_id' => $owner->id,
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal ban WhatsApp: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unban WhatsApp Number
     * 
     * POST /owner/whatsapp/{id}/unban
     */
    public function unbanWhatsapp(Request $request, WhatsappConnection $connection): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);
        
        $owner = $request->user();
        $reason = $request->input('reason', 'Unbanned by owner');
        
        if ($connection->status !== 'banned') {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp tidak dalam status banned',
            ], 400);
        }
        
        DB::beginTransaction();
        
        try {
            $oldValues = ['status' => $connection->status];
            
            // Reset to pending (user must reconnect)
            $connection->status = 'pending';
            $connection->error_reason = null;
            $connection->metadata = array_merge($connection->metadata ?? [], [
                'unbanned_by' => $owner->id,
                'unbanned_at' => now()->toIso8601String(),
                'unbanned_reason' => $reason,
            ]);
            $connection->save();
            
            // Record audit
            AuditLog::create([
                'actor_type' => AuditLog::ACTOR_ADMIN,
                'actor_id' => $owner->id,
                'actor_email' => $owner->email,
                'actor_ip' => $request->ip(),
                'entity_type' => 'whatsapp',
                'entity_id' => $connection->id,
                'action' => 'unban_whatsapp',
                'action_category' => AuditLog::CATEGORY_TRUST_SAFETY,
                'old_values' => $oldValues,
                'new_values' => ['status' => 'pending'],
                'context' => ['reason' => $reason],
                'status' => 'success',
            ]);
            
            Log::channel('owner')->info('WHATSAPP_UNBANNED', [
                'owner_id' => $owner->id,
                'connection_id' => $connection->id,
                'phone_number' => $connection->phone_number,
                'reason' => $reason,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "WhatsApp {$connection->phone_number} berhasil di-unban. User perlu reconnect.",
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal unban WhatsApp: ' . $e->getMessage(),
            ], 500);
        }
    }
}
