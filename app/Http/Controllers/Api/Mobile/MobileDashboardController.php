<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Kontak;
use App\Models\TemplatePesan;
use App\Models\User;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappConnection;
use App\Models\WhatsappTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadMissing(['klien', 'currentPlan']);

        $klienId = $user->klien_id;
        $balance = (int) ($user->getWallet()?->saldo_tersedia ?? 0);

        $connection = null;
        $contactsTotal = 0;
        $campaignsActive = 0;
        $templatesActive = 0;

        if ($klienId) {
            $connection = WhatsappConnection::where('klien_id', $klienId)
                ->latest('id')
                ->first();

            $contactsTotal = Kontak::where('klien_id', $klienId)->count();

            $campaignsActive = WhatsappCampaign::where('klien_id', $klienId)
                ->whereIn('status', [
                    WhatsappCampaign::STATUS_RUNNING,
                    WhatsappCampaign::STATUS_SCHEDULED,
                    WhatsappCampaign::STATUS_PAUSED,
                ])
                ->count();

            $templatesActive =
                TemplatePesan::where('klien_id', $klienId)
                    ->where('status', TemplatePesan::STATUS_DISETUJUI)
                    ->count()
                +
                WhatsappTemplate::where('klien_id', $klienId)
                    ->where('status', WhatsappTemplate::STATUS_APPROVED)
                    ->count();
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'wallet' => [
                    'balance' => $balance,
                    'formatted_balance' => 'Rp ' . number_format($balance, 0, ',', '.'),
                    'status' => $this->resolveWalletStatus($balance),
                ],
                'whatsapp' => [
                    'connected' => $connection?->status === WhatsappConnection::STATUS_CONNECTED,
                    'phone_number' => $connection?->phone_number,
                    'business_name' => $connection?->business_name ?? $user->klien?->nama_perusahaan,
                    'quality_rating' => $connection?->quality_rating,
                    'status' => $connection?->status ?? WhatsappConnection::STATUS_DISCONNECTED,
                ],
                'stats' => [
                    'messages_today' => (int) ($user->messages_sent_daily ?? 0),
                    'campaigns_active' => $campaignsActive,
                    'templates_active' => $templatesActive,
                    'contacts_total' => $contactsTotal,
                ],
                'quick_actions' => [
                    'inbox',
                    'contacts',
                    'campaign_create',
                    'topup',
                ],
            ],
        ]);
    }

    private function resolveWalletStatus(int $balance): string
    {
        if ($balance <= 10000) {
            return 'habis';
        }

        if ($balance <= 50000) {
            return 'kritis';
        }

        return 'aman';
    }
}