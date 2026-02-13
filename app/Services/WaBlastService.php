<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\Klien;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappConnection;
use App\Models\WhatsappContact;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappWarmup;
use App\Services\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Exception;
use Throwable;

/**
 * WaBlastService - Complete WA Blast Flow Manager
 * 
 * Menangani seluruh flow WA Blast:
 * 1. Template sync & validation
 * 2. Audience validation (opt-in check)
 * 3. Pre-send validation (connection, user status, quota)
 * 4. Campaign creation & preview
 * 5. Batch sending with rate limiting
 * 6. Quota enforcement
 * 7. Owner override handling
 * 
 * ATURAN PENTING:
 * ===============
 * 1. Semua pesan WAJIB menggunakan template Meta yang APPROVED
 * 2. Hanya kirim ke kontak yang opted_in = true
 * 3. Kuota habis = campaign STOP, bukan lanjut
 * 4. Owner force disconnect/ban = semua campaign STOP
 * 5. Semua operasi harus atomic dan idempotent
 * 
 * @package App\Services
 * @author Senior Laravel Engineer
 */
class WaBlastService
{
    protected GupshupService $gupshup;
    protected QuotaService $quotaService;
    protected ?WarmupService $warmupService = null;
    protected ?FeatureGateService $featureGate = null;
    protected ?SubscriptionPolicy $subscriptionPolicy = null;
    
    // Rate limit: messages per second
    const DEFAULT_RATE_LIMIT = 10;
    
    // Batch size for processing
    const DEFAULT_BATCH_SIZE = 100;
    
    // Cost per message (IDR)
    const COST_PER_MESSAGE = 350;

    // Fail reasons - aligned with SubscriptionPolicy reason codes
    const REASON_QUOTA_EXCEEDED = 'limit_exceeded';
    const REASON_NO_SUBSCRIPTION = 'no_subscription';
    const REASON_SUBSCRIPTION_EXPIRED = 'subscription_expired';
    const REASON_FEATURE_NOT_INCLUDED = 'feature_disabled';
    const REASON_OWNER_ACTION = 'owner_action';
    const REASON_CONNECTION_DISCONNECTED = 'connection_disconnected';
    const REASON_USER_INACTIVE = 'user_inactive';
    const REASON_NO_VALID_RECIPIENTS = 'no_valid_recipients';
    const REASON_WARMUP_LIMIT = 'warmup_limit_reached';

    public function __construct(
        GupshupService $gupshup, 
        QuotaService $quotaService, 
        ?WarmupService $warmupService = null, 
        ?FeatureGateService $featureGate = null,
        ?SubscriptionPolicy $subscriptionPolicy = null
    ) {
        $this->gupshup = $gupshup;
        $this->quotaService = $quotaService;
        $this->warmupService = $warmupService ?? app(WarmupService::class);
        $this->featureGate = $featureGate ?? app(FeatureGateService::class);
        $this->subscriptionPolicy = $subscriptionPolicy ?? app(SubscriptionPolicy::class);
    }

    // ==================== TEMPLATE HANDLING ====================

