<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Klien;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SubscriptionChangeService
 * 
 * Central service untuk Upgrade & Downgrade subscription.
 * 
 * ATURAN BISNIS:
 * ==============
 * 
 * UPGRADE (ke plan lebih tinggi):
 * - Berlaku LANGSUNG
 * - Create subscription baru dengan snapshot baru
 * - Subscription lama di-mark 'replaced'
 * - Hanya 1 subscription aktif
 * 
 * DOWNGRADE (ke plan lebih rendah):
 * - Simpan pending_change
 * - Tetap pakai paket lama sampai expires_at
 * - Scheduled job apply pending di akhir periode
 * - User tetap bayar harga lama sampai habis
 * 
 * NO PRO-RATE:
 * - Upgrade: User bayar full price paket baru
 * - Downgrade: User tetap bayar sampai periode habis
 * - Simple, clear, no complexity
 * 
 * COMPARISON:
 * - Compare by plan.priority (higher = better plan)
 * - Equal priority = no change
 * 
 * @author Senior Laravel SaaS Architect
 */
class SubscriptionChangeService
{
    // Result codes
    public const RESULT_SUCCESS = 'success';
    public const RESULT_NO_ACTIVE_SUBSCRIPTION = 'no_active_subscription';
    public const RESULT_SAME_PLAN = 'same_plan';
    public const RESULT_PLAN_NOT_FOUND = 'plan_not_found';
    public const RESULT_ALREADY_PENDING = 'already_pending';
    public const RESULT_ERROR = 'error';

    /**
     * Change plan (upgrade or downgrade)
     * 
     * Automatically determines if it's upgrade or downgrade.
     * 
     * @param Klien $klien
     * @param Plan $newPlan
     * @return array{success: bool, type: string, message: string, subscription?: Subscription}
     */
    public function changePlan(Klien $klien, Plan $newPlan): array
    {
        // Get current active subscription
        $currentSubscription = $this->getActiveSubscription($klien);

        if (!$currentSubscription) {
            // No active subscription - create new
            return $this->createNewSubscription($klien, $newPlan);
        }

        // Same plan check
        if ($currentSubscription->plan_id === $newPlan->id) {
            return [
                'success' => false,
                'type' => self::RESULT_SAME_PLAN,
                'message' => 'Anda sudah menggunakan paket ini',
                'subscription' => $currentSubscription,
            ];
        }

        // Determine upgrade or downgrade
        $changeType = $this->determineChangeType($currentSubscription, $newPlan);

        if ($changeType === Subscription::CHANGE_TYPE_UPGRADE) {
            return $this->processUpgrade($klien, $currentSubscription, $newPlan);
        } else {
            return $this->processDowngrade($klien, $currentSubscription, $newPlan);
        }
    }

    /**
     * Process UPGRADE (immediate)
     * 
     * @param Klien $klien
     * @param Subscription $currentSubscription
     * @param Plan $newPlan
     * @return array
     */
    public function processUpgrade(Klien $klien, Subscription $currentSubscription, Plan $newPlan): array
    {
        return DB::transaction(function () use ($klien, $currentSubscription, $newPlan) {
            Log::info('[SubscriptionChange] Processing UPGRADE', [
                'klien_id' => $klien->id,
                'from_plan' => $currentSubscription->plan_id,
                'to_plan' => $newPlan->id,
            ]);

            // 1. Create new subscription (active immediately)
            $newSubscription = new Subscription();
            $newSubscription->klien_id = $klien->id;
            $newSubscription->plan_id = $newPlan->id;
            $newSubscription->plan_snapshot = $newPlan->toSnapshot();
            $newSubscription->price = $newPlan->price_monthly;
            $newSubscription->currency = 'IDR';
            $newSubscription->status = Subscription::STATUS_ACTIVE;
            $newSubscription->started_at = now();
            $newSubscription->change_type = Subscription::CHANGE_TYPE_UPGRADE;
            $newSubscription->previous_subscription_id = $currentSubscription->id;

            // Calculate expiry from new plan
            $durationDays = $newPlan->toSnapshot()['duration_days'] ?? 30;
            if ($durationDays > 0) {
                $newSubscription->expires_at = now()->addDays($durationDays);
            }

            $newSubscription->save();

            // 2. Mark old subscription as replaced
            $currentSubscription->markReplaced($newSubscription->id);

            // 3. Clear any pending change on old subscription
            if ($currentSubscription->hasPendingChange()) {
                $currentSubscription->clearPendingChange();
            }

            // 4. Clear cache
            $this->clearCache($klien->id);

            Log::info('[SubscriptionChange] UPGRADE completed', [
                'klien_id' => $klien->id,
                'new_subscription_id' => $newSubscription->id,
                'old_subscription_id' => $currentSubscription->id,
            ]);

            return [
                'success' => true,
                'type' => Subscription::CHANGE_TYPE_UPGRADE,
                'message' => 'Upgrade berhasil! Paket baru berlaku sekarang.',
                'subscription' => $newSubscription,
                'old_subscription' => $currentSubscription,
            ];
        });
    }

