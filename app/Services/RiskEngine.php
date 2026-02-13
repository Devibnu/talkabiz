<?php

namespace App\Services;

use App\Models\User;
use App\Models\Klien;
use App\Models\BusinessType;
use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RiskEngine - Business Risk Management Service
 * 
 * ARCHITECTURE:
 * - Risk-based fraud prevention
 * - Business type risk categorization
 * - Automated approval workflows
 * - Balance buffer enforcement
 * 
 * RISK LEVELS:
 * - LOW: Verified businesses (PT, CV) → No buffer, auto-approve
 * - MEDIUM: Individual businesses → Minimum buffer required
 * - HIGH: Unknown/new businesses → Manual approval required
 */
class RiskEngine
{
    const RISK_LOW = 'low';
    const RISK_MEDIUM = 'medium';
    const RISK_HIGH = 'high';

    const LARGE_TRANSACTION_THRESHOLD = 500000; // Rp 500k

    /**
     * Get risk profile for user
     * 
     * @param User $user
     * @return array Risk profile
     * @throws \RuntimeException if klien not found
     */
    public function getRiskProfile(User $user): array
    {
        $klien = $user->klien;
        
        if (!$klien) {
            throw new \RuntimeException(
                "Klien profile not found for user ID {$user->id}. " .
                "Cannot determine risk profile."
            );
        }

        // Get business type with caching
        $businessTypeCode = $klien->tipe_bisnis;
        $cacheKey = "business_type_risk:{$businessTypeCode}";
        
        $riskData = Cache::remember($cacheKey, 300, function () use ($businessTypeCode) {
            $businessType = BusinessType::where('code', $businessTypeCode)
                ->where('is_active', true)
                ->first();
            
            if (!$businessType) {
                // Fallback to high risk if business type not found
                Log::warning('Business type not found, using high risk fallback', [
                    'business_type_code' => $businessTypeCode,
                ]);
                
                return [
                    'risk_level' => self::RISK_HIGH,
                    'minimum_balance_buffer' => 100000,
                    'requires_manual_approval' => true,
                ];
            }
            
            return [
                'risk_level' => $businessType->risk_level,
                'minimum_balance_buffer' => $businessType->minimum_balance_buffer,
                'requires_manual_approval' => $businessType->requires_manual_approval,
            ];
        });

        return array_merge($riskData, [
            'has_klien' => true,
            'business_type_code' => $klien->tipe_bisnis,
            'business_type_name' => $klien->businessType?->name ?? ucfirst($klien->tipe_bisnis),
        ]);
    }

