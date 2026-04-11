<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappTemplate;
use App\Models\Kontak;
use App\Services\PlanLimitService;
use App\Services\RevenueGuardService;
use App\Services\Message\MessageDispatchService;
use App\Services\Message\MessageDispatchRequest;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\Subscription\PlanLimitExceededException;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MobileCampaignController extends Controller
{
    public function __construct(
        protected MessageDispatchService $messageDispatch,
    ) {}

    /**
     * List campaigns with optional search & status filter
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $klien = $user->klien;

        if (!$klien) {
            return response()->json(['success' => false, 'message' => 'Klien tidak ditemukan'], 404);
        }

        $query = WhatsappCampaign::where('klien_id', $klien->id)
            ->with('template:id,name')
            ->latest();

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $campaigns = $query->paginate(20);

        $items = $campaigns->map(fn($c) => $this->formatCampaign($c));

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Campaign summary stats
     */
    public function stats(Request $request): JsonResponse
    {
        $klien = $request->user()->klien;

        if (!$klien) {
            return response()->json(['success' => false, 'message' => 'Klien tidak ditemukan'], 404);
        }

        $base = WhatsappCampaign::where('klien_id', $klien->id);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', 'draft')->count(),
                'scheduled' => (clone $base)->where('status', 'scheduled')->count(),
                'running' => (clone $base)->where('status', 'running')->count(),
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    /**
     * Show single campaign detail
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)
            ->with(['template:id,name,language,category', 'recipients'])
            ->findOrFail($id);

        $recipientStats = [
            'pending' => $campaign->recipients->where('status', 'pending')->count(),
            'sent' => $campaign->recipients->where('status', 'sent')->count(),
            'delivered' => $campaign->recipients->where('status', 'delivered')->count(),
            'read' => $campaign->recipients->where('status', 'read')->count(),
            'failed' => $campaign->recipients->where('status', 'failed')->count(),
        ];

        $detail = $this->formatCampaign($campaign);
        $detail['recipient_stats'] = $recipientStats;
        $detail['audience_filter'] = $campaign->audience_filter;
        $detail['template_variables'] = $campaign->template_variables;
        $detail['template'] = $campaign->template ? [
            'id' => $campaign->template->id,
            'name' => $campaign->template->name,
            'language' => $campaign->template->language ?? 'id',
            'category' => $campaign->template->category ?? '-',
        ] : null;

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Create a new campaign
     */
    public function store(Request $request): JsonResponse
    {
        $klien = $request->user()->klien;

        if (!$klien) {
            return response()->json(['success' => false, 'message' => 'Klien tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'template_id' => [
                'required',
                Rule::exists('whatsapp_templates', 'id')->where('klien_id', $klien->id),
            ],
            'audience' => 'required|string|in:all,tag,contacts',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => [
                'integer',
                Rule::exists('kontak', 'id')->where('klien_id', $klien->id),
            ],
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'template_variables' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        // Build audience
        $audienceQuery = Kontak::where('klien_id', $klien->id);
        $filter = [];

        if ($request->audience === 'tag' && !empty($request->tags)) {
            foreach ($request->tags as $tag) {
                $audienceQuery->whereJsonContains('tags', $tag);
            }
            $filter = ['tags' => $request->tags];
        } elseif ($request->audience === 'contacts') {
            $contactIds = collect($request->input('contact_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($contactIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pilih minimal satu kontak untuk audience ini.',
                ], 422);
            }

            $audienceQuery->whereIn('id', $contactIds);
            $filter = ['contact_ids' => $contactIds];
        }

        $totalRecipients = $audienceQuery->count();

        if ($totalRecipients === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada kontak yang ditemukan untuk audience ini.',
            ], 422);
        }

        // Enforce plan limits
        try {
            $planLimitService = app(PlanLimitService::class);
            $planLimitService->enforceCampaignLimit($request->user());
            $planLimitService->enforceRecipientLimit($request->user(), $totalRecipients);
        } catch (PlanLimitExceededException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getUserMessage(),
            ], 403);
        }

        $estimatedCost = $totalRecipients * WhatsappCampaign::COST_PER_MESSAGE;

        $campaign = WhatsappCampaign::create([
            'klien_id' => $klien->id,
            'template_id' => $request->template_id,
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->scheduled_at ? WhatsappCampaign::STATUS_SCHEDULED : WhatsappCampaign::STATUS_DRAFT,
            'audience_filter' => $filter,
            'template_variables' => $request->template_variables,
            'scheduled_at' => $request->scheduled_at,
            'total_recipients' => $totalRecipients,
            'estimated_cost' => $estimatedCost,
            'rate_limit_per_second' => WhatsappCampaign::DEFAULT_RATE_LIMIT,
        ]);

        // Create recipient records
        $contacts = $audienceQuery->get();
        foreach ($contacts as $contact) {
            WhatsappCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'phone_number' => $contact->no_telepon,
                'status' => WhatsappCampaignRecipient::STATUS_PENDING,
            ]);
        }

        Log::info('Mobile: Campaign created', [
            'campaign_id' => $campaign->id,
            'klien_id' => $klien->id,
            'recipients' => $totalRecipients,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Kampanye berhasil dibuat dengan {$totalRecipients} penerima.",
            'data' => $this->formatCampaign($campaign->fresh(['template'])),
        ], 201);
    }

    /**
     * Start / send a campaign
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)->findOrFail($id);

        if (!$campaign->canStart()) {
            return response()->json([
                'success' => false,
                'message' => 'Kampanye tidak bisa dimulai dari status saat ini.',
            ], 422);
        }

        try {
            $revenueGuard = app(RevenueGuardService::class);
            $userId = $request->user()->id;
            $totalRecipients = $campaign->total_recipients;

            $result = $revenueGuard->chargeAndExecute(
                userId: $userId,
                recipientCount: $totalRecipients,
                operationName: "Campaign: {$campaign->name}",
                callback: function () use ($campaign, $request) {
                    $campaign->start();

                    $pendingRecipients = $campaign->recipients()
                        ->where('status', WhatsappCampaignRecipient::STATUS_PENDING)
                        ->get();

                    foreach ($pendingRecipients as $recipient) {
                        $recipient->markAsQueued();

                        try {
                            $dispatchRequest = new MessageDispatchRequest(
                                userId: $request->user()->id,
                                phoneNumber: $recipient->phone_number,
                                templateId: $campaign->template_id,
                                variables: $campaign->template_variables ?? [],
                                campaignId: $campaign->id,
                            );

                            $this->messageDispatch->dispatch($dispatchRequest);
                            $recipient->markAsSent();
                            $campaign->increment('sent_count');
                        } catch (Exception $e) {
                            $recipient->markAsFailed($e->getCode(), $e->getMessage());
                            $campaign->increment('failed_count');
                        }
                    }

                    if ($campaign->recipients()->where('status', 'pending')->count() === 0) {
                        $campaign->complete();
                    }

                    return $campaign->fresh();
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'Kampanye sedang dikirim.',
                'data' => $this->formatCampaign($result),
            ]);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo tidak cukup untuk mengirim kampanye ini. Silakan top-up terlebih dahulu.',
            ], 402);
        } catch (Exception $e) {
            Log::error('Mobile: Campaign start failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memulai kampanye: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pause a running campaign
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)->findOrFail($id);

        if (!$campaign->canPause()) {
            return response()->json([
                'success' => false,
                'message' => 'Kampanye tidak bisa di-pause.',
            ], 422);
        }

        $campaign->pause();

        return response()->json([
            'success' => true,
            'message' => 'Kampanye dipause.',
            'data' => $this->formatCampaign($campaign->fresh()),
        ]);
    }

    /**
     * Resume a paused campaign
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)->findOrFail($id);

        if ($campaign->status !== WhatsappCampaign::STATUS_PAUSED) {
            return response()->json([
                'success' => false,
                'message' => 'Kampanye tidak dalam status pause.',
            ], 422);
        }

        $campaign->resume();

        return response()->json([
            'success' => true,
            'message' => 'Kampanye dilanjutkan.',
            'data' => $this->formatCampaign($campaign->fresh()),
        ]);
    }

    /**
     * Cancel a campaign
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)->findOrFail($id);

        if (!$campaign->canCancel()) {
            return response()->json([
                'success' => false,
                'message' => 'Kampanye tidak bisa dibatalkan.',
            ], 422);
        }

        $campaign->update(['status' => WhatsappCampaign::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'message' => 'Kampanye dibatalkan.',
            'data' => $this->formatCampaign($campaign->fresh()),
        ]);
    }

    /**
     * Delete a draft campaign
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $klien = $request->user()->klien;
        $campaign = WhatsappCampaign::where('klien_id', $klien->id)->findOrFail($id);

        if (!in_array($campaign->status, [WhatsappCampaign::STATUS_DRAFT, WhatsappCampaign::STATUS_CANCELLED])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya kampanye draft atau dibatalkan yang bisa dihapus.',
            ], 422);
        }

        $campaign->recipients()->delete();
        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kampanye dihapus.',
        ]);
    }

    /**
     * Estimate cost before creating campaign
     */
    public function estimateCost(Request $request): JsonResponse
    {
        $klien = $request->user()->klien;

        if (!$klien) {
            return response()->json(['success' => false, 'message' => 'Klien tidak ditemukan'], 404);
        }

        $request->validate([
            'template_id' => [
                'required',
                Rule::exists('whatsapp_templates', 'id')->where('klien_id', $klien->id),
            ],
            'audience' => 'required|string|in:all,tag,contacts',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => [
                'integer',
                Rule::exists('kontak', 'id')->where('klien_id', $klien->id),
            ],
            'tags' => 'nullable|array',
        ]);

        $audienceQuery = Kontak::where('klien_id', $klien->id);

        if ($request->audience === 'tag' && !empty($request->tags)) {
            foreach ($request->tags as $tag) {
                $audienceQuery->whereJsonContains('tags', $tag);
            }
        } elseif ($request->audience === 'contacts') {
            $contactIds = collect($request->input('contact_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($contactIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pilih minimal satu kontak untuk audience ini.',
                ], 422);
            }

            $audienceQuery->whereIn('id', $contactIds);
        }

        $totalRecipients = $audienceQuery->count();

        if ($totalRecipients === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada kontak yang memenuhi kriteria.',
            ], 422);
        }

        $costEstimate = $this->messageDispatch->estimateCost($request->user()->id, $totalRecipients);

        return response()->json([
            'success' => true,
            'data' => [
                'total_recipients' => $totalRecipients,
                'cost_per_message' => $costEstimate['cost_per_message'] ?? WhatsappCampaign::COST_PER_MESSAGE,
                'total_cost' => $costEstimate['total_cost'] ?? $totalRecipients * WhatsappCampaign::COST_PER_MESSAGE,
                'formatted_cost' => $costEstimate['formatted_cost'] ?? 'Rp ' . number_format($totalRecipients * WhatsappCampaign::COST_PER_MESSAGE, 0, ',', '.'),
                'current_balance' => $costEstimate['current_balance'] ?? 0,
                'sufficient_balance' => $costEstimate['sufficient_balance'] ?? false,
            ],
        ]);
    }

    private function formatCampaign(WhatsappCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'status' => $c->status,
            'template_name' => $c->template_name,
            'total_recipients' => $c->total_recipients,
            'sent_count' => $c->sent_count,
            'delivered_count' => $c->delivered_count,
            'read_count' => $c->read_count,
            'failed_count' => $c->failed_count,
            'estimated_cost' => (int) $c->estimated_cost,
            'formatted_cost' => 'Rp ' . number_format($c->estimated_cost, 0, ',', '.'),
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'started_at' => $c->started_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
            'created_at' => $c->created_at->toIso8601String(),
            'can_start' => $c->canStart(),
            'can_pause' => $c->canPause(),
            'can_cancel' => $c->canCancel(),
        ];
    }
}
