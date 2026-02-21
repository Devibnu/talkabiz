<?php

namespace App\Http\Controllers;

use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappConnection;
use App\Models\WhatsappContact;
use App\Models\WhatsappTemplate;
use App\Jobs\ProcessWhatsappCampaign;
use App\Services\RevenueGuardService;
use App\Services\Message\MessageDispatchService;
use App\Services\Message\MessageDispatchRequest;
use App\Services\PlanLimitService;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\Subscription\PlanLimitExceededException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WhatsAppCampaignController extends Controller
{
    protected MessageDispatchService $messageDispatch;

    public function __construct(MessageDispatchService $messageDispatch)
    {
        $this->messageDispatch = $messageDispatch;
    }

    /**
     * List all campaigns
     */
    public function index(Request $request)
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return redirect()->route('dashboard')
                ->with('error', 'Profil klien diperlukan.');
        }

        $campaigns = WhatsappCampaign::where('klien_id', $klien->id)
            ->with('template')
            ->latest()
            ->paginate(15);

        return view('whatsapp.campaigns.index', compact('campaigns'));
    }

    /**
     * Show campaign creation form
     */
    public function create()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return redirect()->route('dashboard')
                ->with('error', 'Profil klien diperlukan.');
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return redirect()->route('whatsapp.index')
                ->with('error', 'Hubungkan WhatsApp Business terlebih dahulu.');
        }

        $templates = WhatsappTemplate::where('klien_id', $klien->id)
            ->approved()
            ->get();

        $contactsCount = WhatsappContact::where('klien_id', $klien->id)
            ->optedIn()
            ->count();

        $tags = WhatsappContact::where('klien_id', $klien->id)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->values();

        return view('whatsapp.campaigns.create', compact('templates', 'contactsCount', 'tags'));
    }

    /**
     * Estimate campaign cost BEFORE creation
     */
    public function estimateCost(Request $request)
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $request->validate([
            'template_id' => [
                'required',
                Rule::exists('whatsapp_templates', 'id')->where('klien_id', $klien->id),
            ],
            'audience_filter' => 'nullable|array',
        ]);

        try {
            // Build audience query
            $audienceQuery = WhatsappContact::where('klien_id', $klien->id)->optedIn();
            
            $filter = $request->get('audience_filter', []);
            if (!empty($filter['tags'])) {
                foreach ($filter['tags'] as $tag) {
                    $audienceQuery->withTag($tag);
                }
            }
            
            $totalRecipients = $audienceQuery->count();

            if ($totalRecipients === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada kontak yang memenuhi kriteria'
                ], 400);
            }

            // Get cost estimation from MessageDispatchService
            $costEstimate = $this->messageDispatch->estimateCost($klien->user_id, $totalRecipients);

            return response()->json([
                'success' => true,
                'data' => array_merge($costEstimate, [
                    'can_afford' => $costEstimate['sufficient_balance'],
                    'requires_topup' => !$costEstimate['sufficient_balance'],
                ])
            ]);

        } catch (Exception $e) {
            Log::error('Campaign cost estimation failed', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghitung estimasi biaya: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new campaign
     */
    public function store(Request $request)
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'template_id' => [
                'required',
                Rule::exists('whatsapp_templates', 'id')->where('klien_id', $klien->id),
            ],
            'audience_filter' => 'nullable|array',
            'audience_filter.tags' => 'nullable|array',
            'template_variables' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
            'rate_limit_per_second' => 'nullable|integer|min:1|max:50',
        ]);

        // Build audience query to count recipients
        $audienceQuery = WhatsappContact::where('klien_id', $klien->id)->optedIn();
        
        $filter = $request->get('audience_filter', []);
        if (!empty($filter['tags'])) {
            foreach ($filter['tags'] as $tag) {
                $audienceQuery->withTag($tag);
            }
        }
        
        $totalRecipients = $audienceQuery->count();

        if ($totalRecipients === 0) {
            return back()->with('error', 'Tidak ada kontak yang memenuhi kriteria. Pastikan kontak sudah opt-in.');
        }

        // HARD LIMIT: Enforce plan limits before creating campaign
        try {
            $planLimitService = app(PlanLimitService::class);
            $planLimitService->enforceCampaignLimit(auth()->user());
            $planLimitService->enforceRecipientLimit(auth()->user(), $totalRecipients);
        } catch (PlanLimitExceededException $e) {
            Log::info('Campaign creation blocked by plan limit', $e->getContext());

            if ($request->wantsJson()) {
                return response()->json($e->toArray(), $e->getHttpStatusCode());
            }

            return back()->with('error', $e->getUserMessage());
        }

        // Calculate estimated cost
        $estimatedCost = $totalRecipients * WhatsappCampaign::COST_PER_MESSAGE;

        // Create campaign
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
            'rate_limit_per_second' => $request->rate_limit_per_second ?? WhatsappCampaign::DEFAULT_RATE_LIMIT,
        ]);

        // Create recipient records
        $recipients = $audienceQuery->get();
        foreach ($recipients as $contact) {
            WhatsappCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'phone_number' => $contact->phone_number,
                'status' => WhatsappCampaignRecipient::STATUS_PENDING,
            ]);
        }

        Log::info('WA Campaign created', [
            'campaign_id' => $campaign->id,
            'klien_id' => $klien->id,
            'recipients' => $totalRecipients,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'campaign' => $campaign,
                'message' => "Kampanye berhasil dibuat dengan {$totalRecipients} penerima.",
            ]);
        }

        return redirect()->route('whatsapp.campaigns.show', $campaign)
            ->with('success', "Kampanye berhasil dibuat dengan {$totalRecipients} penerima.");
    }

    /**
     * Show campaign details
     */
    public function show(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        $campaign->load('template', 'recipients.contact');

        $stats = [
            'total' => $campaign->total_recipients,
            'sent' => $campaign->sent_count,
            'delivered' => $campaign->delivered_count,
            'read' => $campaign->read_count,
            'failed' => $campaign->failed_count,
            'pending' => $campaign->recipients()->pending()->count(),
        ];

        return view('whatsapp.campaigns.show', compact('campaign', 'stats'));
    }

    /**
     * Start a campaign with STRICT saldo protection
     */
    public function start(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if (!$campaign->canStart()) {
            return back()->with('error', 'Kampanye tidak dapat dimulai dari status saat ini.');
        }

        // Verify connection is still active
        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return back()->with('error', 'WhatsApp Business tidak terhubung.');
        }

        try {
            // ============ REVENUE GUARD LAYER 4: chargeAndExecute (atomic) ============
            $revenueGuard = app(RevenueGuardService::class);
            $recipientCount = $campaign->total_recipients ?? $campaign->recipients()->pending()->count();

            if ($recipientCount <= 0) {
                return back()->with('error', 'Kampanye tidak memiliki penerima.');
            }

            $guardResult = $revenueGuard->chargeAndExecute(
                userId: auth()->id(),
                messageCount: $recipientCount,
                category: 'marketing',
                referenceType: 'wa_campaign',
                referenceId: $campaign->id,
                dispatchCallable: function ($transaction) use ($campaign, $klien) {
                    // Execute inside transaction — rollback jika gagal
                    $result = $this->executeDirectCampaign($campaign, $transaction->id);

                    Log::info('WA Campaign completed via chargeAndExecute', [
                        'campaign_id' => $campaign->id,
                        'klien_id' => $klien->id,
                        'sent' => $result->totalSent,
                        'failed' => $result->totalFailed,
                        'cost' => $result->totalCost,
                        'transaction_code' => $result->transactionCode,
                        'revenue_guard_tx' => $transaction->id,
                    ]);

                    return $result;
                },
                costPreview: request()->attributes->get('revenue_guard', []),
            );

            if ($guardResult['duplicate'] ?? false) {
                return back()->with('info', $guardResult['message']);
            }

            $result = $guardResult['dispatch_result'];

            $message = $result->isPartialSuccess() 
                ? "Kampanye selesai. Berhasil: {$result->totalSent}, Gagal: {$result->totalFailed}"
                : "Kampanye berhasil dikirim ke {$result->totalSent} penerima";

            return back()->with('success', $message);

        } catch (InsufficientBalanceException $e) {
            // HARD STOP: Saldo tidak cukup
            return back()->with('error', $e->getMessage())
                ->with('topup_suggestion', [
                    'shortage' => $e->getShortageAmount(),
                    'required' => $e->getRequiredAmount(),
                    'formatted' => $e->getFormattedAmounts()
                ]);

        } catch (\RuntimeException $e) {
            // RevenueGuardService fail-closed
            return back()->with('error', $e->getMessage());

        } catch (Exception $e) {
            Log::error('Campaign start failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Gagal memulai kampanye: ' . $e->getMessage());
        }
    }

    /**
     * Execute campaign directly via MessageDispatchService
     * DEPRECATED: For legacy queue processing only
     */
    public function startLegacy(WhatsappCampaign $campaign)
    {
        // Old method preserved for backward compatibility
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if (!$campaign->canStart()) {
            return back()->with('error', 'Kampanye tidak dapat dimulai dari status saat ini.');
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return back()->with('error', 'WhatsApp Business tidak terhubung.');
        }

        // WARNING: This bypasses saldo protection - use for testing only
        $campaign->start();
        ProcessWhatsappCampaign::dispatch($campaign);

        Log::warning('WA Campaign started via LEGACY method (bypasses saldo protection)', [
            'campaign_id' => $campaign->id,
            'klien_id' => $klien->id,
        ]);

        return back()->with('warning', 'Kampanye dimulai dengan metode lama (tanpa proteksi saldo).');
    }

    /**
     * Protected method: Execute campaign via MessageDispatchService
     * When $revenueGuardTxId is set, dispatch uses preAuthorized=true (saldo already deducted by RGS L4).
     */
    protected function executeDirectCampaign(WhatsappCampaign $campaign, ?int $revenueGuardTxId = null): \App\Services\Message\MessageDispatchResult
    {
        // Get recipients
        $recipients = $campaign->recipients()
            ->with('contact')
            ->where('status', WhatsappCampaignRecipient::STATUS_PENDING)
            ->get()
            ->map(function ($recipient) {
                return [
                    'phone' => $recipient->phone_number,
                    'contact_id' => $recipient->contact_id,
                    'name' => $recipient->contact?->name ?? 'Unknown'
                ];
            })
            ->toArray();

        if (empty($recipients)) {
            throw new Exception('Tidak ada penerima yang dapat diproses');
        }

        // Get template content
        $template = $campaign->template;
        if (!$template) {
            throw new Exception('Template kampanye tidak ditemukan');
        }

        // Build message content with variables
        $messageContent = $this->buildMessageContent($template, $campaign->template_variables ?? []);

        // Create dispatch request
        $dispatchRequest = MessageDispatchRequest::fromCampaign(
            userId: $campaign->klien->user_id,
            recipients: $recipients,
            messageContent: $messageContent,
            campaignId: (string) $campaign->id,
            preAuthorized: $revenueGuardTxId !== null,
            revenueGuardTransactionId: $revenueGuardTxId,
        );

        // Mark campaign as running
        $campaign->update(['status' => WhatsappCampaign::STATUS_RUNNING]);

        // Execute via MessageDispatchService
        $result = $this->messageDispatch->dispatch($dispatchRequest);

        // Update campaign status based on results
        $this->updateCampaignFromResult($campaign, $result);

        return $result;
    }

    /**
     * Build message content from template with variables
     */
    protected function buildMessageContent($template, array $variables = []): string
    {
        $content = $template->content;

        // Replace variables in template
        foreach ($variables as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
        }

        return $content;
    }

    /**
     * Update campaign stats from dispatch result
     */
    protected function updateCampaignFromResult(WhatsappCampaign $campaign, \App\Services\Message\MessageDispatchResult $result): void
    {
        // Update campaign statistics
        $campaign->update([
            'status' => $result->success ? WhatsappCampaign::STATUS_COMPLETED : WhatsappCampaign::STATUS_FAILED,
            'sent_count' => $result->totalSent,
            'failed_count' => $result->totalFailed,
            'actual_cost' => $result->totalCost,
            'completed_at' => now(),
            'last_error' => $result->success ? null : 'Partial or complete failure'
        ]);

        // Update recipient statuses
        foreach ($result->sentResults as $sentResult) {
            $recipient = WhatsappCampaignRecipient::where('campaign_id', $campaign->id)
                ->where('phone_number', $sentResult['recipient'])
                ->first();

            if ($recipient) {
                $recipient->update([
                    'status' => $sentResult['status'] === 'sent' 
                        ? WhatsappCampaignRecipient::STATUS_SENT 
                        : WhatsappCampaignRecipient::STATUS_FAILED,
                    'whatsapp_message_id' => $sentResult['message_id'],
                    'error' => $sentResult['error'],
                    'sent_at' => $sentResult['sent_at']
                ]);
            }
        }
    }

    /**
     * Pause a campaign
     */
    public function pause(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if (!$campaign->canPause()) {
            return back()->with('error', 'Kampanye tidak dapat dijeda.');
        }

        $campaign->pause();

        Log::info('WA Campaign paused', [
            'campaign_id' => $campaign->id,
        ]);

        return back()->with('success', 'Kampanye berhasil dijeda.');
    }

    /**
     * Resume a paused campaign with RevenueGuard L4 for remaining recipients.
     */
    public function resume(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if ($campaign->status !== WhatsappCampaign::STATUS_PAUSED) {
            return back()->with('error', 'Kampanye tidak dalam status jeda.');
        }

        // ============ REVENUE GUARD LAYER 4: chargeAndExecute for remaining ============
        try {
            $remainingRecipients = $campaign->recipients()->pending()->count();

            if ($remainingRecipients > 0) {
                $revenueGuard = app(RevenueGuardService::class);

                $guardResult = $revenueGuard->chargeAndExecute(
                    userId: auth()->id(),
                    messageCount: $remainingRecipients,
                    category: 'marketing',
                    referenceType: 'wa_campaign_resume',
                    referenceId: $campaign->id,
                    dispatchCallable: function ($transaction) use ($campaign) {
                        $campaign->resume();
                        ProcessWhatsappCampaign::dispatch($campaign);
                        return ['dispatched' => true, 'remaining' => $campaign->recipients()->pending()->count()];
                    },
                    costPreview: request()->attributes->get('revenue_guard', []),
                );

                if ($guardResult['duplicate'] ?? false) {
                    return back()->with('info', $guardResult['message']);
                }
            } else {
                // No remaining recipients, just resume
                $campaign->resume();
                ProcessWhatsappCampaign::dispatch($campaign);
            }
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Log::info('WA Campaign resumed via chargeAndExecute', [
            'campaign_id' => $campaign->id,
            'remaining_recipients' => $remainingRecipients ?? 0,
        ]);

        return back()->with('success', 'Kampanye dilanjutkan.');
    }

    /**
     * Cancel a campaign
     */
    public function cancel(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if (!$campaign->canCancel()) {
            return back()->with('error', 'Kampanye tidak dapat dibatalkan.');
        }

        $campaign->cancel();

        Log::info('WA Campaign cancelled', [
            'campaign_id' => $campaign->id,
        ]);

        return back()->with('success', 'Kampanye dibatalkan.');
    }

    /**
     * Delete a draft campaign
     */
    public function destroy(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if ($campaign->status !== WhatsappCampaign::STATUS_DRAFT) {
            return back()->with('error', 'Hanya kampanye draft yang dapat dihapus.');
        }

        $campaign->delete();

        Log::info('WA Campaign deleted', [
            'campaign_id' => $campaign->id,
        ]);

        return redirect()->route('whatsapp.campaigns.index')
            ->with('success', 'Kampanye berhasil dihapus.');
    }

    /**
     * Get campaign statistics (AJAX)
     */
    public function stats(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => $campaign->status,
            'total' => $campaign->total_recipients,
            'sent' => $campaign->sent_count,
            'delivered' => $campaign->delivered_count,
            'read' => $campaign->read_count,
            'failed' => $campaign->failed_count,
            'pending' => $campaign->recipients()->pending()->count(),
            'delivery_rate' => $campaign->delivery_rate,
            'read_rate' => $campaign->read_rate,
            'actual_cost' => $campaign->actual_cost,
        ]);
    }

    /**
     * Get failed recipients for retry
     */
    public function failedRecipients(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $failed = $campaign->recipients()
            ->failed()
            ->with('contact')
            ->get();

        return response()->json([
            'recipients' => $failed,
        ]);
    }

    /**
     * Retry failed recipients with RevenueGuard L4.
     */
    public function retryFailed(WhatsappCampaign $campaign)
    {
        $klien = auth()->user()->klien;
        
        if ($campaign->klien_id !== $klien->id) {
            abort(403);
        }

        if ($campaign->status === WhatsappCampaign::STATUS_RUNNING) {
            return back()->with('error', 'Kampanye sedang berjalan.');
        }

        $failedCount = $campaign->recipients()->failed()->count();

        if ($failedCount <= 0) {
            return back()->with('info', 'Tidak ada penerima yang gagal untuk dicoba ulang.');
        }

        // ============ REVENUE GUARD LAYER 4: chargeAndExecute for retry ============
        try {
            $revenueGuard = app(RevenueGuardService::class);

            $guardResult = $revenueGuard->chargeAndExecute(
                userId: auth()->id(),
                messageCount: $failedCount,
                category: 'marketing',
                referenceType: 'wa_campaign_retry',
                referenceId: $campaign->id,
                dispatchCallable: function ($transaction) use ($campaign) {
                    // Reset failed recipients to pending
                    $resetCount = $campaign->recipients()
                        ->failed()
                        ->update([
                            'status' => WhatsappCampaignRecipient::STATUS_PENDING,
                            'error_code' => null,
                            'error_message' => null,
                            'failed_at' => null,
                        ]);

                    if ($resetCount > 0) {
                        $campaign->decrement('failed_count', $resetCount);
                        $campaign->status = WhatsappCampaign::STATUS_RUNNING;
                        $campaign->save();

                        ProcessWhatsappCampaign::dispatch($campaign);
                    }

                    return ['reset_count' => $resetCount];
                },
                costPreview: request()->attributes->get('revenue_guard', []),
            );

            if ($guardResult['duplicate'] ?? false) {
                return back()->with('info', $guardResult['message']);
            }

            $resetCount = $guardResult['dispatch_result']['reset_count'] ?? 0;

        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Log::info('WA Campaign retry via chargeAndExecute', [
            'campaign_id' => $campaign->id,
            'retry_count' => $resetCount,
            'revenue_guard_tx' => $guardResult['transaction']?->id ?? null,
        ]);

        return back()->with('success', "{$resetCount} penerima akan dicoba ulang.");
    }
}