    /**
     * Process DOWNGRADE (pending until period end)
     * 
     * @param Klien $klien
     * @param Subscription $currentSubscription
     * @param Plan $newPlan
     * @return array
     */
    public function processDowngrade(Klien $klien, Subscription $currentSubscription, Plan $newPlan): array
    {
        // Check if already has pending change
        if ($currentSubscription->hasPendingChange()) {
            $pending = $currentSubscription->getPendingChangeInfo();
            
            // Allow changing pending plan
            if ($pending['new_plan_id'] === $newPlan->id) {
                return [
                    'success' => false,
                    'type' => self::RESULT_ALREADY_PENDING,
                    'message' => 'Paket ini sudah dijadwalkan untuk periode berikutnya',
                    'subscription' => $currentSubscription,
                    'pending_change' => $pending,
                ];
            }
        }

        Log::info('[SubscriptionChange] Processing DOWNGRADE', [
            'klien_id' => $klien->id,
            'from_plan' => $currentSubscription->plan_id,
            'to_plan' => $newPlan->id,
            'effective_at' => $currentSubscription->expires_at,
        ]);

        // Determine effective date (end of current period)
        $effectiveAt = $currentSubscription->expires_at ?? now()->addMonth();

        // Set pending change
        $currentSubscription->setPendingChange($newPlan, $effectiveAt);

        // Clear cache
        $this->clearCache($klien->id);

        $formattedDate = $effectiveAt->translatedFormat('d F Y');

        Log::info('[SubscriptionChange] DOWNGRADE scheduled', [
            'klien_id' => $klien->id,
            'effective_at' => $effectiveAt,
        ]);

        return [
            'success' => true,
            'type' => Subscription::CHANGE_TYPE_DOWNGRADE,
            'message' => "Downgrade dijadwalkan. Paket baru berlaku mulai {$formattedDate}.",
            'subscription' => $currentSubscription,
            'pending_change' => $currentSubscription->getPendingChangeInfo(),
            'effective_at' => $effectiveAt,
        ];
    }

    /**
     * Cancel pending downgrade
     * 
     * @param Klien $klien
     * @return array
     */
    public function cancelPendingChange(Klien $klien): array
    {
        $subscription = $this->getActiveSubscription($klien);

        if (!$subscription) {
            return [
                'success' => false,
                'message' => 'Tidak ada subscription aktif',
            ];
        }

        if (!$subscription->hasPendingChange()) {
            return [
                'success' => false,
                'message' => 'Tidak ada perubahan paket yang dijadwalkan',
            ];
        }

        $pending = $subscription->getPendingChangeInfo();
        $subscription->clearPendingChange();
        
        $this->clearCache($klien->id);

        Log::info('[SubscriptionChange] Pending change cancelled', [
            'klien_id' => $klien->id,
            'cancelled_plan_id' => $pending['new_plan_id'],
        ]);

        return [
            'success' => true,
            'message' => 'Perubahan paket dibatalkan. Anda akan tetap menggunakan paket saat ini.',
            'cancelled_plan_id' => $pending['new_plan_id'],
        ];
    }

