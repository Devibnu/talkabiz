<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppController - Manage WhatsApp Connection
 * 
 * Controller ini menangani:
 * - Menampilkan halaman koneksi WhatsApp
 * - Generate QR code untuk koneksi
 * - Check status koneksi realtime
 * - Disconnect nomor WA
 * 
 * PENTING: User HARUS menghubungkan nomor sendiri.
 * Tidak ada auto-connect atau bypass.
 */
class WhatsAppController extends Controller
{
    protected WhatsAppConnectionService $connectionService;

    public function __construct(WhatsAppConnectionService $connectionService)
    {
        $this->connectionService = $connectionService;
    }

    /**
     * Halaman utama WhatsApp connection.
     */
    public function index()
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return redirect()->route('dashboard')
                ->with('error', 'Akun Anda belum memiliki profil bisnis. Silakan hubungi admin.');
        }

        $connectionStatus = $this->connectionService->getConnectionStatus($klien);

        return view('whatsapp.index', [
            'klien' => $klien,
            'connectionStatus' => $connectionStatus,
        ]);
    }

    /**
     * Initiate connection - Generate QR code.
     */
    public function connect(Request $request)
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak memiliki profil bisnis.',
            ], 400);
        }

        // Check if already connected
        if ($klien->wa_terhubung) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor WhatsApp sudah terhubung. Disconnect terlebih dahulu untuk menghubungkan nomor baru.',
            ], 400);
        }

        try {
            $qrData = $this->connectionService->initiateConnection($klien);

            Log::info('WhatsApp connection initiated', [
                'klien_id' => $klien->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'qr_code' => $qrData['qr_code'],
                'session_id' => $qrData['session_id'],
                'expires_at' => $qrData['expires_at'],
                'message' => 'Scan QR code ini dengan WhatsApp di HP Anda.',
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp connection failed', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal generate QR code. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Check connection status (polling/realtime).
     */
    public function status(Request $request)
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'connected' => false,
                'message' => 'Akun tidak memiliki profil bisnis.',
            ], 400);
        }

        $status = $this->connectionService->getConnectionStatus($klien);

        // If just connected, mark onboarding step
        if ($status['connected'] && $request->has('check_onboarding')) {
            $this->markWhatsAppConnectedOnboarding($user);
        }

        return response()->json([
            'success' => true,
            'connected' => $status['connected'],
            'phone_display' => $status['phone_display'],
            'connected_at' => $status['connected_at'],
            'status_label' => $status['status_label'],
        ]);
    }

    /**
     * Disconnect WhatsApp number.
     */
    public function disconnect(Request $request)
    {
        $user = Auth::user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak memiliki profil bisnis.',
            ], 400);
        }

        if (!$klien->wa_terhubung) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada nomor WhatsApp yang terhubung.',
            ], 400);
        }

        try {
            $this->connectionService->disconnect($klien);

            Log::info('WhatsApp disconnected', [
                'klien_id' => $klien->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp berhasil di-disconnect. Anda dapat menghubungkan nomor baru.',
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp disconnect failed', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal disconnect. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Confirm connection from webhook callback.
     * Called by WhatsApp provider when QR scanned successfully.
     */
    public function confirmConnection(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'phone_number_id' => 'required|string',
            'business_account_id' => 'required|string',
            'access_token' => 'required|string',
        ]);

        try {
            $result = $this->connectionService->confirmConnection(
                $request->session_id,
                $request->phone_number_id,
                $request->business_account_id,
                $request->access_token
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'WhatsApp berhasil terhubung!',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        } catch (\Exception $e) {
            Log::error('WhatsApp confirm connection failed', [
                'session_id' => $request->session_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal konfirmasi koneksi.',
            ], 500);
        }
    }

    /**
     * Check session status for QR code polling.
     */
    public function checkSessionStatus(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $status = $this->connectionService->checkSessionStatus($request->session_id);

        return response()->json($status);
    }

    /**
     * Mark WhatsApp connected in onboarding steps.
     */
    protected function markWhatsAppConnectedOnboarding($user): void
    {
        if (!$user->getOnboardingStep('wa_connected')) {
            $user->completeOnboardingStep('wa_connected');
            
            Log::info('Onboarding step wa_connected completed', [
                'user_id' => $user->id,
            ]);
        }
    }
}
