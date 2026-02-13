<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Exceptions\WhatsApp\GupshupApiException;
use App\Jobs\ProcessWaBlastBatch;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappConnection;
use App\Models\WhatsappContact;
use App\Models\WhatsappTemplate;
use App\Services\WaBlastService;
use App\Services\RevenueGuardService;
use App\Services\Message\MessageDispatchService;
use App\Services\Message\MessageDispatchRequest;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * WaBlastController - Controller untuk WA Blast Flow
 * 
 * Endpoints:
 * - GET  /wa-blast                     → Stepper UI
 * - GET  /wa-blast/templates           → List approved templates
 * - POST /wa-blast/templates/sync      → Sync templates dari Gupshup
 * - GET  /wa-blast/audience            → Get audience dengan filter
 * - POST /wa-blast/campaign            → Create campaign (DRAFT)
 * - GET  /wa-blast/campaign/{id}       → Get campaign detail
 * - POST /wa-blast/campaign/{id}/preview → Preview campaign
 * - POST /wa-blast/campaign/{id}/confirm → Confirm (READY)
 * - POST /wa-blast/campaign/{id}/send    → Start sending
 * - POST /wa-blast/campaign/{id}/pause   → Pause campaign
 * - POST /wa-blast/campaign/{id}/resume  → Resume campaign
 * - POST /wa-blast/campaign/{id}/cancel  → Cancel campaign
 * - GET  /wa-blast/campaign/{id}/progress → Get progress (polling)
 * - GET  /wa-blast/quota               → Get current quota
 * 
 * @package App\Http\Controllers
 */
class WaBlastController extends Controller
{
    protected WaBlastService $waBlast;
    protected MessageDispatchService $messageDispatch;

    public function __construct(WaBlastService $waBlast, MessageDispatchService $messageDispatch)
    {
        $this->waBlast = $waBlast;
        $this->messageDispatch = $messageDispatch;
        $this->middleware('auth');
        $this->middleware('campaign.guard');
    }

    /**
     * Get klien_id for current user
     */
    protected function getKlienId(): int
    {
        return Auth::user()->klien_id;
    }

    // ==================== VIEWS ====================

    /**
     * Stepper UI - Main WA Blast page
     */
    public function index()
    {
        $klienId = $this->getKlienId();
        
        // Check if user has active connection
        $connection = WhatsappConnection::where('klien_id', $klienId)
            ->where('status', WhatsappConnection::STATUS_CONNECTED)
            ->first();

        if (!$connection) {
            return redirect()->route('whatsapp.index')
                ->with('error', 'Anda harus menghubungkan WhatsApp terlebih dahulu sebelum melakukan WA Blast.');
        }

        return view('wa-blast.index', [
            'connection' => $connection,
        ]);
    }

    // ==================== TEMPLATE ENDPOINTS ====================

    /**
     * GET /wa-blast/templates
     * List approved templates
     */
    public function templates(): JsonResponse
    {
        $klienId = $this->getKlienId();
        $templates = $this->waBlast->getApprovedTemplates($klienId);

        return response()->json([
            'success' => true,
            'templates' => $templates,
            'total' => count($templates),
        ]);
    }