    /**
     * Process all pending changes that are due
     * 
     * Called by scheduled command at period end.
     * 
     * @return array{processed: int, failed: int, details: array}
     */
    public function processPendingChanges(): array
    {
        $pendingSubscriptions = Subscription::pendingChangesDue()->get();

        $results = [
            'processed' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($pendingSubscriptions as $subscription) {
            try {
                $pending = $subscription->getPendingChangeInfo();
                
                if (!$pending) {
                    continue;
                }

                // Get the new plan
                $newPlan = Plan::find($pending['new_plan_id']);
                
                if (!$newPlan) {
                    Log::warning('[SubscriptionChange] Pending plan not found', [
                        'subscription_id' => $subscription->id,
                        'plan_id' => $pending['new_plan_id'],
                    ]);
                    $results['failed']++;
                    $results['details'][] = [
                        'subscription_id' => $subscription->id,
                        'error' => 'Plan not found',
                    ];
                    continue;
                }

                // Apply the pending change
                $this->applyPendingChange($subscription, $newPlan);
                
                $results['processed']++;
                $results['details'][] = [
                    'subscription_id' => $subscription->id,
                    'new_plan_id' => $newPlan->id,
                    'status' => 'success',
                ];

            } catch (\Exception $e) {
                Log::error('[SubscriptionChange] Error processing pending change', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                $results['failed']++;
                $results['details'][] = [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Apply a pending change (create new subscription)
     * 
     * @param Subscription $oldSubscription
     * @param Plan $newPlan
     * @return Subscription
     */
    protected function applyPendingChange(Subscription $oldSubscription, Plan $newPlan): Subscription
    {
        return DB::transaction(function () use ($oldSubscription, $newPlan) {
            $klien = $oldSubscription->klien;

            // 1. Create new subscription
            $newSubscription = new Subscription();
            $newSubscription->klien_id = $klien->id;
            $newSubscription->plan_id = $newPlan->id;
            $newSubscription->plan_snapshot = $newPlan->toSnapshot();
            $newSubscription->price = $newPlan->price_monthly;
            $newSubscription->currency = 'IDR';
            $newSubscription->status = Subscription::STATUS_ACTIVE;
            $newSubscription->started_at = now();
            $newSubscription->change_type = Subscription::CHANGE_TYPE_DOWNGRADE;
            $newSubscription->previous_subscription_id = $oldSubscription->id;

            // Calculate expiry
            $durationDays = $newPlan->toSnapshot()['duration_days'] ?? 30;
            if ($durationDays > 0) {
                $newSubscription->expires_at = now()->addDays($durationDays);
            }

            $newSubscription->save();

            // 2. Mark old subscription as replaced
            $oldSubscription->markReplaced($newSubscription->id);

            // 3. Clear cache
            $this->clearCache($klien->id);

            Log::info('[SubscriptionChange] Pending change applied', [
                'klien_id' => $klien->id,
                'old_subscription_id' => $oldSubscription->id,
                'new_subscription_id' => $newSubscription->id,
                'new_plan_id' => $newPlan->id,
            ]);

            return $newSubscription;
        });
    }

    /**
     * Create new subscription (no existing subscription)
     * 
     * @param Klien $klien
     * @param Plan $plan
     * @return array
     */
    public function createNewSubscription(Klien $klien, Plan $plan): array
    {
        return DB::transaction(function () use ($klien, $plan) {
            // Ensure no other active subscription
            Subscription::where('klien_id', $klien->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_EXPIRED]);

            $subscription = new Subscription();
            $subscription->klien_id = $klien->id;
            $subscription->plan_id = $plan->id;
            $subscription->plan_snapshot = $plan->toSnapshot();
            $subscription->price = $plan->price_monthly;
            $subscription->currency = 'IDR';
            $subscription->status = Subscription::STATUS_ACTIVE;
            $subscription->started_at = now();
            $subscription->change_type = Subscription::CHANGE_TYPE_NEW;

            // Calculate expiry
            $durationDays = $plan->toSnapshot()['duration_days'] ?? 30;
            if ($durationDays > 0) {
                $subscription->expires_at = now()->addDays($durationDays);
            }

            $subscription->save();

            $this->clearCache($klien->id);

            Log::info('[SubscriptionChange] New subscription created', [
                'klien_id' => $klien->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
            ]);

            return [
                'success' => true,
                'type' => Subscription::CHANGE_TYPE_NEW,
                'message' => 'Subscription berhasil dibuat.',
                'subscription' => $subscription,
            ];
        });
    }

    /**
     * Renew subscription (same plan, new period)
     * 
     * @param Klien $klien
     * @return array
     */
    public function renewSubscription(Klien $klien): array
    {
        $currentSubscription = $this->getActiveSubscription($klien);

        if (!$currentSubscription) {
            return [
                'success' => false,
                'message' => 'Tidak ada subscription untuk diperpanjang',
            ];
        }

        // Get the plan (from snapshot or original)
        $plan = $currentSubscription->plan;
        
        if (!$plan) {
            return [
                'success' => false,
                'message' => 'Paket tidak ditemukan',
            ];
        }

        return DB::transaction(function () use ($klien, $currentSubscription, $plan) {
            // Create new subscription
            $newSubscription = new Subscription();
            $newSubscription->klien_id = $klien->id;
            $newSubscription->plan_id = $plan->id;
            $newSubscription->plan_snapshot = $plan->toSnapshot(); // Fresh snapshot
            $newSubscription->price = $plan->price_monthly;
            $newSubscription->currency = 'IDR';
            $newSubscription->status = Subscription::STATUS_ACTIVE;
            $newSubscription->started_at = now();
            $newSubscription->change_type = Subscription::CHANGE_TYPE_RENEWAL;
            $newSubscription->previous_subscription_id = $currentSubscription->id;

            // Calculate expiry
            $durationDays = $plan->toSnapshot()['duration_days'] ?? 30;
            if ($durationDays > 0) {
                $newSubscription->expires_at = now()->addDays($durationDays);
            }

            $newSubscription->save();

            // Mark old as replaced
            $currentSubscription->markReplaced($newSubscription->id);

            $this->clearCache($klien->id);

            Log::info('[SubscriptionChange] Subscription renewed', [
                'klien_id' => $klien->id,
                'old_subscription_id' => $currentSubscription->id,
                'new_subscription_id' => $newSubscription->id,
            ]);

            return [
                'success' => true,
                'type' => Subscription::CHANGE_TYPE_RENEWAL,
                'message' => 'Subscription berhasil diperpanjang.',
                'subscription' => $newSubscription,
            ];
        });
    }

    /**
     * Get current active subscription for klien
     */
    public function getActiveSubscription(Klien $klien): ?Subscription
    {
        return Subscription::where('klien_id', $klien->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Determine if change is upgrade or downgrade
     * 
     * Compare by plan priority (higher = better plan)
     */
    protected function determineChangeType(Subscription $current, Plan $newPlan): string
    {
        $currentPriority = $current->plan_priority;
        $newPriority = $newPlan->priority;

        if ($newPriority > $currentPriority) {
            return Subscription::CHANGE_TYPE_UPGRADE;
        }

        return Subscription::CHANGE_TYPE_DOWNGRADE;
    }

    /**
     * Check if plan change is upgrade
     */
    public function isUpgrade(Subscription $current, Plan $newPlan): bool
    {
        return $this->determineChangeType($current, $newPlan) === Subscription::CHANGE_TYPE_UPGRADE;
    }

    /**
     * Check if plan change is downgrade
     */
    public function isDowngrade(Subscription $current, Plan $newPlan): bool
    {
        return $this->determineChangeType($current, $newPlan) === Subscription::CHANGE_TYPE_DOWNGRADE;
    }

    /**
     * Get subscription change preview
     * 
     * Shows what will happen without making changes.
     */
    public function previewChange(Klien $klien, Plan $newPlan): array
    {
        $currentSubscription = $this->getActiveSubscription($klien);

        if (!$currentSubscription) {
            return [
                'has_current' => false,
                'change_type' => Subscription::CHANGE_TYPE_NEW,
                'message' => 'Akan membuat subscription baru',
                'new_plan' => $newPlan->toSnapshot(),
                'effective_at' => now(),
            ];
        }

        if ($currentSubscription->plan_id === $newPlan->id) {
            return [
                'has_current' => true,
                'change_type' => null,
                'message' => 'Sama dengan paket saat ini',
                'current_plan' => $currentSubscription->plan_snapshot,
            ];
        }

        $changeType = $this->determineChangeType($currentSubscription, $newPlan);

        if ($changeType === Subscription::CHANGE_TYPE_UPGRADE) {
            return [
                'has_current' => true,
                'change_type' => Subscription::CHANGE_TYPE_UPGRADE,
                'message' => 'Upgrade - berlaku sekarang',
                'current_plan' => $currentSubscription->plan_snapshot,
                'new_plan' => $newPlan->toSnapshot(),
                'effective_at' => now(),
                'immediate' => true,
            ];
        }

        return [
            'has_current' => true,
            'change_type' => Subscription::CHANGE_TYPE_DOWNGRADE,
            'message' => 'Downgrade - berlaku periode berikutnya',
            'current_plan' => $currentSubscription->plan_snapshot,
            'new_plan' => $newPlan->toSnapshot(),
            'effective_at' => $currentSubscription->expires_at ?? now()->addMonth(),
            'immediate' => false,
        ];
    }

    /**
     * Clear subscription cache
     */
    protected function clearCache(int $klienId): void
    {
        Cache::forget("subscription:policy:{$klienId}");
        Cache::forget("subscription:active:{$klienId}");
    }
}
