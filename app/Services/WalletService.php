<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * @var PricingService
     */
    protected $pricingService;

    /**
     * @var RiskEngine
     */
    protected $riskEngine;

    /**
     * WalletService constructor.
     * 
     * @param PricingService $pricingService
     * @param RiskEngine $riskEngine
     */
    public function __construct(PricingService $pricingService, RiskEngine $riskEngine)
    {
        $this->pricingService = $pricingService;
        $this->riskEngine = $riskEngine;
    }

    /**
     * Get wallet for user (READ ONLY - does NOT auto-create).
     *
     * IMPORTANT: This method ONLY retrieves existing wallet.
     * If wallet doesn't exist, it throws an exception.
     * Wallet should be created via createWalletOnce() during onboarding.
     *
     * @param User $user
     * @return Wallet
     * @throws \RuntimeException if wallet not found
     */
    public function getWallet(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)->where('is_active', true)->first();

        if (!$wallet) {
            throw new \RuntimeException(
                "Wallet not found for user ID {$user->id}. " .
                "Wallet should be created during onboarding process. " .
                "Please complete onboarding first."
            );
        }

        return $wallet;
    }

    /**
     * Create wallet for user (ONCE - during onboarding only).
     *
     * CRITICAL RULES:
     * 1. User MUST have onboarding_complete = true
     * 2. Wallet MUST NOT already exist
     * 3. Uses DB transaction + lockForUpdate (race condition safe)
     * 4. Should ONLY be called from OnboardingController
     *
     * @param User $user
     * @return Wallet
     * @throws \RuntimeException if validation fails
     */
    public function createWalletOnce(User $user): Wallet
    {
        // VALIDATION 1: User must complete onboarding first
        if (!$user->onboarding_complete) {
            \Log::critical('WALLET CREATION BLOCKED: User has not completed onboarding', [
                'user_id' => $user->id,
                'onboarding_complete' => $user->onboarding_complete,
            ]);

            throw new \RuntimeException(
                "Cannot create wallet: User ID {$user->id} has not completed onboarding. " .
                "Wallet can only be created after onboarding_complete = true."
            );
        }

        return DB::transaction(function () use ($user) {
            // VALIDATION 2: Check if wallet already exists (with row lock)
            $existing = Wallet::lockForUpdate()
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                \Log::warning('WALLET CREATION BLOCKED: Wallet already exists', [
                    'user_id' => $user->id,
                    'wallet_id' => $existing->id,
                    'existing_balance' => $existing->balance,
                ]);

                throw new \RuntimeException(
                    "Cannot create wallet: User ID {$user->id} already has a wallet (ID: {$existing->id}). " .
                    "Each user can only have ONE wallet."
                );
            }

            // CREATE WALLET (race condition safe via transaction + lock)
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'total_topup' => 0,
                'total_spent' => 0,
                'currency' => 'IDR',
                'is_active' => true,
            ]);

            \Log::info('✅ WALLET CREATED', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'created_at' => $wallet->created_at,
            ]);

            return $wallet;
        });
    }

    /**
     * Get or create wallet by user ID (for backward compatibility).
     *
     * @deprecated Use getWallet(User $user) instead. This method will be removed.
     * Wallet creation should only happen via createWalletOnce() during onboarding.
     *
     * IMPORTANT VALIDATIONS:
     * 1. User MUST exist in database before wallet creation
     * 2. Race condition safe via firstOrCreate (atomic)
     * 3. Foreign key safe - validates user_id exists
     *
     * For multi-tenant SaaS:
     * - If passing klien_id: validate Klien exists and has user
     * - Otherwise: validate User exists
     *
     * @param int $userId User ID (users.id) - NOT klien_id!
     * @return Wallet
     * @throws \InvalidArgumentException if user doesn't exist
     * @throws \RuntimeException if wallet creation fails
     */
    public function getOrCreateWallet(int $userId): Wallet
    {
        // CRITICAL VALIDATION: User MUST exist before creating wallet
        // This prevents FK constraint violation
        $userExists = User::where('id', $userId)->exists();
        
        if (!$userExists) {
            throw new \InvalidArgumentException(
                "Cannot create wallet: User ID {$userId} does not exist in database. " .
                "Wallet can only be created for valid, existing users. " .
                "Make sure user is authenticated and database record exists."
            );
        }

        try {
            return Wallet::firstOrCreate(
                ['user_id' => $userId],
                [
                    'balance' => 0,
                    'total_topup' => 0,
                    'total_spent' => 0,
                    'currency' => 'IDR',
                    'is_active' => true,
                ]
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to create or retrieve wallet for user ID {$userId}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get wallet balance for user.
     *
     * @param User $user
     * @return float
     */
    public function getBalance(User $user): float
    {
        $wallet = $this->getWallet($user);
        return (float) $wallet->balance;
    }

    /**
     * Get monthly usage (total spent this month).
     * 
     * Accepts either User object or user ID (int) for backward compatibility.
     *
     * @param User|int $userOrId User object or user ID
     * @return float
     * @throws \RuntimeException if wallet not found
     */
    public function getMonthlyUsage($userOrId): float
    {
        // Handle both User object and int ID
        if ($userOrId instanceof User) {
            $wallet = $this->getWallet($userOrId);
        } else {
            // Convert int ID to User object
            $user = User::find($userOrId);
            if (!$user) {
                throw new \RuntimeException("User ID {$userOrId} not found");
            }
            $wallet = $this->getWallet($user);
        }
        
        // Calculate total spent this month
        return WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_USAGE)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum(DB::raw('ABS(amount)'));
    }

    /**
     * Top up saldo user.
     *
     * @param int    $userId
     * @param int    $amount  Jumlah topup (positif, dalam Rupiah)
     * @param string $source  Sumber topup: 'bank_transfer', 'manual', 'refund', dll
     * @return WalletTransaction
     *
     * @throws \InvalidArgumentException
     */
    public function topup(int $userId, int $amount, string $source, ?string $idempotencyKey = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Jumlah topup harus lebih dari 0.');
        }

        // Generate idempotency key jika tidak disediakan
        $idempotencyKey = $idempotencyKey ?? "topup_{$userId}_{$source}_" . md5($amount . now()->timestamp);

        // Cek duplikat di DB (source of truth)
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing; // Sudah pernah diproses, return record lama
        }

        return DB::transaction(function () use ($userId, $amount, $source, $idempotencyKey) {
            // Double-check di dalam transaction (race condition safe)
            $existing = WalletTransaction::lockForUpdate()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            // Get existing wallet (do NOT auto-create)
            $wallet = Wallet::lockForUpdate()->where('user_id', $userId)->first();

            if (!$wallet) {
                throw new \RuntimeException(
                    "Cannot process topup: Wallet not found for user ID {$userId}. " .
                    "User must complete onboarding first to create wallet."
                );
            }

            $balanceBefore = $wallet->balance;
            $wallet->balance += $amount;
            $wallet->total_topup += $amount;
            $wallet->last_topup_at = now();
            $wallet->last_transaction_at = now();
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => WalletTransaction::TYPE_TOPUP,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $wallet->balance,
                'currency'       => 'IDR',
                'description'    => "Topup saldo via {$source}",
                'status'         => WalletTransaction::STATUS_COMPLETED,
                'processed_at'   => now(),
                'metadata'       => ['source' => $source],
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Potong saldo user — ATOMIC (saldo + ledger dalam 1 transaksi).
     *
     * Jika salah satu gagal → rollback SEMUA.
     * Saldo TIDAK BOLEH menjadi negatif.
     *
     * @param int    $userId
     * @param int    $amount        Jumlah potongan (positif, dalam Rupiah)
     * @param string $referenceType Tipe referensi: 'broadcast', 'campaign', 'single_message', dll
     * @param int    $referenceId   ID referensi: broadcast_id, campaign_id, message_id
     * @return WalletTransaction
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException jika saldo tidak cukup / wallet tidak ada
     */
    public function deduct(int $userId, int $amount, string $referenceType, int $referenceId): WalletTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Jumlah potongan harus lebih dari 0.');
        }

        // Idempotency key = kombinasi unik aksi
        $idempotencyKey = "usage_{$referenceType}_{$referenceId}";

        // Cek duplikat di DB (source of truth, bukan cache)
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing; // Sudah pernah dipotong, skip
        }

        return DB::transaction(function () use ($userId, $amount, $referenceType, $referenceId, $idempotencyKey) {
            // Double-check di dalam transaction (race condition safe)
            $existing = WalletTransaction::lockForUpdate()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            // 1. Lock wallet row — mencegah race condition
            $wallet = Wallet::lockForUpdate()->where('user_id', $userId)->first();

            if (!$wallet || !$wallet->is_active) {
                throw new \RuntimeException('Wallet tidak ditemukan atau tidak aktif.');
            }

            // 2. Validasi saldo (anti negatif)
            if ($wallet->balance < $amount) {
                throw new \RuntimeException(
                    "Saldo tidak cukup. Saldo: Rp " . number_format($wallet->balance, 0, ',', '.') .
                    ", Dibutuhkan: Rp " . number_format($amount, 0, ',', '.')
                );
            }

            $balanceBefore = $wallet->balance;

            // 3. Kurangi saldo
            $wallet->balance -= $amount;
            $wallet->total_spent += $amount;
            $wallet->last_transaction_at = now();
            $wallet->save();

            // Invalidate wallet balance cache
            app(WalletCacheService::class)->clear($userId);

            // 4. Double-check: pastikan balance tidak negatif setelah save
            if ($wallet->balance < 0) {
                throw new \RuntimeException('Saldo menjadi negatif setelah potongan. Transaksi dibatalkan.');
            }

            // 5. Catat ke ledger — WAJIB dalam transaksi yang sama
            return WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => WalletTransaction::TYPE_USAGE,
                'amount'         => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $wallet->balance,
                'currency'       => 'IDR',
                'description'    => ucfirst(str_replace('_', ' ', $referenceType)) . " message usage",
                'reference_type' => $referenceType,
                'reference_id'   => (string) $referenceId,
                'status'         => WalletTransaction::STATUS_COMPLETED,
                'processed_at'   => now(),
                'metadata'       => [
                    'reference_type' => $referenceType,
                    'reference_id'   => $referenceId,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Deduct saldo dengan pricing multiplier berdasarkan Business Type
     * 
     * ARCHITECTURE:
     * - Base price dari config/database
     * - Multiplier dari business_types table
     * - Final cost = base × multiplier
     * - Risk validation BEFORE deduction
     * - Idempotent & race-condition safe
     * 
     * RISK CHECKS:
     * - Validates minimum balance buffer
     * - Blocks high-risk transactions requiring approval
     * - All rules enforced at service layer
     * 
     * EXAMPLE:
     * - Base price: 100
     * - Perorangan (1.00): 100
     * - CV (0.95): 95 (5% discount)
     * - PT (0.90): 90 (10% discount)
     * 
     * @param int    $userId User ID
     * @param float  $baseAmount Base amount sebelum multiplier
     * @param string $referenceType Transaction reference type
     * @param int    $referenceId Transaction reference ID
     * @param bool   $skipRiskCheck Skip risk validation (for admin operations)
     * @return WalletTransaction
     * 
     * @throws \InvalidArgumentException if amount invalid
     * @throws \RuntimeException if insufficient balance, wallet not found, or risk check fails
     */
    public function deductWithPricing(
        int $userId,
        float $baseAmount,
        string $referenceType,
        int $referenceId,
        bool $skipRiskCheck = false
    ): WalletTransaction {
        if ($baseAmount <= 0) {
            throw new \InvalidArgumentException('Base amount harus lebih dari 0.');
        }

        // Get user for pricing calculation
        $user = User::findOrFail($userId);

        // Calculate final cost with business type multiplier
        $finalCost = $this->pricingService->calculateFinalCost($baseAmount, $user);

        // RISK CHECK: Validate transaction against risk rules
        if (!$skipRiskCheck) {
            $this->riskEngine->validateTransaction($user, $finalCost);
        }

        // Get pricing info for logging
        $pricingInfo = $this->pricingService->getPricingInfo($user);
        $riskProfile = $this->riskEngine->getRiskProfile($user);

        // Idempotency key
        $idempotencyKey = "usage_{$referenceType}_{$referenceId}";

        // Check for duplicate
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use (
            $userId,
            $baseAmount,
            $finalCost,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $pricingInfo
        ) {
            // Double-check in transaction
            $existing = WalletTransaction::lockForUpdate()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            // Lock wallet row
            $wallet = Wallet::lockForUpdate()->where('user_id', $userId)->first();

            if (!$wallet || !$wallet->is_active) {
                throw new \RuntimeException('Wallet tidak ditemukan atau tidak aktif.');
            }

            // Validate balance
            if ($wallet->balance < $finalCost) {
                throw new \RuntimeException(
                    "Saldo tidak cukup. Saldo: Rp " . number_format($wallet->balance, 0, ',', '.') .
                    ", Dibutuhkan: Rp " . number_format($finalCost, 0, ',', '.')
                );
            }

            $balanceBefore = $wallet->balance;

            // Deduct final cost
            $wallet->balance -= $finalCost;
            $wallet->total_spent += $finalCost;
            $wallet->last_transaction_at = now();
            $wallet->save();

            // Safety check
            if ($wallet->balance < 0) {
                throw new \RuntimeException('Saldo menjadi negatif setelah potongan. Transaksi dibatalkan.');
            }

            // Create transaction record with pricing metadata
            return WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => WalletTransaction::TYPE_USAGE,
                'amount'         => -$finalCost,
                'balance_before' => $balanceBefore,
                'balance_after'  => $wallet->balance,
                'currency'       => 'IDR',
                'description'    => ucfirst(str_replace('_', ' ', $referenceType)) . " message usage",
                'reference_type' => $referenceType,
                'reference_id'   => (string) $referenceId,
                'status'         => WalletTransaction::STATUS_COMPLETED,
                'processed_at'   => now(),
                'metadata'       => [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'base_amount' => $baseAmount,
                    'final_cost' => $finalCost,
                    'pricing_multiplier' => $pricingInfo['multiplier'],
                    'business_type' => $pricingInfo['business_type_code'] ?? null,
                    'discount_percentage' => $pricingInfo['discount_percentage'] ?? 0,
                    'risk_level' => $riskProfile['risk_level'] ?? null,
                    'risk_validated' => !$skipRiskCheck,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Cek apakah saldo user cukup.
     *
     * @param int $userId
     * @param int $amount
     * @return bool
     */
    public function hasEnoughBalance(int $userId, int $amount): bool
    {
        $wallet = Wallet::where('user_id', $userId)->where('is_active', true)->first();

        if (!$wallet) {
            return false;
        }

        return $wallet->balance >= $amount;
    }

    /**
     * Check if user has enough balance untuk base amount (dengan pricing multiplier)
     * 
     * @param int   $userId
     * @param float $baseAmount Base amount before multiplier
     * @return bool
     */
    public function hasEnoughBalanceForBase(int $userId, float $baseAmount): bool
    {
        try {
            $user = User::findOrFail($userId);
            $wallet = Wallet::where('user_id', $userId)->where('is_active', true)->first();

            if (!$wallet) {
                return false;
            }

            // Calculate final cost with multiplier
            $finalCost = $this->pricingService->calculateFinalCost($baseAmount, $user);

            return $wallet->balance >= $finalCost;
        } catch (\Exception $e) {
            \Log::error('Error checking balance with pricing', [
                'user_id' => $userId,
                'base_amount' => $baseAmount,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get calculated final cost for user (preview before deduction)
     * 
     * @param int   $userId
     * @param float $baseAmount
     * @return array ['base' => float, 'multiplier' => float, 'final' => int]
     */
    public function calculateCostPreview(int $userId, float $baseAmount): array
    {
        $user = User::findOrFail($userId);
        $pricingInfo = $this->pricingService->getPricingInfo($user);
        $finalCost = $this->pricingService->calculateFinalCost($baseAmount, $user);

        return [
            'base_amount' => $baseAmount,
            'multiplier' => $pricingInfo['multiplier'],
            'final_cost' => $finalCost,
            'business_type' => $pricingInfo['business_type_name'] ?? 'Unknown',
            'discount_percentage' => $pricingInfo['discount_percentage'] ?? 0,
            'savings' => (int) round($baseAmount - $finalCost),
        ];
    }

    // ============== BACKWARD COMPATIBILITY METHODS ==============

    /**
     * Get transaction history for user (backward compatibility)
     *
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws \RuntimeException if wallet not found
     */
    public function getTransactionHistory(int $userId, int $limit = 20)
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("User ID {$userId} not found");
        }
        
        $wallet = $this->getWallet($user);
        
        return WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get pending topups (legacy TransaksiSaldo model)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingTopUps()
    {
        // Check if TransaksiSaldo model exists
        if (!class_exists(\App\Models\TransaksiSaldo::class)) {
            return collect([]);
        }

        return \App\Models\TransaksiSaldo::where('jenis', 'topup')
            ->where('status_topup', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Confirm topup (legacy TransaksiSaldo model)
     *
     * @param int $transaksiId
     * @param int $confirmedBy
     * @return mixed
     * @throws \Exception
     */
    public function confirmTopUp(int $transaksiId, int $confirmedBy)
    {
        if (!class_exists(\App\Models\TransaksiSaldo::class)) {
            throw new \Exception('TransaksiSaldo model tidak ditemukan');
        }

        $transaksi = \App\Models\TransaksiSaldo::find($transaksiId);
        
        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan');
        }

        if ($transaksi->status_topup !== 'pending') {
            throw new \Exception('Transaksi sudah diproses');
        }

        return DB::transaction(function () use ($transaksi, $confirmedBy) {
            // Update transaction status
            $transaksi->status_topup = 'paid';
            $transaksi->verified_by = $confirmedBy;
            $transaksi->verified_at = now();
            
            // CRITICAL FIX: Use pengguna_id (actual user), NOT klien_id
            // klien_id references Klien table, but Wallet.user_id FK to users.id
            // pengguna_id is the actual User who initiated the topup
            $userId = $transaksi->pengguna_id;
            
            if (!$userId) {
                throw new \Exception(
                    'Cannot sync topup to new wallet: Transaction has no valid user ID. ' .
                    'Old transaction data may be incomplete.'
                );
            }
            
            // Validate user exists before updating wallet
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception(
                    "Cannot sync topup to new wallet: User ID {$userId} does not exist in database. " .
                    "Transaction will be marked as paid but wallet won't be updated."
                );
            }
            
            // Get wallet - for legacy data migration, use getOrCreateWallet
            // This is one of the few exceptions where auto-create is acceptable
            // because we're syncing OLD data that predates the onboarding flow
            $wallet = $this->getOrCreateWallet($userId);
            $transaksi->saldo_sesudah = $wallet->balance + $transaksi->nominal;
            $transaksi->save();
            
            // Add to wallet
            $wallet->balance += $transaksi->nominal;
            $wallet->total_topup += $transaksi->nominal;
            $wallet->last_topup_at = now();
            $wallet->last_transaction_at = now();
            $wallet->save();
            
            // Create WalletTransaction record
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $userId, // Use validated userId, not klien_id
                'type' => WalletTransaction::TYPE_TOPUP,
                'amount' => $transaksi->nominal,
                'balance_before' => $wallet->balance - $transaksi->nominal,
                'balance_after' => $wallet->balance,
                'currency' => 'IDR',
                'description' => 'Manual topup approval: ' . $transaksi->kode_transaksi,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'processed_at' => now(),
                'metadata' => [
                    'legacy_transaction_id' => $transaksi->id,
                    'legacy_klien_id' => $transaksi->klien_id,
                    'confirmed_by' => $confirmedBy,
                ],
            ]);
            
            return $transaksi;
        });
    }

    /**
     * Reject topup (legacy TransaksiSaldo model)
     *
     * @param int $transaksiId
     * @param int $rejectedBy
     * @param string $catatan
     * @return mixed
     * @throws \Exception
     */
    public function rejectTopUp(int $transaksiId, int $rejectedBy, string $catatan = '')
    {
        if (!class_exists(\App\Models\TransaksiSaldo::class)) {
            throw new \Exception('TransaksiSaldo model tidak ditemukan');
        }

        $transaksi = \App\Models\TransaksiSaldo::find($transaksiId);
        
        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan');
        }

        if ($transaksi->status_topup !== 'pending') {
            throw new \Exception('Transaksi sudah diproses');
        }

        $transaksi->status_topup = 'rejected';
        $transaksi->verified_by = $rejectedBy;
        $transaksi->verified_at = now();
        $transaksi->keterangan .= ' | Ditolak: ' . $catatan;
        $transaksi->save();

        return $transaksi;
    }
}
