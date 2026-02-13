<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * SaldoLedger Model
 * 
 * SINGLE SOURCE OF TRUTH untuk semua mutasi saldo.
 * 
 * ATURAN MUTLAK:
 * 1. Semua perubahan saldo WAJIB tercatat di ledger
 * 2. Ledger bersifat IMMUTABLE (tidak bisa diubah/dihapus)
 * 3. Balance dihitung dari ledger, bukan field manual
 * 4. Setiap entry harus ada balance_before & balance_after untuk audit
 * 
 * TYPES:
 * - topup: Credit dari pembayaran invoice
 * - debit_message: Debit untuk kirim pesan WhatsApp
 * - refund: Refund untuk pesan yang gagal
 * - adjustment: Manual adjustment (admin only)
 * - bonus: Bonus credit dari sistem
 * - penalty: Penalty debit dari sistem
 */
class SaldoLedger extends Model
{
    protected $table = 'saldo_ledger';

    // IMMUTABLE: No update allowed, only insert
    protected $guarded = ['id', 'created_at'];
    
    // NO UPDATED_AT - records are immutable
    const UPDATED_AT = null;

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'processed_at' => 'datetime'
    ];

    // Type constants
    const TYPE_TOPUP = 'topup';
    const TYPE_DEBIT_MESSAGE = 'debit_message';
    const TYPE_REFUND = 'refund';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_BONUS = 'bonus';
    const TYPE_PENALTY = 'penalty';

    // Direction constants
    const DIRECTION_CREDIT = 'credit';
    const DIRECTION_DEBIT = 'debit';

    // Reference type constants
    const REF_INVOICE = 'invoice';
    const REF_MESSAGE_DISPATCH = 'message_dispatch';
    const REF_MANUAL = 'manual';
    const REF_SYSTEM = 'system';

    protected static function boot()
    {
        parent::boot();

        // IMMUTABLE PROTECTION: Prevent any updates or deletes
        static::updating(function () {
            throw new Exception('SaldoLedger records are immutable and cannot be updated');
        });

        static::deleting(function () {
            throw new Exception('SaldoLedger records are immutable and cannot be deleted');
        });

        // Auto-generate ledger ID
        static::creating(function ($ledger) {
            if (empty($ledger->ledger_id)) {
                $ledger->ledger_id = static::generateLedgerId();
            }
            
            if (empty($ledger->processed_at)) {
                $ledger->processed_at = now();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ==================== SCOPES ====================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeCredit($query)
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    public function scopeDebit($query)
    {
        return $query->where('direction', self::DIRECTION_DEBIT);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOrderedByProcessedAt($query)
    {
        return $query->orderBy('processed_at')->orderBy('id');
    }

    // ==================== ACCESSORS ====================

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    public function getSignedAmountAttribute(): float
    {
        return $this->isCredit() ? $this->amount : -$this->amount;
    }

    public function getFormattedAmountAttribute(): string
    {
        $sign = $this->isCredit() ? '+' : '-';
        return $sign . 'Rp ' . number_format($this->amount, 0, ',', '.');
    }

    // ==================== STATIC FACTORY METHODS ====================

    /**
     * Create credit entry (topup, refund, bonus, etc)
     */
    public static function createCredit(
        int $userId,
        string $type,
        float $amount,
        string $description,
        array $options = []
    ): self {
        return static::createEntry(
            userId: $userId,
            type: $type,
            direction: self::DIRECTION_CREDIT,
            amount: $amount,
            description: $description,
            options: $options
        );
    }

    /**
     * Create debit entry (message send, penalty, etc)
     */
    public static function createDebit(
        int $userId,
        string $type,
        float $amount,
        string $description,
        array $options = []
    ): self {
        return static::createEntry(
            userId: $userId,
            type: $type,
            direction: self::DIRECTION_DEBIT,
            amount: $amount,
            description: $description,
            options: $options
        );
    }

    /**
     * Core method to create ledger entry with balance calculation
     */
    protected static function createEntry(
        int $userId,
        string $type,
        string $direction,
        float $amount,
        string $description,
        array $options = []
    ): self {
        return DB::transaction(function () use ($userId, $type, $direction, $amount, $description, $options) {
            // Get current balance with row lock
            $currentBalance = static::getCurrentBalanceWithLock($userId);
            
            // Calculate new balance
            $signedAmount = $direction === self::DIRECTION_CREDIT ? $amount : -$amount;
            $newBalance = $currentBalance + $signedAmount;

            // Validate business rules
            static::validateLedgerEntry($type, $direction, $amount, $newBalance, $options);

            // Create ledger entry
            return static::create([
                'user_id' => $userId,
                'klien_id' => $options['klien_id'] ?? null,
                'type' => $type,
                'direction' => $direction,
                'amount' => abs($amount), // Always positive in storage
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'reference_type' => $options['reference_type'] ?? null,
                'reference_id' => $options['reference_id'] ?? null,
                'invoice_id' => $options['invoice_id'] ?? null,
                'transaction_code' => $options['transaction_code'] ?? null,
                'description' => $description,
                'metadata' => $options['metadata'] ?? null,
                'created_by_ip' => $options['ip'] ?? request()?->ip(),
                'created_by_user_id' => $options['created_by'] ?? auth()?->id(),
                'processed_at' => $options['processed_at'] ?? now()
            ]);
        });
    }

    /**
     * Get current balance with row lock (for atomic operations)
     */
    public static function getCurrentBalanceWithLock(int $userId): float
    {
        // Get the latest balance from the last ledger entry with lock
        $lastEntry = static::where('user_id', $userId)
            ->orderBy('processed_at', 'desc')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        return $lastEntry ? $lastEntry->balance_after : 0.0;
    }

    /**
     * Get current balance (read-only, no lock)
     */
    public static function getCurrentBalance(int $userId): float
    {
        $lastEntry = static::where('user_id', $userId)
            ->orderBy('processed_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastEntry ? $lastEntry->balance_after : 0.0;
    }

    /**
     * Calculate balance at specific point in time
     */
    public static function getBalanceAt(int $userId, \DateTime $timestamp): float
    {
        $lastEntry = static::where('user_id', $userId)
            ->where('processed_at', '<=', $timestamp)
            ->orderBy('processed_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastEntry ? $lastEntry->balance_after : 0.0;
    }

    /**
     * Validate business rules for ledger entry
     */
    protected static function validateLedgerEntry(
        string $type,
        string $direction,
        float $amount,
        float $newBalance,
        array $options
    ): void {
        // Amount must be positive
        if ($amount <= 0) {
            throw new Exception("Ledger amount must be positive, got: {$amount}");
        }

        // Balance cannot go negative for debit operations (unless admin override)
        if ($direction === self::DIRECTION_DEBIT && $newBalance < 0) {
            $adminOverride = $options['admin_override'] ?? false;
            if (!$adminOverride) {
                throw new \App\Exceptions\InsufficientBalanceException(
                    currentBalance: (int) ($newBalance + $amount), // Balance before debit
                    requiredAmount: (int) $amount
                );
            }
        }

        // Type-specific validations
        switch ($type) {
            case self::TYPE_TOPUP:
                if ($direction !== self::DIRECTION_CREDIT) {
                    throw new Exception('Topup entries must be credit direction');
                }
                break;

            case self::TYPE_DEBIT_MESSAGE:
                if ($direction !== self::DIRECTION_DEBIT) {
                    throw new Exception('Message debit entries must be debit direction');
                }
                if (empty($options['transaction_code'])) {
                    throw new Exception('Message debit entries require transaction_code');
                }
                break;

            case self::TYPE_REFUND:
                if ($direction !== self::DIRECTION_CREDIT) {
                    throw new Exception('Refund entries must be credit direction');
                }
                break;
        }
    }

    /**
     * Generate unique ledger ID
     */
    protected static function generateLedgerId(): string
    {
        $prefix = 'LED';
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));
        
        return "{$prefix}-{$timestamp}-{$random}";
    }

    // ==================== BALANCE SUMMARY METHODS ====================

    /**
     * Get balance summary for user
     */
    public static function getBalanceSummary(int $userId): array
    {
        $currentBalance = static::getCurrentBalance($userId);
        
        $summary = static::where('user_id', $userId)
            ->selectRaw('
                type,
                direction,
                SUM(amount) as total_amount,
                COUNT(*) as transaction_count
            ')
            ->groupBy(['type', 'direction'])
            ->get()
            ->groupBy('type')
            ->map(function ($typeGroup) {
                $credits = $typeGroup->where('direction', self::DIRECTION_CREDIT)->first();
                $debits = $typeGroup->where('direction', self::DIRECTION_DEBIT)->first();
                
                return [
                    'credits' => $credits ? $credits->total_amount : 0,
                    'debits' => $debits ? $debits->total_amount : 0,
                    'net' => ($credits ? $credits->total_amount : 0) - ($debits ? $debits->total_amount : 0),
                    'transaction_count' => ($credits ? $credits->transaction_count : 0) + ($debits ? $debits->transaction_count : 0)
                ];
            })
            ->toArray();

        return [
            'current_balance' => $currentBalance,
            'formatted_balance' => 'Rp ' . number_format($currentBalance, 0, ',', '.'),
            'by_type' => $summary
        ];
    }
}