    /**
     * Sync templates from Gupshup API
     * 
     * @param WhatsappConnection $connection
     * @return array{synced: int, failed: int, templates: array}
     */
    public function syncTemplates(WhatsappConnection $connection): array
    {
        try {
            $gupshupService = GupshupService::forConnection($connection);
            $result = $gupshupService->getTemplates();

            if (!isset($result['templates'])) {
                Log::channel('wa-blast')->warning('Gupshup template sync: no templates found', [
                    'connection_id' => $connection->id,
                    'klien_id' => $connection->klien_id,
                ]);
                return ['synced' => 0, 'failed' => 0, 'templates' => []];
            }

            $synced = 0;
            $failed = 0;
            $templates = [];

            foreach ($result['templates'] as $template) {
                try {
                    $status = $this->mapTemplateStatus($template['status'] ?? 'PENDING');
                    
                    $waTemplate = WhatsappTemplate::updateOrCreate(
                        [
                            'klien_id' => $connection->klien_id,
                            'template_id' => $template['id'],
                        ],
                        [
                            'connection_id' => $connection->id,
                            'name' => $template['elementName'] ?? $template['name'] ?? 'Unknown',
                            'category' => $template['category'] ?? null,
                            'language' => $template['languageCode'] ?? 'id',
                            'components' => $template['components'] ?? null,
                            'sample_text' => $template['data'] ?? null,
                            'status' => $status,
                            'rejection_reason' => $template['reason'] ?? null,
                            'quality_score' => $template['qualityScore'] ?? null,
                            'synced_at' => now(),
                        ]
                    );
                    
                    $templates[] = $waTemplate;
                    $synced++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::channel('wa-blast')->error('Template sync failed', [
                        'template' => $template,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::channel('wa-blast')->info('Templates synced', [
                'connection_id' => $connection->id,
                'synced' => $synced,
                'failed' => $failed,
            ]);

            return ['synced' => $synced, 'failed' => $failed, 'templates' => $templates];
        } catch (Exception $e) {
            Log::channel('wa-blast')->error('Template sync error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get approved templates for klien
     */
    public function getApprovedTemplates(int $klienId): array
    {
        return WhatsappTemplate::where('klien_id', $klienId)
            ->where('status', WhatsappTemplate::STATUS_APPROVED)
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Map Gupshup template status
     */
    protected function mapTemplateStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVED', 'ENABLED' => WhatsappTemplate::STATUS_APPROVED,
            'REJECTED', 'DISABLED' => WhatsappTemplate::STATUS_REJECTED,
            'PAUSED' => WhatsappTemplate::STATUS_PAUSED,
            default => WhatsappTemplate::STATUS_PENDING,
        };
    }

    // ==================== AUDIENCE VALIDATION ====================

    /**
     * Get valid audience (opt-in only) for campaign
     * 
     * @param int $klienId
     * @param array $filters Optional filters (tags, etc)
     * @return array{valid: int, invalid: int, contacts: \Illuminate\Support\Collection}
     */
    public function getValidAudience(int $klienId, array $filters = []): array
    {
        $query = WhatsappContact::where('klien_id', $klienId);

        // Only opt-in contacts
        $query->where('opted_in', true);

        // Apply tag filters
        if (!empty($filters['tags'])) {
            foreach ($filters['tags'] as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }

        // Apply custom field filters
        if (!empty($filters['custom_fields'])) {
            foreach ($filters['custom_fields'] as $key => $value) {
                $query->where("custom_fields->{$key}", $value);
            }
        }

        $validContacts = $query->get();

        // Count invalid (non opt-in) for reporting
        $totalContacts = WhatsappContact::where('klien_id', $klienId)->count();
        $invalidCount = $totalContacts - $validContacts->count();

        return [
            'valid' => $validContacts->count(),
            'invalid' => $invalidCount,
            'contacts' => $validContacts,
        ];
    }

    /**
     * Validate single contact for sending
     */
    public function validateContact(WhatsappContact $contact): array
    {
        $errors = [];

        if (!$contact->opted_in) {
            $errors[] = 'not_opted_in';
        }

        if (empty($contact->phone_number)) {
            $errors[] = 'missing_phone';
        }

        // Validate phone format
        if (!preg_match('/^(62|0)[0-9]{9,13}$/', $contact->phone_number)) {
            $errors[] = 'invalid_phone_format';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    // ==================== PRE-SEND VALIDATION ====================

    /**
     * Complete pre-send validation using SubscriptionPolicy
     * 
     * Checks (via SubscriptionPolicy - reads from plan_snapshot):
     * 0. Subscription active
     * 0.1. Broadcast feature access
     * 0.2. Message quota limits
     * 
     * Checks (other validations):
     * 0.5. Warmup limit (if warmup enabled)
     * 1. WhatsApp connection status == CONNECTED
     * 2. User status == ACTIVE
     * 3. Template is APPROVED
     * 
     * CRITICAL: All subscription checks use plan_snapshot, NOT plans table
     * 
     * @return array{can_send: bool, errors: array, quota: array}
     */
    public function validatePreSend(
        int $klienId,
        int $templateId,
        int $recipientCount
    ): array {
        $errors = [];
        $warnings = [];
        $warmupInfo = null;

        // Get klien and user first (needed for subscription check)
        $klien = Klien::find($klienId);
        $user = $klien?->user;

        // =====================================================
        // SUBSCRIPTION ENFORCEMENT (via SubscriptionPolicy)
        // Reads from subscription.plan_snapshot - NOT plans table
        // =====================================================
        
        if ($user && $this->subscriptionPolicy) {
            // Check subscription + feature + quota in one call
            $campaignCheck = $this->subscriptionPolicy->canCreateCampaign($user, $recipientCount);
            
            if (!$campaignCheck['allowed']) {
                $errors[] = [
                    'code' => $campaignCheck['reason'],
                    'message' => $campaignCheck['message'],
                    'data' => $campaignCheck['data'] ?? null,
                ];
                
                // Return early with structured response
                return [
                    'can_send' => false,
                    'errors' => $errors,
                    'warnings' => [],
                    'quota' => [],
                    'connection' => null,
                    'template' => null,
                    'warmup' => null,
                    'reason' => $campaignCheck['reason'],
                    'upgrade_url' => $campaignCheck['upgrade_url'] ?? route('subscription.index'),
                ];
            }
        }

        // 1. Check WhatsApp connection
        $connection = WhatsappConnection::where('klien_id', $klienId)->first();
        if (!$connection) {
            $errors[] = [
                'code' => 'no_connection',
                'message' => 'Tidak ada koneksi WhatsApp yang terdaftar',
            ];
        } elseif ($connection->status !== WhatsappConnection::STATUS_CONNECTED) {
            $errors[] = [
                'code' => 'connection_not_active',
                'message' => 'Koneksi WhatsApp tidak aktif. Status: ' . $connection->status,
            ];
        }

        // 0.5. Check warmup limit BEFORE quota check (only if connection exists)
        if ($connection && $connection->warmup_enabled) {
            $warmupCheck = $this->warmupService->canSend($connection, $recipientCount);
            $warmupInfo = $warmupCheck;
            
            if (!$warmupCheck['can_send']) {
                $errors[] = [
                    'code' => 'warmup_limit_reached',
                    'message' => $warmupCheck['reason'] ?? 'Limit warmup harian tercapai',
                    'remaining' => $warmupCheck['remaining'],
                ];
            } elseif ($warmupCheck['warmup_active'] && $warmupCheck['remaining'] < $recipientCount) {
                $warnings[] = [
                    'code' => 'warmup_partial_send',
                    'message' => "Warmup aktif. Hanya {$warmupCheck['remaining']} pesan yang bisa dikirim hari ini.",
                    'remaining' => $warmupCheck['remaining'],
                ];
            }
        }

        // 0.5. Check health score - block if CRITICAL or paused by health
        if ($connection) {
            if ($connection->is_paused_by_health) {
                $errors[] = [
                    'code' => 'health_paused',
                    'message' => 'Pengiriman di-pause otomatis karena health score kritis',
                    'health_score' => $connection->health_score,
                    'health_status' => $connection->health_status,
                ];
            } elseif ($connection->health_status === 'critical') {
                $errors[] = [
                    'code' => 'health_critical',
                    'message' => 'Health score nomor WhatsApp dalam status CRITICAL. Perbaiki terlebih dahulu.',
                    'health_score' => $connection->health_score,
                ];
            } elseif ($connection->health_status === 'warning') {
                $warnings[] = [
                    'code' => 'health_warning',
                    'message' => 'Health score dalam status WARNING. Batch size dan delay sudah disesuaikan.',
                    'health_score' => $connection->health_score,
                ];
            }
        }

        // 2. Check User status (already fetched above for feature check)
        if (!$user) {
            $errors[] = [
                'code' => 'no_user',
                'message' => 'User tidak ditemukan',
            ];
        } elseif ($user->status !== 'active') {
            $errors[] = [
                'code' => 'user_inactive',
                'message' => 'Akun user tidak aktif. Status: ' . $user->status,
            ];
        }

        // 3. Check template is APPROVED
        $template = WhatsappTemplate::find($templateId);
        if (!$template) {
            $errors[] = [
                'code' => 'template_not_found',
                'message' => 'Template tidak ditemukan',
            ];
        } elseif (!$template->isApproved()) {
            $errors[] = [
                'code' => 'template_not_approved',
                'message' => 'Template belum disetujui Meta. Status: ' . $template->status,
            ];
        }

        // 4. Check quota
        $quota = $this->checkQuota($klienId, $user?->id ?? 0, $recipientCount);
        if (!$quota['sufficient']) {
            $errors[] = [
                'code' => 'insufficient_quota',
                'message' => $quota['message'],
                'details' => $quota,
            ];
        }

        // Check plan quota from QuotaService
        if ($user) {
            $planQuota = $this->quotaService->canConsume($klienId, $recipientCount);
            if (!$planQuota['can_consume']) {
                $errors[] = [
                    'code' => 'plan_quota_insufficient',
                    'message' => $planQuota['message'] ?? 'Kuota paket tidak mencukupi',
                ];
            }
        }

        return [
            'can_send' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'quota' => $quota,
            'connection' => $connection?->only(['id', 'status', 'phone_number']),
            'template' => $template?->only(['id', 'name', 'status']),
            'warmup' => $warmupInfo ? [
                'enabled' => $warmupInfo['warmup_active'] ?? false,
                'remaining' => $warmupInfo['remaining'] ?? null,
                'can_send' => $warmupInfo['can_send'] ?? true,
            ] : null,
        ];
    }

    /**
     * Check quota availability
     */
    public function checkQuota(int $klienId, int $userId, int $requiredAmount): array
    {
        $quota = UserQuota::forUser($userId, $klienId);
        $quota->resetIfNeeded();

        $dailyRemaining = $quota->getDailyRemaining();
        $monthlyRemaining = $quota->getMonthlyRemaining();
        $available = min($dailyRemaining, $monthlyRemaining);

        $sufficient = $available >= $requiredAmount;
        $message = null;

        if (!$sufficient) {
            if ($dailyRemaining < $requiredAmount) {
                $message = "Kuota harian tidak mencukupi. Tersisa: {$dailyRemaining}, dibutuhkan: {$requiredAmount}";
            } else {
                $message = "Kuota bulanan tidak mencukupi. Tersisa: {$monthlyRemaining}, dibutuhkan: {$requiredAmount}";
            }
        }

        return [
            'sufficient' => $sufficient,
            'message' => $message,
            'daily' => [
                'limit' => $quota->daily_limit,
                'used' => $quota->daily_used,
                'remaining' => $dailyRemaining,
            ],
            'monthly' => [
                'limit' => $quota->monthly_limit,
                'used' => $quota->monthly_used,
                'remaining' => $monthlyRemaining,
            ],
            'available' => $available,
            'required' => $requiredAmount,
            'estimated_cost' => $requiredAmount * self::COST_PER_MESSAGE,
        ];
    }

    // ==================== CAMPAIGN CREATION ====================

    /**
     * Create campaign in DRAFT status
     * 
     * @return WhatsappCampaign
     */
    public function createCampaign(
        int $klienId,
        int $templateId,
        string $name,
        array $audienceFilter = [],
        array $templateVariables = [],
        ?string $description = null,
        int $rateLimit = self::DEFAULT_RATE_LIMIT,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): WhatsappCampaign {
        $connection = WhatsappConnection::where('klien_id', $klienId)
            ->where('status', WhatsappConnection::STATUS_CONNECTED)
            ->first();

        return DB::transaction(function () use (
            $klienId, $templateId, $name, $audienceFilter, 
            $templateVariables, $description, $rateLimit, $batchSize, $connection
        ) {
            $campaign = WhatsappCampaign::create([
                'klien_id' => $klienId,
                'template_id' => $templateId,
                'connection_id' => $connection?->id,
                'name' => $name,
                'description' => $description,
                'status' => CampaignStatus::DRAFT->value,
                'audience_filter' => $audienceFilter,
                'template_variables' => $templateVariables,
                'rate_limit_per_second' => $rateLimit,
                'batch_size' => $batchSize,
            ]);

            Log::channel('wa-blast')->info('Campaign created', [
                'campaign_id' => $campaign->id,
                'klien_id' => $klienId,
                'template_id' => $templateId,
            ]);

            return $campaign;
        });
    }

    /**
     * Preview campaign before sending
     * 
     * Calculates target count and quota usage.
     * 
     * @return array{campaign: WhatsappCampaign, preview: array}
     */
    public function previewCampaign(WhatsappCampaign $campaign): array
    {
        $klien = Klien::find($campaign->klien_id);
        $audience = $this->getValidAudience($campaign->klien_id, $campaign->audience_filter ?? []);
        $quota = $this->checkQuota($campaign->klien_id, $klien?->user?->id ?? 0, $audience['valid']);

        // Prepare recipients if not yet done
        if ($campaign->recipients()->count() === 0 && $audience['valid'] > 0) {
            $this->prepareRecipients($campaign, $audience['contacts']);
        }

        // Update campaign with recipient counts
        $campaign->update([
            'total_recipients' => $audience['valid'],
            'estimated_cost' => $audience['valid'] * self::COST_PER_MESSAGE,
        ]);

        return [
            'campaign' => $campaign->fresh(),
            'preview' => [
                'total_recipients' => $audience['valid'],
                'invalid_recipients' => $audience['invalid'],
                'estimated_cost' => $audience['valid'] * self::COST_PER_MESSAGE,
                'estimated_cost_formatted' => 'Rp ' . number_format($audience['valid'] * self::COST_PER_MESSAGE, 0, ',', '.'),
                'quota' => $quota,
                'can_send' => $quota['sufficient'] && $audience['valid'] > 0,
                'send_blocked_reason' => !$quota['sufficient'] 
                    ? 'Kuota tidak mencukupi' 
                    : ($audience['valid'] === 0 ? 'Tidak ada penerima valid' : null),
            ],
        ];
    }

    /**
     * Prepare recipients for campaign (batch assignment)
     */
    protected function prepareRecipients(WhatsappCampaign $campaign, $contacts): void
    {
        $batchNumber = 0;
        $batchCount = 0;
        $batchSize = $campaign->batch_size ?? self::DEFAULT_BATCH_SIZE;

        // Apply health-based batch size reduction
        $connection = WhatsappConnection::find($campaign->connection_id);
        if ($connection && $connection->reduced_batch_size) {
            $batchSize = min($batchSize, $connection->reduced_batch_size);
        }

        $recipients = [];
        foreach ($contacts as $contact) {
            if ($batchCount >= $batchSize) {
                $batchNumber++;
                $batchCount = 0;
            }

            $recipients[] = [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'phone_number' => $contact->phone_number,
                'status' => MessageStatus::PENDING->value,
                'batch_number' => $batchNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $batchCount++;
        }

        // Bulk insert recipients
        foreach (array_chunk($recipients, 1000) as $chunk) {
            WhatsappCampaignRecipient::insert($chunk);
        }
    }

    // ==================== CAMPAIGN CONFIRMATION & SENDING ====================

    /**
     * Confirm campaign - change status to READY
     */
    public function confirmCampaign(WhatsappCampaign $campaign): array
    {
        if ($campaign->status !== CampaignStatus::DRAFT->value) {
            return [
                'success' => false,
                'message' => 'Campaign harus dalam status DRAFT untuk dikonfirmasi',
            ];
        }

        // Final validation
        $validation = $this->validatePreSend(
            $campaign->klien_id,
            $campaign->template_id,
            $campaign->total_recipients
        );

        if (!$validation['can_send']) {
            return [
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validation['errors'],
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::READY->value,
            'quota_allocated' => $campaign->total_recipients,
        ]);

        Log::channel('wa-blast')->info('Campaign confirmed', [
            'campaign_id' => $campaign->id,
            'total_recipients' => $campaign->total_recipients,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign siap dikirim',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Start sending campaign
     * 
     * Changes status to SENDING and returns the campaign for job processing.
     */
    public function startCampaign(WhatsappCampaign $campaign): array
    {
        $status = CampaignStatus::tryFrom($campaign->status);
        
        if (!$status || !$status->canStart()) {
            return [
                'success' => false,
                'message' => "Campaign tidak dapat dimulai. Status saat ini: {$campaign->status}",
            ];
        }

        // Final pre-send check
        $validation = $this->validatePreSend(
            $campaign->klien_id,
            $campaign->template_id,
            $campaign->total_recipients - $campaign->sent_count - $campaign->failed_count
        );

        if (!$validation['can_send']) {
            return [
                'success' => false,
                'message' => 'Validasi gagal sebelum kirim',
                'errors' => $validation['errors'],
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::SENDING->value,
            'started_at' => $campaign->started_at ?? now(),
        ]);

        Log::channel('wa-blast')->info('Campaign started', [
            'campaign_id' => $campaign->id,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign dimulai',
            'campaign' => $campaign->fresh(),
        ];
    }

    // ==================== BATCH SENDING ====================

    /**
     * Process one batch of messages
     * 
     * This method is called by the job worker.
     * Returns the result of batch processing.
     * 
     * @return array{processed: int, sent: int, failed: int, skipped: int, completed: bool, stopped_reason: ?string}
     */
    public function processBatch(WhatsappCampaign $campaign, int $batchNumber): array
    {
        // Pre-checks
        $preCheck = $this->checkCampaignCanContinue($campaign);
        if (!$preCheck['can_continue']) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'completed' => false,
                'stopped_reason' => $preCheck['reason'],
            ];
        }

        $connection = WhatsappConnection::find($campaign->connection_id);
        if (!$connection || $connection->status !== WhatsappConnection::STATUS_CONNECTED) {
            $this->failCampaign($campaign, self::REASON_CONNECTION_DISCONNECTED);
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'completed' => false,
                'stopped_reason' => self::REASON_CONNECTION_DISCONNECTED,
            ];
        }

        // Check warmup limit before batch processing
        $warmupActive = false;
        if ($connection->warmup_enabled) {
            $warmupCheck = $this->warmupService->canSend($connection, 1);
            $warmupActive = $warmupCheck['warmup_active'] ?? false;
            
            if (!$warmupCheck['can_send']) {
                // Pause campaign until next day
                $campaign->update([
                    'status' => CampaignStatus::PAUSED->value,
                    'pause_reason' => self::REASON_WARMUP_LIMIT,
                ]);
                
                return [
                    'processed' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'completed' => false,
                    'stopped_reason' => self::REASON_WARMUP_LIMIT,
                ];
            }
        }

        $gupshupService = GupshupService::forConnection($connection);
        $template = WhatsappTemplate::find($campaign->template_id);

        // Get recipients for this batch
        $recipients = WhatsappCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('batch_number', $batchNumber)
            ->where('status', MessageStatus::PENDING->value)
            ->get();

        $klien = Klien::find($campaign->klien_id);
        $quota = UserQuota::forUser($klien?->user?->id ?? 0, $campaign->klien_id);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;

        $rateLimit = $campaign->rate_limit_per_second ?? self::DEFAULT_RATE_LIMIT;
        $delayMicroseconds = (int) (1000000 / $rateLimit);

        // Apply health-based adjustments
        if ($connection->added_delay_ms) {
            // Add extra delay between messages if health is degraded
            $delayMicroseconds += ($connection->added_delay_ms * 1000); // Convert ms to microseconds
        }

        foreach ($recipients as $recipient) {
            $processed++;

            // Check warmup limit per message (if warmup active)
            if ($warmupActive) {
                $warmupCheck = $this->warmupService->canSend($connection, 1);
                if (!$warmupCheck['can_send']) {
                    // Pause campaign until next day - warmup limit reached
                    $campaign->update([
                        'status' => CampaignStatus::PAUSED->value,
                        'pause_reason' => self::REASON_WARMUP_LIMIT,
                    ]);
                    break;
                }
            }

            // Check quota before each send
            if (!$quota->hasQuota(1)) {
                $this->failCampaign($campaign, self::REASON_QUOTA_EXCEEDED);
                
                // Mark remaining as pending
                break;
            }

            // Validate contact
            $contact = WhatsappContact::find($recipient->contact_id);
            $validation = $contact ? $this->validateContact($contact) : ['valid' => false, 'errors' => ['contact_not_found']];

            if (!$validation['valid']) {
                $this->skipRecipient($recipient, implode(',', $validation['errors']));
                $skipped++;
                continue;
            }

            // Send message
            try {
                $idempotencyKey = "blast:{$campaign->id}:{$recipient->id}";
                
                // Check idempotency
                if ($this->isAlreadySent($idempotencyKey)) {
                    $skipped++;
                    continue;
                }

                // Consume quota
                $quotaResult = $quota->consume(1);
                if (!$quotaResult['success']) {
                    $this->failCampaign($campaign, $quotaResult['reason'] ?? self::REASON_QUOTA_EXCEEDED);
                    break;
                }

                // Prepare template parameters
                $params = $this->prepareTemplateParams(
                    $campaign->template_variables ?? [],
                    $contact
                );

                // Send via Gupshup
                $result = $gupshupService->sendTemplateMessage(
                    destination: $recipient->phone_number,
                    templateId: $template->template_id,
                    params: $params,
                    klienId: $campaign->klien_id,
                    campaignId: $campaign->id
                );

                // Update recipient status
                $recipient->update([
                    'status' => MessageStatus::SENT->value,
                    'message_id' => $result['messageId'] ?? null,
                    'sent_at' => now(),
                    'cost' => self::COST_PER_MESSAGE,
                ]);

                // Record warmup send (if warmup active)
                if ($warmupActive) {
                    $this->warmupService->recordSend($connection, 1);
                }

                // Log message
                $this->logMessage($campaign, $recipient, $result, $idempotencyKey);

                $sent++;

                // Rate limiting
                usleep($delayMicroseconds);

            } catch (Throwable $e) {
                Log::channel('wa-blast')->error('Message send failed', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);

                // Rollback quota on failure
                $quota->rollback(1);

                // Record warmup fail (if warmup active)
                if ($warmupActive) {
                    $this->warmupService->recordSend($connection, 0, 0, 1);
                }

                $recipient->update([
                    'status' => MessageStatus::FAILED->value,
                    'failed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        // Update campaign counts
        $campaign->increment('sent_count', $sent);
        $campaign->increment('failed_count', $failed);
        $campaign->increment('skipped_count', $skipped);
        $campaign->update(['current_batch' => $batchNumber]);
        $campaign->update(['quota_used' => $campaign->quota_used + $sent]);

        // Check if all batches completed
        $pendingCount = WhatsappCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', MessageStatus::PENDING->value)
            ->count();

        $completed = $pendingCount === 0;
        if ($completed && $campaign->status === CampaignStatus::SENDING->value) {
            $this->completeCampaign($campaign);
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'completed' => $completed,
            'stopped_reason' => null,
        ];
    }

    /**
     * Prepare template parameters with contact data
     */
    protected function prepareTemplateParams(array $variables, WhatsappContact $contact): array
    {
        $params = [];
        
        foreach ($variables as $key => $source) {
            $value = match ($source) {
                'name' => $contact->name ?? '',
                'phone' => $contact->phone_number ?? '',
                'email' => $contact->email ?? '',
                default => $contact->custom_fields[$source] ?? $source,
            };
            
            $params[] = $value;
        }

        return $params;
    }

    /**
     * Check if message already sent (idempotency)
     */
    protected function isAlreadySent(string $idempotencyKey): bool
    {
        return WhatsappMessageLog::where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * Log sent message
     */
    protected function logMessage(
        WhatsappCampaign $campaign,
        WhatsappCampaignRecipient $recipient,
        array $result,
        string $idempotencyKey
    ): void {
        WhatsappMessageLog::create([
            'klien_id' => $campaign->klien_id,
            'message_id' => $result['messageId'] ?? null,
            'direction' => WhatsappMessageLog::DIRECTION_OUTBOUND,
            'phone_number' => $recipient->phone_number,
            'template_id' => $campaign->template?->template_id,
            'status' => MessageStatus::SENT->value,
            'cost' => self::COST_PER_MESSAGE,
            'campaign_id' => $campaign->id,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'recipient_id' => $recipient->id,
                'batch_number' => $recipient->batch_number,
            ],
        ]);
    }

    /**
     * Skip recipient with reason
     */
    protected function skipRecipient(WhatsappCampaignRecipient $recipient, string $reason): void
    {
        $recipient->update([
            'status' => MessageStatus::SKIPPED->value,
            'skip_reason' => $reason,
        ]);

        Log::channel('wa-blast')->info('Recipient skipped', [
            'recipient_id' => $recipient->id,
            'reason' => $reason,
        ]);
    }

    // ==================== CAMPAIGN STATUS MANAGEMENT ====================

    /**
     * Check if campaign can continue sending
     */
    public function checkCampaignCanContinue(WhatsappCampaign $campaign): array
    {
        $campaign->refresh();

        // Check if stopped by owner
        if ($campaign->stopped_by_owner) {
            return [
                'can_continue' => false,
                'reason' => self::REASON_OWNER_ACTION,
            ];
        }

        // Check campaign status
        $status = CampaignStatus::tryFrom($campaign->status);
        if (!$status || $status->isTerminal()) {
            return [
                'can_continue' => false,
                'reason' => "campaign_status_{$campaign->status}",
            ];
        }

        if ($status === CampaignStatus::PAUSED) {
            return [
                'can_continue' => false,
                'reason' => 'campaign_paused',
            ];
        }

        // Check connection status
        $connection = WhatsappConnection::find($campaign->connection_id);
        if (!$connection || $connection->status !== WhatsappConnection::STATUS_CONNECTED) {
            return [
                'can_continue' => false,
                'reason' => self::REASON_CONNECTION_DISCONNECTED,
            ];
        }

        // Check user status
        $klien = Klien::find($campaign->klien_id);
        if ($klien?->user?->status !== 'active') {
            return [
                'can_continue' => false,
                'reason' => self::REASON_USER_INACTIVE,
            ];
        }

        // =====================================================
        // SUBSCRIPTION CHECK (via SubscriptionPolicy)
        // CRITICAL: This reads from plan_snapshot, NOT plans table
        // =====================================================
        $user = $klien?->user;
        if ($user && $this->subscriptionPolicy) {
            // Check remaining messages
            $remaining = $campaign->total_recipients - $campaign->sent_count - $campaign->failed_count - $campaign->skipped_count;
            
            // Check if user can still send messages
            $sendCheck = $this->subscriptionPolicy->canSendMessage($user, $remaining);
            if (!$sendCheck['allowed']) {
                return [
                    'can_continue' => false,
                    'reason' => $sendCheck['reason'],
                    'message' => $sendCheck['message'],
                    'upgrade_url' => $sendCheck['upgrade_url'] ?? route('subscription.index'),
                ];
            }
        }

        return [
            'can_continue' => true,
            'reason' => null,
        ];
    }

    /**
     * Pause campaign
     */
    public function pauseCampaign(WhatsappCampaign $campaign, ?string $reason = null): bool
    {
        if (!CampaignStatus::tryFrom($campaign->status)?->canPause()) {
            return false;
        }

        $campaign->update([
            'status' => CampaignStatus::PAUSED->value,
            'fail_reason' => $reason,
        ]);

        Log::channel('wa-blast')->info('Campaign paused', [
            'campaign_id' => $campaign->id,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Resume paused campaign
     */
    public function resumeCampaign(WhatsappCampaign $campaign): array
    {
        if ($campaign->status !== CampaignStatus::PAUSED->value) {
            return [
                'success' => false,
                'message' => 'Campaign tidak dalam status PAUSED',
            ];
        }

        // Re-validate before resume
        $remaining = $campaign->total_recipients - $campaign->sent_count - $campaign->failed_count - $campaign->skipped_count;
        $validation = $this->validatePreSend($campaign->klien_id, $campaign->template_id, $remaining);

        if (!$validation['can_send']) {
            return [
                'success' => false,
                'message' => 'Validasi gagal untuk melanjutkan',
                'errors' => $validation['errors'],
            ];
        }

        $campaign->update([
            'status' => CampaignStatus::SENDING->value,
            'fail_reason' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Campaign dilanjutkan',
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * Fail campaign with reason
     */
    public function failCampaign(WhatsappCampaign $campaign, string $reason): void
    {
        $campaign->update([
            'status' => CampaignStatus::FAILED->value,
            'fail_reason' => $reason,
            'completed_at' => now(),
        ]);

        Log::channel('wa-blast')->warning('Campaign failed', [
            'campaign_id' => $campaign->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Complete campaign
     */
    protected function completeCampaign(WhatsappCampaign $campaign): void
    {
        $actualCost = $campaign->sent_count * self::COST_PER_MESSAGE;

        $campaign->update([
            'status' => CampaignStatus::COMPLETED->value,
            'completed_at' => now(),
            'actual_cost' => $actualCost,
        ]);

        Log::channel('wa-blast')->info('Campaign completed', [
            'campaign_id' => $campaign->id,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'actual_cost' => $actualCost,
        ]);
    }

    /**
     * Cancel campaign
     */
    public function cancelCampaign(WhatsappCampaign $campaign): bool
    {
        if (!CampaignStatus::tryFrom($campaign->status)?->canCancel()) {
            return false;
        }

        $campaign->update([
            'status' => CampaignStatus::CANCELLED->value,
            'completed_at' => now(),
        ]);

        Log::channel('wa-blast')->info('Campaign cancelled', [
            'campaign_id' => $campaign->id,
        ]);

        return true;
    }

    // ==================== OWNER OVERRIDE ====================

    /**
     * Stop all campaigns by owner action
     * 
     * Called when owner force disconnects or bans a user/whatsapp.
     * 
     * @param int $klienId
     * @param int $ownerId
     * @param string $reason
     * @return int Number of campaigns stopped
     */
    public function stopAllCampaignsByOwner(int $klienId, int $ownerId, string $reason = 'owner_action'): int
    {
        $campaigns = WhatsappCampaign::where('klien_id', $klienId)
            ->whereIn('status', [
                CampaignStatus::READY->value,
                CampaignStatus::SENDING->value,
                CampaignStatus::SCHEDULED->value,
                CampaignStatus::PAUSED->value,
            ])
            ->get();

        $stoppedCount = 0;

        foreach ($campaigns as $campaign) {
            $campaign->update([
                'status' => CampaignStatus::FAILED->value,
                'fail_reason' => self::REASON_OWNER_ACTION . ':' . $reason,
                'stopped_by_owner' => true,
                'stopped_by_user_id' => $ownerId,
                'stopped_at' => now(),
                'completed_at' => now(),
            ]);

            // Log to audit
            AuditLog::create([
                'actor_id' => $ownerId,
                'actor_type' => 'user',
                'target_type' => 'whatsapp_campaign',
                'target_id' => $campaign->id,
                'action' => 'stop_campaign',
                'reason' => $reason,
                'metadata' => [
                    'klien_id' => $klienId,
                    'previous_status' => $campaign->getOriginal('status'),
                ],
            ]);

            $stoppedCount++;
        }

        Log::channel('wa-blast')->warning('Campaigns stopped by owner', [
            'klien_id' => $klienId,
            'owner_id' => $ownerId,
            'campaigns_stopped' => $stoppedCount,
            'reason' => $reason,
        ]);

        return $stoppedCount;
    }

    // ==================== PROGRESS & STATS ====================

    /**
     * Get campaign progress
     */
    public function getCampaignProgress(WhatsappCampaign $campaign): array
    {
        $campaign->refresh();

        $total = $campaign->total_recipients;
        $sent = $campaign->sent_count;
        $failed = $campaign->failed_count;
        $skipped = $campaign->skipped_count ?? 0;
        $delivered = $campaign->delivered_count ?? 0;
        $read = $campaign->read_count ?? 0;
        $pending = $total - $sent - $failed - $skipped;

        return [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
            'status_label' => CampaignStatus::tryFrom($campaign->status)?->label() ?? $campaign->status,
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'skipped' => $skipped,
            'pending' => max(0, $pending),
            'percentage' => $total > 0 ? round((($sent + $failed + $skipped) / $total) * 100, 1) : 0,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : 0,
            'read_rate' => $delivered > 0 ? round(($read / $delivered) * 100, 1) : 0,
            'current_batch' => $campaign->current_batch,
            'started_at' => $campaign->started_at?->toISOString(),
            'completed_at' => $campaign->completed_at?->toISOString(),
            'fail_reason' => $campaign->fail_reason,
            'actual_cost' => $campaign->actual_cost,
        ];
    }
}