    /**
     * Check if transaction is allowed based on risk rules
     * 
     * @param User $user
     * @param int $amount Transaction amount
     * @return array ['allowed' => bool, 'reason' => string, 'requires_approval' => bool]
     */
    public function checkTransactionRisk(User $user, int $amount): array
    {
        try {
            $riskProfile = $this->getRiskProfile($user);
            $wallet = Wallet::where('user_id', $user->id)->first();

            if (!$wallet) {
                return [
                    'allowed' => false,
                    'reason' => 'Wallet tidak ditemukan',
                    'requires_approval' => false,
                ];
            }

            // Check 1: Minimum balance buffer
            $requiredBuffer = $riskProfile['minimum_balance_buffer'];
            $balanceAfterTransaction = $wallet->balance - $amount;

            if ($balanceAfterTransaction < $requiredBuffer) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Saldo tidak mencukupi. Dibutuhkan minimum buffer Rp %s. Saldo setelah transaksi: Rp %s',
                        number_format($requiredBuffer, 0, ',', '.'),
                        number_format($balanceAfterTransaction, 0, ',', '.')
                    ),
                    'requires_approval' => false,
                    'required_buffer' => $requiredBuffer,
                    'current_balance' => $wallet->balance,
                    'balance_after' => $balanceAfterTransaction,
                ];
            }

            // Check 2: High risk + large transaction = manual approval
            if ($riskProfile['requires_manual_approval'] && $amount >= self::LARGE_TRANSACTION_THRESHOLD) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Transaksi memerlukan approval manual (High risk: %s, Amount: Rp %s)',
                        $riskProfile['business_type_name'],
                        number_format($amount, 0, ',', '.')
                    ),
                    'requires_approval' => true,
                    'risk_level' => $riskProfile['risk_level'],
                    'amount' => $amount,
                    'threshold' => self::LARGE_TRANSACTION_THRESHOLD,
                ];
            }

            // Check 3: Any high-risk business type requires approval for large amounts
            if ($riskProfile['risk_level'] === self::RISK_HIGH && $amount >= self::LARGE_TRANSACTION_THRESHOLD) {
                return [
                    'allowed' => false,
                    'reason' => 'Transaksi besar untuk akun high-risk memerlukan verifikasi manual',
                    'requires_approval' => true,
                    'risk_level' => $riskProfile['risk_level'],
                ];
            }

            // All checks passed
            return [
                'allowed' => true,
                'reason' => 'Transaction approved by risk engine',
                'requires_approval' => false,
                'risk_level' => $riskProfile['risk_level'],
            ];

        } catch (\Exception $e) {
            Log::error('Risk check failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            // Fail-safe: Block transaction on error
            return [
                'allowed' => false,
                'reason' => 'Risk check gagal: ' . $e->getMessage(),
                'requires_approval' => false,
            ];
        }
    }

    /**
     * Validate transaction against risk rules (throws exception if not allowed)
     * 
     * @param User $user
     * @param int $amount
     * @throws \RuntimeException if transaction not allowed
     * @return void
     */
    public function validateTransaction(User $user, int $amount): void
    {
        $riskCheck = $this->checkTransactionRisk($user, $amount);

        if (!$riskCheck['allowed']) {
            Log::warning('Transaction blocked by risk engine', [
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $riskCheck['reason'],
                'requires_approval' => $riskCheck['requires_approval'],
            ]);

            throw new \RuntimeException($riskCheck['reason']);
        }
    }

    /**
     * Get risk statistics for user
     * 
     * @param User $user
     * @return array Statistics
     */
    public function getRiskStatistics(User $user): array
    {
        $riskProfile = $this->getRiskProfile($user);
        $wallet = Wallet::where('user_id', $user->id)->first();

        $currentBalance = $wallet?->balance ?? 0;
        $requiredBuffer = $riskProfile['minimum_balance_buffer'];
        $usableBalance = max(0, $currentBalance - $requiredBuffer);

        return [
            'risk_level' => $riskProfile['risk_level'],
            'business_type' => $riskProfile['business_type_name'],
            'current_balance' => $currentBalance,
            'required_buffer' => $requiredBuffer,
            'usable_balance' => $usableBalance,
            'requires_manual_approval' => $riskProfile['requires_manual_approval'],
            'large_transaction_threshold' => self::LARGE_TRANSACTION_THRESHOLD,
            'can_auto_transact' => $currentBalance > $requiredBuffer,
        ];
    }

    /**
     * Check if amount requires manual approval
     * 
     * @param User $user
     * @param int $amount
     * @return bool
     */
    public function requiresManualApproval(User $user, int $amount): bool
    {
        $riskCheck = $this->checkTransactionRisk($user, $amount);
        return $riskCheck['requires_approval'] ?? false;
    }

    /**
     * Get risk level for business type code (cached)
     * 
     * @param string $businessTypeCode
     * @return string Risk level
     */
    public function getRiskLevelForBusinessType(string $businessTypeCode): string
    {
        $cacheKey = "business_type_risk_level:{$businessTypeCode}";
        
        return Cache::remember($cacheKey, 300, function () use ($businessTypeCode) {
            $businessType = BusinessType::where('code', $businessTypeCode)->first();
            return $businessType?->risk_level ?? self::RISK_HIGH;
        });
    }

    /**
     * Clear risk cache
     * 
     * @param string|null $businessTypeCode
     * @return void
     */
    public function clearCache(?string $businessTypeCode = null): void
    {
        if ($businessTypeCode) {
            Cache::forget("business_type_risk:{$businessTypeCode}");
            Cache::forget("business_type_risk_level:{$businessTypeCode}");
        } else {
            // Clear all business type risk caches
            $codes = BusinessType::pluck('code');
            foreach ($codes as $code) {
                Cache::forget("business_type_risk:{$code}");
                Cache::forget("business_type_risk_level:{$code}");
            }
        }
        
        Log::info('Risk engine cache cleared', [
            'business_type_code' => $businessTypeCode ?? 'all',
        ]);
    }

    /**
     * Get risk summary for all business types (admin panel)
     * 
     * @return array
     */
    public function getRiskSummary(): array
    {
        $businessTypes = BusinessType::active()->ordered()->get();
        
        return $businessTypes->map(function ($type) {
            return [
                'code' => $type->code,
                'name' => $type->name,
                'risk_level' => $type->risk_level,
                'minimum_buffer' => $type->minimum_balance_buffer,
                'requires_approval' => $type->requires_manual_approval,
                'pricing_multiplier' => (float) $type->pricing_multiplier,
                'risk_color' => $this->getRiskColor($type->risk_level),
            ];
        })->toArray();
    }

    /**
     * Get color code for risk level (UI helper)
     * 
     * @param string $riskLevel
     * @return string
     */
    protected function getRiskColor(string $riskLevel): string
    {
        return match($riskLevel) {
            self::RISK_LOW => 'green',
            self::RISK_MEDIUM => 'yellow',
            self::RISK_HIGH => 'red',
            default => 'gray',
        };
    }
}
