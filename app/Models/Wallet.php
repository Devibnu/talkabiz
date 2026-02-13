<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wallet Model - SaaS Billing System
 * 
 * Manages user wallet balance for message consumption billing.
 * Core component of billing-first architecture where:
 * - Packages provide FEATURES only
 * - Messages consume SALDO (this wallet)
 * 
 * @property int $id
 * @property int $user_id
 * @property float $balance Current balance in IDR
 * @property float $total_topup Lifetime topup amount
 * @property float $total_spent Lifetime spent amount
 * @property string $currency
 * @property bool $is_active
 * @property \Carbon\Carbon $last_topup_at
 * @property \Carbon\Carbon $last_transaction_at
 */
class Wallet extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id',
        'balance',
        'total_topup',
        'total_spent',
        'currency',
        'is_active',
        'last_topup_at',
        'last_transaction_at',
    ];
    
    protected $casts = [
        'balance' => 'decimal:2',
        'total_topup' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'is_active' => 'boolean',
        'last_topup_at' => 'datetime',
        'last_transaction_at' => 'datetime',
    ];
    
    // ============== RELATIONSHIPS ==============
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderBy('created_at', 'desc');
    }
    
    public function recentTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)
            ->orderBy('created_at', 'desc')
            ->limit(10);
    }
    
    // ============== SCOPES ==============
    
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
    
    public function scopeWithSufficientBalance(Builder $query, float $amount): Builder
    {
        return $query->where('balance', '>=', $amount);
    }
    
    // ============== WALLET OPERATIONS ==============
    
    /**
     * Check if wallet has sufficient balance
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->balance >= $amount && $this->is_active;
    }
    
    /**
     * Get formatted balance for display
     */
    public function getFormattedBalanceAttribute(): string
    {
        return 'Rp ' . number_format($this->balance, 0, ',', '.');
    }
    
    // ============== BACKWARD COMPATIBILITY ACCESSORS ==============
    
    /**
     * Alias for balance (backward compatibility with DompetSaldo model)
     */
    public function getSaldoTersediaAttribute(): float
    {
        return $this->balance;
    }
    
    /**
     * Held balance (backward compatibility with DompetSaldo model)
     * Currently not implemented in new system, always returns 0
     */
    public function getSaldoTertahanAttribute(): float
    {
        return 0;
    }
    
    /**
     * Wallet status (backward compatibility with DompetSaldo model)
     */
    public function getStatusSaldoAttribute(): string
    {
        if ($this->balance <= 0) return 'habis';
        if ($this->balance <= 10000) return 'kritis';
        if ($this->balance <= 50000) return 'rendah';
        return 'aman';
    }
    
    /**
     * Get balance status (low, medium, high)
     */
    public function getBalanceStatusAttribute(): string
    {
        if ($this->balance <= 10000) return 'low';
        if ($this->balance <= 50000) return 'medium';
        return 'high';
    }
    
    /**
     * Calculate average monthly spending
     */
    public function getAverageMonthlySpendingAttribute(): float
    {
        $transactions = $this->transactions()
            ->where('type', 'usage')
            ->where('created_at', '>=', now()->subMonths(3))
            ->get();
            
        if ($transactions->isEmpty()) return 0;
        
        $totalSpent = $transactions->where('amount', '<', 0)->sum('amount') * -1;
        $months = 3; // Last 3 months
        
        return $totalSpent / $months;
    }
    
    // ============== STATIC HELPER METHODS ==============
    
    /**
     * Get or create wallet for user
     */
    public static function getOrCreateForUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'balance' => 0,
                'total_topup' => 0,
                'total_spent' => 0,
                'currency' => 'IDR',
                'is_active' => true,
            ]
        );
    }
    
    /**
     * Get wallets with low balance for notifications
     */
    public static function withLowBalance(float $threshold = 10000): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
            ->where('balance', '<=', $threshold)
            ->with('user')
            ->get();
    }
}
