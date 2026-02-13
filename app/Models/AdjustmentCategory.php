<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class AdjustmentCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'auto_approval_limit',
        'requires_documentation',
        'is_active',
        'sort_order',
        'risk_level',
        'allowed_directions',
        'documentation_requirements'
    ];

    protected $casts = [
        'auto_approval_limit' => 'decimal:2',
        'requires_documentation' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'allowed_directions' => 'array',
        'documentation_requirements' => 'array'
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Adjustments using this category
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(UserAdjustment::class, 'reason_code', 'code');
    }

    // ==================== SCOPES ====================

    /**
     * Active categories only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Order by sort order
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Filter by risk level
     */
    public function scopeRiskLevel(Builder $query, string $level): Builder
    {
        return $query->where('risk_level', $level);
    }

    /**
     * Categories that support direction
     */
    public function scopeSupportsDirection(Builder $query, string $direction): Builder
    {
        return $query->where(function ($q) use ($direction) {
            $q->whereJsonContains('allowed_directions', $direction)
              ->orWhereJsonContains('allowed_directions', 'both');
        });
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted auto approval limit
     */
    public function getFormattedAutoApprovalLimitAttribute(): string
    {
        return 'Rp ' . number_format($this->auto_approval_limit, 0, ',', '.');
    }

    /**
     * Get risk level color
     */
    public function getRiskColorAttribute(): string
    {
        return match($this->risk_level) {
            'low' => 'success',
            'medium' => 'warning',
            'high' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get risk level label
     */
    public function getRiskLabelAttribute(): string
    {
        return match($this->risk_level) {
            'low' => 'Low Risk',
            'medium' => 'Medium Risk',
            'high' => 'High Risk',
            default => 'Unknown'
        ];
    }

    /**
     * Check if category allows auto approval for amount
     */
    public function allowsAutoApproval(float $amount): bool
    {
        return $this->auto_approval_limit > 0 && $amount <= $this->auto_approval_limit;
    }

    /**
     * Check if category supports direction
     */
    public function supportsDirection(string $direction): bool
    {
        $directions = $this->allowed_directions ?? [];
        return in_array($direction, $directions) || in_array('both', $directions);
    }

    /**
     * Get documentation requirements text
     */
    public function getDocumentationRequirementsTextAttribute(): string
    {
        if (!$this->requires_documentation) {
            return 'No documentation required';
        }

        $requirements = $this->documentation_requirements ?? [];
        return empty($requirements) 
            ? 'Documentation required' 
            : implode(', ', $requirements);
    }

    /**
     * Get allowed directions text
     */
    public function getAllowedDirectionsTextAttribute(): string
    {
        $directions = $this->allowed_directions ?? [];
        
        if (in_array('both', $directions)) {
            return 'Credit & Debit';
        }
        
        return implode(' & ', array_map('ucfirst', $directions));
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Check if amount requires approval for this category
     */
    public function requiresApproval(float $amount): bool
    {
        return !$this->allowsAutoApproval($amount);
    }

    /**
     * Check if category requires attachment
     */
    public function requiresAttachment(): bool
    {
        return $this->requires_documentation && 
               in_array('attachment', $this->documentation_requirements ?? []);
    }

    /**
     * Check if category requires detailed notes
     */
    public function requiresDetailedNotes(): bool
    {
        return $this->requires_documentation && 
               in_array('detailed_notes', $this->documentation_requirements ?? []);
    }

    /**
     * Check if category requires manager approval regardless of amount
     */
    public function requiresManagerApproval(): bool
    {
        return $this->risk_level === 'high' || 
               in_array('manager_approval', $this->documentation_requirements ?? []);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get active categories for dropdown
     */
    public static function getDropdownOptions(string $direction = null): array
    {
        $query = self::active()->ordered();
        
        if ($direction) {
            $query->supportsDirection($direction);
        }

        return $query->pluck('name', 'code')->toArray();
    }

    /**
     * Get category by code
     */
    public static function getByCode(string $code): ?self
    {
        return self::where('code', $code)->first();
    }

    /**
     * Check if code exists
     */
    public static function codeExists(string $code, int $excludeId = null): bool
    {
        $query = self::where('code', $code);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }

    /**
     * Get statistics
     */
    public static function getStatistics(): array
    {
        $categories = self::active()->get();
        
        $stats = [];
        foreach ($categories as $category) {
            $adjustmentCount = $category->adjustments()->count();
            $totalAmount = $category->adjustments()->sum('amount');
            
            $stats[] = [
                'category' => $category->name,
                'code' => $category->code,
                'adjustment_count' => $adjustmentCount,
                'total_amount' => $totalAmount,
                'avg_amount' => $adjustmentCount > 0 ? $totalAmount / $adjustmentCount : 0,
                'auto_approval_limit' => $category->auto_approval_limit,
                'risk_level' => $category->risk_level
            ];
        }
        
        return $stats;
    }

    /**
     * Seed default categories
     */
    public static function seedDefaults(): void
    {
        $defaultCategories = [
            [
                'code' => 'system_error',
                'name' => 'System Error',
                'description' => 'Balance adjustment due to system errors',
                'auto_approval_limit' => 50000.00,
                'requires_documentation' => true,
                'documentation_requirements' => ['detailed_notes', 'error_log'],
                'risk_level' => 'medium',
                'allowed_directions' => ['credit', 'debit'],
                'sort_order' => 1
            ],
            [
                'code' => 'payment_error',
                'name' => 'Payment Processing Error',
                'description' => 'Failed payment processing corrections',
                'auto_approval_limit' => 100000.00,
                'requires_documentation' => true,
                'documentation_requirements' => ['detailed_notes', 'payment_proof'],
                'risk_level' => 'high',
                'allowed_directions' => ['credit'],
                'sort_order' => 2
            ],
            [
                'code' => 'refund_manual',
                'name' => 'Manual Refund',
                'description' => 'Manual customer refund processing',
                'auto_approval_limit' => 0.00, // Always requires approval
                'requires_documentation' => true,
                'documentation_requirements' => ['customer_request', 'manager_approval'],
                'risk_level' => 'high',
                'allowed_directions' => ['debit'],
                'sort_order' => 3
            ],
            [
                'code' => 'bonus_campaign',
                'name' => 'Bonus Campaign',
                'description' => 'Marketing campaign bonus distribution',
                'auto_approval_limit' => 25000.00,
                'requires_documentation' => true,
                'documentation_requirements' => ['campaign_details'],
                'risk_level' => 'low',
                'allowed_directions' => ['credit'],
                'sort_order' => 4
            ],
            [
                'code' => 'compensation',
                'name' => 'Service Compensation',
                'description' => 'Compensation for service issues',
                'auto_approval_limit' => 75000.00,
                'requires_documentation' => true,
                'documentation_requirements' => ['detailed_notes', 'issue_report'],
                'risk_level' => 'medium',
                'allowed_directions' => ['credit'],
                'sort_order' => 5
            ],
            [
                'code' => 'migration',
                'name' => 'Data Migration',
                'description' => 'Balance migration from old system',
                'auto_approval_limit' => 0.00, // Always requires approval
                'requires_documentation' => true,
                'documentation_requirements' => ['migration_report', 'manager_approval'],
                'risk_level' => 'high',
                'allowed_directions' => ['credit', 'debit'],
                'sort_order' => 6
            ],
            [
                'code' => 'fraud_recovery',
                'name' => 'Fraud Recovery',
                'description' => 'Recovery from fraudulent activities',
                'auto_approval_limit' => 0.00, // Always requires approval
                'requires_documentation' => true,
                'documentation_requirements' => ['fraud_report', 'legal_clearance', 'manager_approval'],
                'risk_level' => 'high',
                'allowed_directions' => ['credit', 'debit'],
                'sort_order' => 7
            ],
            [
                'code' => 'other',
                'name' => 'Other',
                'description' => 'Other adjustment reasons not covered above',
                'auto_approval_limit' => 10000.00,
                'requires_documentation' => true,
                'documentation_requirements' => ['detailed_notes', 'manager_approval'],
                'risk_level' => 'medium',
                'allowed_directions' => ['credit', 'debit'],
                'sort_order' => 99
            ]
        ];

        foreach ($defaultCategories as $category) {
            $category['is_active'] = true;
            self::updateOrCreate(
                ['code' => $category['code']],
                $category
            );
        }
    }

    // ==================== MODEL EVENTS ====================

    protected static function booted(): void
    {
        // Ensure code is unique
        static::saving(function ($category) {
            if (self::codeExists($category->code, $category->id)) {
                throw new \Exception("Category code '{$category->code}' already exists");
            }
        });
    }
}