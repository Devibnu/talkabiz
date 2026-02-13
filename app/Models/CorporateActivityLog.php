<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corporate Activity Log Model
 * 
 * Audit trail untuk semua aktivitas corporate client:
 * - Invite, approval, billing, config changes
 * - Failsafe actions (pause, throttle)
 * - SLA violations
 * 
 * PENTING: Corporate harus punya audit trail lengkap
 */
class CorporateActivityLog extends Model
{
    /**
     * Indicates if the model should be timestamped.
     * Only created_at is used.
     */
    public $timestamps = false;

    protected $fillable = [
        'corporate_client_id',
        'action',
        'category',
        'description',
        'old_values',
        'new_values',
        'performed_by',
        'performed_by_type',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // ==================== CATEGORY CONSTANTS ====================

    const CATEGORY_GENERAL = 'general';
    const CATEGORY_BILLING = 'billing';
    const CATEGORY_FAILSAFE = 'failsafe';
    const CATEGORY_LIMIT = 'limit';
    const CATEGORY_SLA = 'sla';

    // ==================== ACTION CONSTANTS ====================

    const ACTION_INVITED = 'invited';
    const ACTION_ACTIVATED = 'activated';
    const ACTION_SUSPENDED = 'suspended';
    const ACTION_PAUSED = 'paused';
    const ACTION_RESUMED = 'resumed';
    const ACTION_THROTTLED = 'throttled';
    const ACTION_LIMIT_CHANGED = 'limit_changed';
    const ACTION_SLA_VIOLATED = 'sla_violated';
    const ACTION_CONTRACT_APPROVED = 'contract_approved';
    const ACTION_CONTRACT_TERMINATED = 'contract_terminated';
    const ACTION_CONTRACT_RENEWED = 'contract_renewed';
    const ACTION_RISK_EVALUATED = 'risk_evaluated';

    // ==================== RELATIONSHIPS ====================

    public function corporateClient(): BelongsTo
    {
        return $this->belongsTo(CorporateClient::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Log an action for a corporate client.
     */
    public static function log(
        int $corporateClientId,
        string $action,
        string $category,
        ?string $description = null,
        ?int $performedBy = null,
        string $performerType = 'admin',
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return self::create([
            'corporate_client_id' => $corporateClientId,
            'action' => $action,
            'category' => $category,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'performed_by' => $performedBy,
            'performed_by_type' => $performerType,
            'created_at' => now(),
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('corporate_client_id', $clientId);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeFailsafe($query)
    {
        return $query->where('category', self::CATEGORY_FAILSAFE);
    }

    public function scopeBilling($query)
    {
        return $query->where('category', self::CATEGORY_BILLING);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== DISPLAY HELPERS ====================

    /**
     * Get human-readable action label.
     */
    public function getActionLabel(): string
    {
        $labels = [
            'invited' => 'Diundang',
            'activated' => 'Diaktifkan',
            'suspended' => 'Ditangguhkan',
            'paused' => 'Dijeda',
            'resumed' => 'Dilanjutkan',
            'throttled' => 'Throttle Diterapkan',
            'limit_changed' => 'Limit Diubah',
            'sla_violated' => 'SLA Dilanggar',
            'contract_approved' => 'Kontrak Disetujui',
            'contract_terminated' => 'Kontrak Dibatalkan',
            'contract_renewed' => 'Kontrak Diperpanjang',
            'risk_evaluated' => 'Evaluasi Risiko',
        ];

        return $labels[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }

    /**
     * Get category badge color.
     */
    public function getCategoryBadgeColor(): string
    {
        $colors = [
            'general' => 'bg-secondary',
            'billing' => 'bg-info',
            'failsafe' => 'bg-danger',
            'limit' => 'bg-warning',
            'sla' => 'bg-primary',
        ];

        return $colors[$this->category] ?? 'bg-secondary';
    }

    /**
     * Get performer name for display.
     */
    public function getPerformerName(): string
    {
        if ($this->performer) {
            return $this->performer->name;
        }

        if ($this->performed_by_type === 'system') {
            return 'System';
        }

        return 'Unknown';
    }
}
