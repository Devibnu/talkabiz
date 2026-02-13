<?php

namespace App\Jobs\Traits;

use App\Models\User;
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\Log;

/**
 * Trait EnforcePlanLimit
 * 
 * Trait untuk enforce plan limits di level Job (LAST DEFENSE).
 * Digunakan di semua Job yang mengirim pesan.
 * 
 * PRINSIP:
 * 1. Check limit sebelum kirim
 * 2. Jika limit exceeded → abort job (jangan retry)
 * 3. Log event quota_exceeded
 * 4. Consume quota setelah berhasil kirim
 * 
 * @author Senior Laravel SaaS Architect
 */
trait EnforcePlanLimit
{
    /**
     * Check plan limit before sending
     * 
     * @param int|null $userId
     * @param int $messageCount
     * @return array{allowed: bool, user?: User, reason?: string}
     */
    protected function checkPlanLimit(?int $userId, int $messageCount = 1): array
    {
        // Skip if no user ID
        if (!$userId) {
            Log::channel('whatsapp')->warning('EnforcePlanLimit: No user ID provided', [
                'job' => get_class($this),
            ]);
            // FAILSAFE: Block if no user context
            return [
                'allowed' => false,
                'reason' => 'no_user_context',
            ];
        }
        
        $user = User::find($userId);
        
        if (!$user) {
            Log::channel('whatsapp')->warning('EnforcePlanLimit: User not found', [
                'user_id' => $userId,
            ]);
            return [
                'allowed' => false,
                'reason' => 'user_not_found',
            ];
        }
        
        /** @var PlanLimitService $limitService */
        $limitService = app(PlanLimitService::class);
        
        $check = $limitService->canSendMessage($user, $messageCount);
        
        if (!$check['allowed']) {
            Log::channel('whatsapp')->warning('EnforcePlanLimit: Quota exceeded in Job', [
                'user_id' => $userId,
                'user_email' => $user->email,
                'code' => $check['code'] ?? 'unknown',
                'message' => $check['message'] ?? '',
                'job' => get_class($this),
            ]);
            
            return [
                'allowed' => false,
                'user' => $user,
                'reason' => $check['code'] ?? 'quota_exceeded',
                'message' => $check['message'] ?? 'Kuota habis',
            ];
        }
        
        return [
            'allowed' => true,
            'user' => $user,
        ];
    }
    
    /**
     * Consume quota after successful send
     * 
     * @param User $user
     * @param int $count
     * @return bool
     */
    protected function consumePlanQuota(User $user, int $count = 1): bool
    {
        /** @var PlanLimitService $limitService */
        $limitService = app(PlanLimitService::class);
        
        return $limitService->consumeQuota($user, $count);
    }
    
    /**
     * Abort job due to quota exceeded
     * 
     * Tidak throw exception - langsung mark job sebagai selesai
     * karena retry tidak akan berhasil (kuota tetap habis)
     * 
     * @param string $reason
     * @param array $context
     * @return void
     */
    protected function abortDueToQuotaExceeded(string $reason, array $context = []): void
    {
        Log::channel('whatsapp')->error('EnforcePlanLimit: Job ABORTED - quota exceeded', array_merge([
            'reason' => $reason,
            'job' => get_class($this),
        ], $context));
        
        // Don't throw - just return (job will be marked as processed)
        // Retry tidak akan membantu karena kuota tetap habis
    }
}
