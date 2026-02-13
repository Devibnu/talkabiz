<?php

namespace App\Http\Controllers\Owner;

use App\Enums\WhatsAppConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * OwnerWhatsAppConnectionController
 * 
 * Control panel Owner untuk mengelola semua WhatsApp connections.
 * 
 * FITUR:
 * - List semua whatsapp_connections
 * - Force disconnect (owner only, not CONNECTED)
 * - View webhook history
 * - Read-only re-sync status (refresh dari Gupshup)
 * 
 * PENTING:
 * - Status CONNECTED hanya bisa di-set oleh webhook
 * - Owner hanya bisa force disconnect (DISCONNECTED)
 * - Tidak ada cara manual set CONNECTED
 * 
 * @package App\Http\Controllers\Owner
 */
class OwnerWhatsAppConnectionController extends Controller
{
    /**
     * List semua WhatsApp connections
     * 
     * GET /owner/whatsapp-connections
     */
    public function index(Request $request): View
    {
        $query = WhatsappConnection::with(['klien:id,nama_perusahaan,email'])
            ->select([
                'id', 'klien_id', 'phone_number', 'display_name', 'business_name',
                'status', 'quality_rating', 'gupshup_app_id',
                'connected_at', 'disconnected_at', 'failed_at',
                'error_reason', 'webhook_last_update', 'created_at', 'updated_at'
            ]);
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Search by phone or business name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%")
                  ->orWhereHas('klien', function($q) use ($search) {
                      $q->where('nama_perusahaan', 'like', "%{$search}%");
                  });
            });
        }
        
        // Sort
        $sortBy = $request->get('sort', 'updated_at');
        $sortDir = $request->get('dir', 'desc');
        $query->orderBy($sortBy, $sortDir);
        
        $connections = $query->paginate(20);
        
        // Get status counts
        $statusCounts = WhatsappConnection::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        return view('owner.whatsapp-connections.index', [
            'connections' => $connections,
            'statusCounts' => $statusCounts,
            'statuses' => WhatsAppConnectionStatus::options(),
            'filters' => $request->only(['status', 'search', 'sort', 'dir']),
        ]);
    }

    /**
     * Show single connection detail
     * 
     * GET /owner/whatsapp-connections/{id}
     */
    public function show(int $id): View
    {
        $connection = WhatsappConnection::with(['klien'])
            ->findOrFail($id);
        
        // Get recent webhook events for this connection
        $webhookHistory = WebhookEvent::where('whatsapp_connection_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        $statusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        
        return view('owner.whatsapp-connections.show', [
            'connection' => $connection,
            'webhookHistory' => $webhookHistory,
            'statusEnum' => $statusEnum,
        ]);
    }

    /**
     * Force disconnect a connection (Owner only)
     * 
     * POST /owner/whatsapp-connections/{id}/force-disconnect
     * 
     * RULES:
     * - Cannot disconnect if already DISCONNECTED
     * - Cannot set to CONNECTED (webhook only)
     * - Logs action to security log
     */
    public function forceDisconnect(Request $request, int $id): JsonResponse
    {
        $connection = WhatsappConnection::findOrFail($id);
        $user = $request->user();
        
        $currentStatus = WhatsAppConnectionStatus::fromString($connection->status);
        
        // Already disconnected
        if ($currentStatus === WhatsAppConnectionStatus::DISCONNECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Koneksi sudah dalam status DISCONNECTED',
            ], 400);
        }
        
        // Validate transition
        if (!$currentStatus->canTransitionTo(WhatsAppConnectionStatus::DISCONNECTED, isWebhook: false)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mengubah status dari ' . $currentStatus->label(),
            ], 400);
        }
        
        $oldStatus = $connection->status;
        $reason = $request->input('reason', 'Force disconnected by owner');
        
        DB::beginTransaction();
        
        try {
            // Update connection
            $connection->update([
                'status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'disconnected_at' => now(),
                'error_reason' => $reason,
            ]);
            
            // Clear cache
            Cache::forget("whatsapp_connection_status:{$connection->klien_id}");
            
            // Log to security
            Log::channel('security')->info('OWNER_FORCE_DISCONNECT', [
                'action' => 'force_disconnect',
                'connection_id' => $id,
                'klien_id' => $connection->klien_id,
                'phone' => $this->maskPhone($connection->phone_number),
                'old_status' => $oldStatus,
                'new_status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'reason' => $reason,
                'performed_by' => [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'ip' => $request->ip(),
            ]);
            
            // Log to webhook for audit trail
            Log::channel('webhook')->info('AUDIT: status_transition', [
                'connection_id' => $id,
                'klien_id' => $connection->klien_id,
                'from_status' => $oldStatus,
                'to_status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'source' => 'owner_panel', // NOT webhook
                'performed_by' => $user->email,
                'reason' => $reason,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Koneksi berhasil diputus',
                'old_status' => $oldStatus,
                'new_status' => WhatsAppConnectionStatus::DISCONNECTED->value,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::channel('security')->error('OWNER_FORCE_DISCONNECT_FAILED', [
                'connection_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memutus koneksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset connection to PENDING (for retry)
     * 
     * POST /owner/whatsapp-connections/{id}/reset-to-pending
     * 
     * RULES:
     * - Only from FAILED or DISCONNECTED
     * - Cannot reset CONNECTED (must disconnect first)
     */
    public function resetToPending(Request $request, int $id): JsonResponse
    {
        $connection = WhatsappConnection::findOrFail($id);
        $user = $request->user();
        
        $currentStatus = WhatsAppConnectionStatus::fromString($connection->status);
        
        // Can only reset from FAILED or DISCONNECTED
        if (!in_array($currentStatus, [WhatsAppConnectionStatus::FAILED, WhatsAppConnectionStatus::DISCONNECTED])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya bisa reset dari status FAILED atau DISCONNECTED. Status saat ini: ' . $currentStatus->label(),
            ], 400);
        }
        
        $oldStatus = $connection->status;
        
        DB::beginTransaction();
        
        try {
            $connection->update([
                'status' => WhatsAppConnectionStatus::PENDING->value,
                'failed_at' => null,
                'disconnected_at' => null,
                'error_reason' => null,
            ]);
            
            Cache::forget("whatsapp_connection_status:{$connection->klien_id}");
            
            Log::channel('security')->info('OWNER_RESET_TO_PENDING', [
                'connection_id' => $id,
                'klien_id' => $connection->klien_id,
                'old_status' => $oldStatus,
                'new_status' => WhatsAppConnectionStatus::PENDING->value,
                'performed_by' => $user->email,
                'ip' => $request->ip(),
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Status berhasil di-reset ke PENDING. Menunggu verifikasi dari Gupshup.',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal reset status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View webhook history for a connection
     * 
     * GET /owner/whatsapp-connections/{id}/webhook-history
     */
    public function webhookHistory(int $id): View
    {
        $connection = WhatsappConnection::findOrFail($id);
        
        $webhookEvents = WebhookEvent::where('whatsapp_connection_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        
        return view('owner.whatsapp-connections.webhook-history', [
            'connection' => $connection,
            'webhookEvents' => $webhookEvents,
        ]);
    }

    /**
     * API endpoint for webhook history (JSON)
     * 
     * GET /api/owner/whatsapp-connections/{id}/webhook-history
     */
    public function webhookHistoryApi(int $id): JsonResponse
    {
        $connection = WhatsappConnection::findOrFail($id);
        
        $webhookEvents = WebhookEvent::where('whatsapp_connection_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(function($event) {
                return [
                    'id' => $event->id,
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                    'old_status' => $event->old_status,
                    'new_status' => $event->new_status,
                    'status_changed' => $event->status_changed,
                    'result' => $event->result,
                    'result_reason' => $event->result_reason,
                    'source_ip' => $event->source_ip,
                    'signature_valid' => $event->signature_valid,
                    'created_at' => $event->created_at->toIso8601String(),
                ];
            });
        
        return response()->json([
            'connection_id' => $id,
            'phone' => $this->maskPhone($connection->phone_number),
            'current_status' => $connection->status,
            'webhook_events' => $webhookEvents,
            'total_events' => WebhookEvent::where('whatsapp_connection_id', $id)->count(),
        ]);
    }

    /**
     * Read-only re-sync status (just refresh cache)
     * 
     * POST /owner/whatsapp-connections/{id}/refresh-status
     * 
     * NOTE: This does NOT change the status.
     * It only clears cache and returns fresh data from database.
     */
    public function refreshStatus(int $id): JsonResponse
    {
        $connection = WhatsappConnection::findOrFail($id);
        
        // Clear cache to force fresh read
        Cache::forget("whatsapp_connection_status:{$connection->klien_id}");
        
        // Refresh model from database
        $connection->refresh();
        
        $statusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        
        return response()->json([
            'success' => true,
            'message' => 'Status berhasil di-refresh dari database',
            'connection' => [
                'id' => $connection->id,
                'status' => $statusEnum->value,
                'status_label' => $statusEnum->label(),
                'status_color' => $statusEnum->color(),
                'connected_at' => $connection->connected_at?->toIso8601String(),
                'webhook_last_update' => $connection->webhook_last_update?->toIso8601String(),
                'updated_at' => $connection->updated_at->toIso8601String(),
            ],
            'source_of_truth' => 'webhook',
            'note' => 'Status hanya bisa diubah oleh webhook dari Gupshup',
        ]);
    }

    /**
     * Mask phone for privacy
     */
    private function maskPhone(?string $phone): string
    {
        if (empty($phone) || strlen($phone) < 6) {
            return '***';
        }
        return substr($phone, 0, 4) . '****' . substr($phone, -4);
    }
}