    /**
     * POST /wa-blast/templates/sync
     * Sync templates from Gupshup
     */
    public function syncTemplates(): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $connection = WhatsappConnection::where('klien_id', $klienId)
            ->where('status', WhatsappConnection::STATUS_CONNECTED)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada koneksi WhatsApp aktif',
            ], 400);
        }

        try {
            $result = $this->waBlast->syncTemplates($connection);
            
            return response()->json([
                'success' => true,
                'message' => "Berhasil sync {$result['synced']} template",
                'synced' => $result['synced'],
                'failed' => $result['failed'],
            ]);
        } catch (GupshupApiException $e) {
            Log::channel('wa-blast')->error('Template sync failed - Gupshup API error', [
                'klien_id' => $klienId,
                'connection_id' => $connection->id,
                'error_code' => $e->getGupshupErrorCode(),
                'http_code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getGupshupErrorCode(),
            ], $e->getCode());
        } catch (\Exception $e) {
            Log::channel('wa-blast')->error('Template sync failed', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal sync template: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ==================== AUDIENCE ENDPOINTS ====================

    /**
     * GET /wa-blast/audience
     * Get audience with optional filters
     */
    public function audience(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $filters = [];
        if ($request->has('tags')) {
            $filters['tags'] = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
        }
        if ($request->has('custom_fields')) {
            $filters['custom_fields'] = $request->custom_fields;
        }

        $audience = $this->waBlast->getValidAudience($klienId, $filters);

        return response()->json([
            'success' => true,
            'valid' => $audience['valid'],
            'invalid' => $audience['invalid'],
            'contacts' => $audience['contacts']->map(function ($contact) {
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone_number' => $contact->phone_number,
                    'tags' => $contact->tags,
                ];
            }),
        ]);
    }

    /**
     * GET /wa-blast/audience/tags
     * Get available tags for filtering
     */
    public function audienceTags(): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        // Get unique tags from contacts
        $contacts = WhatsappContact::where('klien_id', $klienId)
            ->whereNotNull('tags')
            ->pluck('tags');

        $tags = $contacts->flatten()->unique()->values()->all();

        return response()->json([
            'success' => true,
            'tags' => $tags,
        ]);
    }

    // ==================== CAMPAIGN ENDPOINTS ====================

    /**
     * POST /wa-blast/campaign
     * Create new campaign in DRAFT status
     */
    public function createCampaign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'audience_filter' => ['nullable', 'array'],
            'audience_filter.tags' => ['nullable', 'array'],
            'template_variables' => ['nullable', 'array'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'batch_size' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $klienId = $this->getKlienId();

        // Verify template belongs to klien and is approved
        $template = WhatsappTemplate::where('id', $validated['template_id'])
            ->where('klien_id', $klienId)
            ->where('status', WhatsappTemplate::STATUS_APPROVED)
            ->first();

        if (!$template) {
            return response()->json([
                'success' => false,
                'message' => 'Template tidak valid atau belum disetujui',
            ], 400);
        }

        try {
            $campaign = $this->waBlast->createCampaign(
                klienId: $klienId,
                templateId: $validated['template_id'],
                name: $validated['name'],
                audienceFilter: $validated['audience_filter'] ?? [],
                templateVariables: $validated['template_variables'] ?? [],
                description: $validated['description'] ?? null,
                rateLimit: $validated['rate_limit'] ?? WaBlastService::DEFAULT_RATE_LIMIT,
                batchSize: $validated['batch_size'] ?? WaBlastService::DEFAULT_BATCH_SIZE,
            );

            return response()->json([
                'success' => true,
                'message' => 'Campaign berhasil dibuat',
                'campaign' => $campaign,
            ], 201);
        } catch (\Exception $e) {
            Log::channel('wa-blast')->error('Campaign creation failed', [
                'klien_id' => $klienId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat campaign: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /wa-blast/campaign/{id}
     * Get campaign detail
     */
    public function getCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->with(['template', 'recipients'])
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'campaign' => $campaign,
            'progress' => $this->waBlast->getCampaignProgress($campaign),
        ]);
    }

    /**
     * POST /wa-blast/campaign/{id}/preview
     * Preview campaign before sending
     */
    public function previewCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        if ($campaign->status !== CampaignStatus::DRAFT->value) {
            return response()->json([
                'success' => false,
                'message' => 'Preview hanya tersedia untuk campaign DRAFT',
            ], 400);
        }

        $preview = $this->waBlast->previewCampaign($campaign);

        return response()->json([
            'success' => true,
            'campaign' => $preview['campaign'],
            'preview' => $preview['preview'],
        ]);
    }

    /**
     * POST /wa-blast/campaign/{id}/confirm
     * Confirm campaign (change to READY)
     */
    public function confirmCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        $result = $this->waBlast->confirmCampaign($campaign);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * POST /wa-blast/campaign/{id}/send-protected
     * Start sending campaign with STRICT saldo protection
     * NEW: Uses MessageDispatchService for atomic saldo deduction
     */
    public function sendCampaignProtected(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        if ($campaign->status !== CampaignStatus::READY->value) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign harus dalam status READY untuk dikirim',
            ], 400);
        }

        try {
            // ============ REVENUE GUARD LAYER 4: Atomic Deduction ============
            $revenueGuard = app(RevenueGuardService::class);
            $recipientCount = $campaign->total_recipients ?? 0;
            if ($recipientCount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campaign tidak memiliki penerima',
                ], 400);
            }

            $guardResult = $revenueGuard->executeDeduction(
                userId: Auth::id(),
                messageCount: $recipientCount,
                category: 'marketing',
                referenceType: 'wa_blast',
                referenceId: $campaign->id,
                costPreview: request()->attributes->get('revenue_guard', []),
            );

            if (!$guardResult['success'] && !($guardResult['duplicate'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => 'revenue_guard_failed',
                    'message' => $guardResult['message'] ?? 'Gagal memproses pembayaran',
                ], 402);
            }

            // Execute via MessageDispatchService — preAuthorized karena RGS sudah deduct
            $result = $this->executeBlastCampaign($campaign, $guardResult['transaction']?->id);
            
            Log::channel('wa-blast')->info('WA Blast completed via MessageDispatch + RevenueGuard L4', [
                'campaign_id' => $campaign->id,
                'klien_id' => $klienId,
                'sent' => $result->totalSent,
                'failed' => $result->totalFailed,
                'cost' => $result->totalCost,
                'transaction_code' => $result->transactionCode,
                'revenue_guard_tx' => $guardResult['transaction']?->id,
            ]);

            return response()->json([
                'success' => $result->success,
                'message' => $result->isPartialSuccess() 
                    ? "Blast selesai. Berhasil: {$result->totalSent}, Gagal: {$result->totalFailed}"
                    : "Blast berhasil dikirim ke {$result->totalSent} kontak",
                'data' => $result->toApiResponse()['data']
            ]);

        } catch (InsufficientBalanceException $e) {
            // HARD STOP: Saldo tidak cukup
            Log::warning('WA Blast blocked - insufficient balance', [
                'campaign_id' => $campaign->id,
                'klien_id' => $klienId,
                'required' => $e->getRequiredAmount(),
                'current' => $e->getCurrentBalance(),
                'shortage' => $e->getShortageAmount()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'insufficient_balance',
                'message' => $e->getMessage(),
                'balance_info' => $e->toApiResponse()
            ], 402); // 402 Payment Required

        } catch (Exception $e) {
            Log::error('WA Blast send failed', [
                'campaign_id' => $campaign->id,
                'klien_id' => $klienId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim blast: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /wa-blast/campaign/{id}/send
     * Start sending campaign
     * Uses RevenueGuardService Layer 4 for atomic deduction before dispatching batch job.
     */
    public function sendCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        // ============ REVENUE GUARD LAYER 4: Atomic Deduction ============
        try {
            $revenueGuard = app(RevenueGuardService::class);
            $recipientCount = $campaign->total_recipients ?? 0;

            if ($recipientCount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campaign tidak memiliki penerima',
                ], 400);
            }

            $guardResult = $revenueGuard->executeDeduction(
                userId: Auth::id(),
                messageCount: $recipientCount,
                category: 'marketing',
                referenceType: 'wa_blast_batch',
                referenceId: $campaign->id,
                costPreview: request()->attributes->get('revenue_guard', []),
            );

            if (!$guardResult['success'] && !($guardResult['duplicate'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => 'revenue_guard_failed',
                    'message' => $guardResult['message'] ?? 'Gagal memproses pembayaran',
                ], 402);
            }
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_balance',
                'message' => $e->getMessage(),
            ], 402);
        }

        $result = $this->waBlast->startCampaign($campaign);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        // Dispatch first batch job (saldo sudah dipotong oleh RGS)
        ProcessWaBlastBatch::dispatch($campaign->id, 0);

        Log::channel('wa-blast')->info('Campaign send initiated via RevenueGuard L4', [
            'campaign_id' => $campaign->id,
            'klien_id' => $klienId,
            'revenue_guard_tx' => $guardResult['transaction']?->id ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign mulai dikirim',
            'campaign' => $result['campaign'],
        ]);
    }

    /**
     * POST /wa-blast/campaign/{id}/pause
     * Pause sending campaign
     */
    public function pauseCampaign(int $id, Request $request): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        $success = $this->waBlast->pauseCampaign($campaign, $request->input('reason'));

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Campaign dijeda' : 'Tidak dapat menjeda campaign',
            'campaign' => $campaign->fresh(),
        ], $success ? 200 : 400);
    }

    /**
     * POST /wa-blast/campaign/{id}/resume
     * Resume paused campaign with RevenueGuard L4 for remaining recipients.
     */
    public function resumeCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        // ============ REVENUE GUARD LAYER 4: Atomic Deduction for remaining ============
        try {
            $remainingRecipients = max(0, ($campaign->total_recipients ?? 0) - ($campaign->sent_count ?? 0) - ($campaign->failed_count ?? 0));

            if ($remainingRecipients > 0) {
                $revenueGuard = app(RevenueGuardService::class);

                $guardResult = $revenueGuard->executeDeduction(
                    userId: Auth::id(),
                    messageCount: $remainingRecipients,
                    category: 'marketing',
                    referenceType: 'wa_blast_resume',
                    referenceId: $campaign->id,
                    costPreview: request()->attributes->get('revenue_guard', []),
                );

                if (!$guardResult['success'] && !($guardResult['duplicate'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'revenue_guard_failed',
                        'message' => $guardResult['message'] ?? 'Gagal memproses pembayaran untuk resume',
                    ], 402);
                }
            }
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_balance',
                'message' => $e->getMessage(),
            ], 402);
        }

        $result = $this->waBlast->resumeCampaign($campaign);

        if ($result['success']) {
            // Dispatch next batch
            $nextBatch = $campaign->current_batch + 1;
            ProcessWaBlastBatch::dispatch($campaign->id, $nextBatch);
        }

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * POST /wa-blast/campaign/{id}/cancel
     * Cancel campaign
     */
    public function cancelCampaign(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        $success = $this->waBlast->cancelCampaign($campaign);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Campaign dibatalkan' : 'Tidak dapat membatalkan campaign',
            'campaign' => $campaign->fresh(),
        ], $success ? 200 : 400);
    }

    /**
     * GET /wa-blast/campaign/{id}/progress
     * Get campaign progress (for polling)
     */
    public function campaignProgress(int $id): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $campaign = WhatsappCampaign::where('id', $id)
            ->where('klien_id', $klienId)
            ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'progress' => $this->waBlast->getCampaignProgress($campaign),
        ]);
    }

    // ==================== QUOTA ENDPOINTS ====================

    /**
     * GET /wa-blast/quota
     * Get current quota status
     */
    public function quota(): JsonResponse
    {
        $klienId = $this->getKlienId();
        $user = Auth::user();

        $quotaCheck = $this->waBlast->checkQuota($klienId, $user->id, 0);

        return response()->json([
            'success' => true,
            'quota' => $quotaCheck,
        ]);
    }

    /**
     * POST /wa-blast/validate
     * Validate before creating campaign
     */
    public function validatePreSend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'exists:whatsapp_templates,id'],
            'recipient_count' => ['required', 'integer', 'min:1'],
        ]);

        $klienId = $this->getKlienId();

        $validation = $this->waBlast->validatePreSend(
            $klienId,
            $validated['template_id'],
            $validated['recipient_count']
        );

        return response()->json([
            'success' => $validation['can_send'],
            'validation' => $validation,
        ], $validation['can_send'] ? 200 : 400);
    }

    // ==================== CAMPAIGN LIST ====================

    /**
     * GET /wa-blast/campaigns
     * List campaigns for current klien
     */
    public function campaigns(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId();
        
        $query = WhatsappCampaign::where('klien_id', $klienId)
            ->with('template')
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $campaigns = $query->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'campaigns' => $campaigns,
        ]);
    }

    // ==================== PROTECTED METHODS ====================

    /**
     * Execute blast campaign via MessageDispatchService
     * When $revenueGuardTxId is set, dispatch uses preAuthorized=true (saldo already deducted by RGS L4).
     */
    protected function executeBlastCampaign(WhatsappCampaign $campaign, ?int $revenueGuardTxId = null): \App\Services\Message\MessageDispatchResult
    {
        // Get contact list from campaign audience
        $contacts = $this->waBlast->getCampaignContacts($campaign);
        
        if (empty($contacts)) {
            throw new Exception('Tidak ada kontak yang dapat diproses untuk campaign ini');
        }

        // Convert to recipients format
        $recipients = collect($contacts)->map(function ($contact) {
            return [
                'phone' => $contact['phone'],
                'name' => $contact['name'] ?? 'Unknown',
                'contact_id' => $contact['id'] ?? null
            ];
        })->toArray();

        // Get template and build message content
        $template = $campaign->template;
        if (!$template) {
            throw new Exception('Template campaign tidak ditemukan');
        }

        $messageContent = $this->buildBlastMessage($template, $campaign->template_variables ?? []);

        // Create dispatch request
        $dispatchRequest = MessageDispatchRequest::fromBroadcast(
            userId: $campaign->klien->user_id,
            recipients: $recipients,
            messageContent: $messageContent,
            broadcastId: (string) $campaign->id,
            preAuthorized: $revenueGuardTxId !== null,
            revenueGuardTransactionId: $revenueGuardTxId,
        );

        // Mark campaign as running
        $campaign->update(['status' => CampaignStatus::SENDING->value]);

        // Execute via MessageDispatchService
        $result = $this->messageDispatch->dispatch($dispatchRequest);

        // Update campaign from results
        $this->updateBlastCampaignFromResult($campaign, $result);

        return $result;
    }

    /**
     * Build message content for blast
     */
    protected function buildBlastMessage($template, array $variables = []): string
    {
        $content = $template->template_text ?? $template->content ?? '';

        // Replace variables in template
        foreach ($variables as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
        }

        return $content;
    }

    /**
     * Update blast campaign from dispatch result
     */
    protected function updateBlastCampaignFromResult(WhatsappCampaign $campaign, \App\Services\Message\MessageDispatchResult $result): void
    {
        // Update campaign status and stats
        $status = $result->success 
            ? ($result->totalFailed > 0 ? CampaignStatus::PARTIAL->value : CampaignStatus::COMPLETED->value)
            : CampaignStatus::FAILED->value;

        $campaign->update([
            'status' => $status,
            'sent_count' => $result->totalSent,
            'failed_count' => $result->totalFailed,
            'actual_cost' => $result->totalCost,
            'completed_at' => now(),
            'last_error' => $result->success ? null : 'Some messages failed to send'
        ]);

        // Update individual contact send statuses if needed
        foreach ($result->sentResults as $sentResult) {
            // Update contact blast status in campaign_contacts table if exists
            // This depends on your blast data structure
        }
    }
}
