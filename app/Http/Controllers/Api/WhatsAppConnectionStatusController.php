<?php

namespace App\Http\Controllers\Api;

use App\Enums\WhatsAppConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\WhatsappConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * WhatsAppConnectionStatusController
 * 
 * READ-ONLY API endpoint untuk UI polling status koneksi WhatsApp.
 * 
 * PENTING - SOURCE OF TRUTH:
 * - Endpoint ini HANYA MEMBACA status dari database/cache
 * - TIDAK PERNAH mengubah status
 * - Status CONNECTED hanya bisa di-set oleh webhook
 * 
 * @package App\Http\Controllers\Api
 */
class WhatsAppConnectionStatusController extends Controller
{
    /**
     * Get current connection status for authenticated klien
     * 
     * READ-ONLY - Tidak mengubah data apapun
     * 
     * GET /api/whatsapp/connection/status
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        $klien = $user->klien;
        
        if (!$klien) {
            return response()->json([
                'status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'status_label' => WhatsAppConnectionStatus::DISCONNECTED->label(),
                'status_color' => WhatsAppConnectionStatus::DISCONNECTED->color(),
                'source_of_truth' => 'webhook', // Informasi untuk frontend
            ]);
        }
        
        // Try cache first (set by webhook)
        $cacheKey = "whatsapp_connection_status:{$klien->id}";
        $cached = Cache::get($cacheKey);
        
        if ($cached) {
            $cached['source'] = 'cache';
            $cached['source_of_truth'] = 'webhook';
            return response()->json($cached);
        }
        
        // READ from database (no writes)
        $connection = WhatsappConnection::where('klien_id', $klien->id)
            ->select([
                'id', 'status', 'phone_number', 'display_name', 
                'quality_rating', 'connected_at', 'failed_at',
                'error_reason', 'webhook_last_update', 'updated_at'
            ])
            ->first();
        
        if (!$connection) {
            return response()->json([
                'status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'status_label' => WhatsAppConnectionStatus::DISCONNECTED->label(),
                'status_color' => WhatsAppConnectionStatus::DISCONNECTED->color(),
                'source' => 'database',
                'source_of_truth' => 'webhook',
            ]);
        }
        
        $statusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        
        $response = [
            'status' => $statusEnum->value,
            'status_label' => $statusEnum->label(),
            'status_color' => $statusEnum->color(),
            'status_icon' => $statusEnum->icon(),
            'can_send_messages' => $statusEnum->canSendMessages(),
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'phone_number' => $connection->phone_number,
            'display_name' => $connection->display_name,
            'quality_rating' => $connection->quality_rating,
            'error_reason' => $statusEnum === WhatsAppConnectionStatus::FAILED 
                ? $connection->error_reason 
                : null,
            'webhook_last_update' => $connection->webhook_last_update?->toIso8601String(),
            'updated_at' => $connection->updated_at->toIso8601String(),
            'source' => 'database',
            'source_of_truth' => 'webhook', // Status hanya bisa diubah oleh webhook
        ];
        
        // Cache for 30 seconds (read-only cache)
        Cache::put($cacheKey, $response, 30);
        
        return response()->json($response);
    }

    /**
     * Get connection status by klien ID (for owner dashboard)
     * 
     * READ-ONLY - Tidak mengubah data apapun
     * 
     * GET /api/whatsapp/connection/{klienId}/status
     */
    public function showByKlien(Request $request, int $klienId): JsonResponse
    {
        $user = $request->user();
        
        // Only owner/admin can view other klien's status
        if (!$user || !in_array($user->role, ['owner', 'super_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        // READ from database (no writes)
        $connection = WhatsappConnection::where('klien_id', $klienId)
            ->select([
                'id', 'klien_id', 'status', 'phone_number', 'display_name',
                'quality_rating', 'connected_at', 'failed_at', 'disconnected_at',
                'error_reason', 'webhook_last_update', 'updated_at'
            ])
            ->first();
        
        if (!$connection) {
            return response()->json([
                'status' => WhatsAppConnectionStatus::DISCONNECTED->value,
                'status_label' => WhatsAppConnectionStatus::DISCONNECTED->label(),
                'status_color' => WhatsAppConnectionStatus::DISCONNECTED->color(),
                'source_of_truth' => 'webhook',
            ]);
        }
        
        $statusEnum = WhatsAppConnectionStatus::fromString($connection->status);
        
        return response()->json([
            'connection_id' => $connection->id,
            'klien_id' => $connection->klien_id,
            'status' => $statusEnum->value,
            'status_label' => $statusEnum->label(),
            'status_color' => $statusEnum->color(),
            'status_icon' => $statusEnum->icon(),
            'can_send_messages' => $statusEnum->canSendMessages(),
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'disconnected_at' => $connection->disconnected_at?->toIso8601String(),
            'failed_at' => $connection->failed_at?->toIso8601String(),
            'phone_number' => $connection->phone_number,
            'display_name' => $connection->display_name,
            'quality_rating' => $connection->quality_rating,
            'error_reason' => $connection->error_reason,
            'webhook_last_update' => $connection->webhook_last_update?->toIso8601String(),
            'updated_at' => $connection->updated_at->toIso8601String(),
            'source_of_truth' => 'webhook',
        ]);
    }
}
