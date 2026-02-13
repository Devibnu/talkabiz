<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * ComplianceLog - Append-Only Legal & Compliance Trail
 * 
 * IMMUTABLE MODEL - No Update, No Delete
 * 
 * Purpose:
 * - Legal-grade audit trail for all critical operations
 * - Hash-chained for tamper evidence
 * - Regulatory compliance (OJK, UU ITE, GDPR)
 * - Evidence for dispute resolution
 * 
 * Protected by:
 * 1. Eloquent guards (updating/deleting events throw RuntimeException)
 * 2. MySQL triggers (BEFORE UPDATE/DELETE SIGNAL SQLSTATE 45000)
 * 3. No update/delete methods exposed
 * 
 * @property int $id
 * @property string $log_ulid
 * @property string $record_hash
 * @property string|null $previous_hash
 * @property int $sequence_number
 * @property string $module
 * @property string $action
 * @property string $severity
 * @property string $outcome
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property string|null $actor_role
 * @property string|null $actor_ip
 * @property string|null $actor_user_agent
 * @property string|null $actor_session_id
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $target_label
 * @property int|null $klien_id
 * @property string $description
 * @property array|null $before_state
 * @property array|null $after_state
 * @property array|null $context
 * @property array|null $evidence
 * @property float|null $amount
 * @property string|null $currency
 * @property string|null $correlation_id
 * @property string|null $request_id
 * @property string|null $idempotency_key
 * @property string|null $legal_basis
 * @property string|null $regulation_ref
 * @property \Carbon\Carbon|null $retention_until
 * @property bool $is_sensitive
 * @property bool $is_financial
 * @property \Carbon\Carbon $occurred_at
 * @property \Carbon\Carbon $created_at
 */
class ComplianceLog extends Model
{
    // ==================== IMMUTABILITY PROTECTION ====================

    public $timestamps = false; // We manage created_at manually

    protected static function boot()
    {
        parent::boot();

        // Generate ULID and hash before creating
        static::creating(function ($model) {
            // ULID for time-ordered unique ID
            if (empty($model->log_ulid)) {
                $model->log_ulid = (string) Str::ulid();
            }

            // Set occurred_at and created_at
            if (empty($model->occurred_at)) {
                $model->occurred_at = now();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }

            // Calculate retention date based on module
            $model->calculateRetentionDate();

            // Set sequence number and hash chain
            $model->buildHashChain();
        });

        // BLOCK updates — append only
        static::updating(function ($model) {
            throw new \RuntimeException(
                'COMPLIANCE VIOLATION: ComplianceLog records are immutable and cannot be updated. ' .
                'Record ID: ' . $model->id . ', ULID: ' . $model->log_ulid
            );
        });

        // BLOCK deletes — legal records must be preserved
        static::deleting(function ($model) {
            throw new \RuntimeException(
                'COMPLIANCE VIOLATION: ComplianceLog records cannot be deleted. ' .
                'Record ID: ' . $model->id . ', ULID: ' . $model->log_ulid . '. ' .
                'Use archival process for data retention compliance.'
            );
        });
    }

    // ==================== CONFIGURATION ====================

    protected $table = 'compliance_logs';

    protected $fillable = [
        'module',
        'action',
        'severity',
        'outcome',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'actor_ip',
        'actor_user_agent',
        'actor_session_id',
        'target_type',
        'target_id',
        'target_label',
        'klien_id',
        'description',
        'before_state',
        'after_state',
        'context',
        'evidence',
        'amount',
        'currency',
        'correlation_id',
        'request_id',
        'idempotency_key',
        'legal_basis',
        'regulation_ref',
        'retention_until',
        'is_sensitive',
        'is_financial',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'target_id' => 'integer',
        'klien_id' => 'integer',
        'sequence_number' => 'integer',
        'before_state' => 'array',
        'after_state' => 'array',
        'context' => 'array',
        'evidence' => 'array',
        'amount' => 'decimal:2',
        'is_sensitive' => 'boolean',
        'is_financial' => 'boolean',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'retention_until' => 'date',
    ];

    // ==================== CONSTANTS: MODULES ====================

    const MODULE_WALLET = 'wallet';
    const MODULE_BILLING = 'billing';
    const MODULE_INVOICE = 'invoice';
    const MODULE_RISK = 'risk';
    const MODULE_ABUSE = 'abuse';
    const MODULE_APPROVAL = 'approval';
    const MODULE_COMPLAINT = 'complaint';
    const MODULE_AUTH = 'auth';
    const MODULE_SYSTEM = 'system';

    // ==================== CONSTANTS: ACTIONS ====================

    // Wallet actions
    const ACTION_WALLET_TOPUP = 'wallet.topup';
    const ACTION_WALLET_DEDUCT = 'wallet.deduct';
    const ACTION_WALLET_DEDUCT_PRICED = 'wallet.deduct_priced';
    const ACTION_WALLET_CREATE = 'wallet.create';
    const ACTION_WALLET_CONFIRM_TOPUP = 'wallet.confirm_topup';
    const ACTION_WALLET_REJECT_TOPUP = 'wallet.reject_topup';

    // Billing actions
    const ACTION_BILLING_TOPUP_REQUEST = 'billing.topup_request';
    const ACTION_BILLING_PAYMENT_GATEWAY = 'billing.payment_gateway';
    const ACTION_BILLING_QUICK_TOPUP = 'billing.quick_topup';

    // Approval actions
    const ACTION_KLIEN_APPROVE = 'approval.approve';
    const ACTION_KLIEN_REJECT = 'approval.reject';
    const ACTION_KLIEN_SUSPEND = 'approval.suspend';
    const ACTION_KLIEN_REACTIVATE = 'approval.reactivate';

    // Abuse actions
    const ACTION_ABUSE_EVENT = 'abuse.event_recorded';
    const ACTION_ABUSE_SCORE_RESET = 'abuse.score_reset';
    const ACTION_ABUSE_COMPLAINT = 'abuse.complaint_recorded';
    const ACTION_ABUSE_ESCALATION = 'abuse.escalation';

    // Complaint actions
    const ACTION_COMPLAINT_SUSPEND = 'complaint.suspend_klien';
    const ACTION_COMPLAINT_BLOCK = 'complaint.block_recipient';
    const ACTION_COMPLAINT_PROCESS = 'complaint.mark_processed';
    const ACTION_COMPLAINT_DISMISS = 'complaint.dismiss';
    const ACTION_COMPLAINT_BULK = 'complaint.bulk_action';
    const ACTION_COMPLAINT_EXPORT = 'complaint.export';

    // ==================== CONSTANTS: SEVERITY ====================

    const SEVERITY_INFO = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_CRITICAL = 'critical';
    const SEVERITY_LEGAL = 'legal';

    // ==================== CONSTANTS: ACTOR TYPES ====================

    const ACTOR_USER = 'user';
    const ACTOR_ADMIN = 'admin';
    const ACTOR_SYSTEM = 'system';
    const ACTOR_WEBHOOK = 'webhook';
    const ACTOR_CRON = 'cron';

    // ==================== CONSTANTS: LEGAL BASIS ====================

    const LEGAL_OJK = 'OJK';
    const LEGAL_UU_ITE = 'UU_ITE';
    const LEGAL_GDPR = 'GDPR';
    const LEGAL_COMPANY_POLICY = 'company_policy';
    const LEGAL_REGULATORY = 'regulatory';
    const LEGAL_CONTRACT = 'contract';

    // ==================== CONSTANTS: RETENTION (years) ====================

    const RETENTION_FINANCIAL = 10; // OJK requires 10 years for financial records
    const RETENTION_RISK = 7;
    const RETENTION_ABUSE = 5;
    const RETENTION_STANDARD = 3;

    // ==================== RELATIONSHIPS ====================

    public function klien()
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function actorUser()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ==================== SCOPES ====================

    public function scopeByModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeBySeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->whereIn('severity', [self::SEVERITY_CRITICAL, self::SEVERITY_LEGAL]);
    }

    public function scopeFinancial(Builder $query): Builder
    {
        return $query->where('is_financial', true);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForActor(Builder $query, int $actorId, ?string $actorType = null): Builder
    {
        $query->where('actor_id', $actorId);
        if ($actorType) {
            $query->where('actor_type', $actorType);
        }
        return $query;
    }

    public function scopeByCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    public function scopeDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }

    // ==================== HASH CHAIN ====================

    /**
     * Build hash chain: set sequence_number, previous_hash, record_hash
     */
    protected function buildHashChain(): void
    {
        // Get the last record for chain linking
        $lastRecord = static::query()
            ->orderByDesc('id')
            ->select(['id', 'record_hash', 'sequence_number'])
            ->first();

        $this->sequence_number = $lastRecord ? ($lastRecord->sequence_number + 1) : 1;
        $this->previous_hash = $lastRecord ? $lastRecord->record_hash : str_repeat('0', 64);

        // Calculate hash of this record's content
        $this->record_hash = $this->calculateHash();
    }

    /**
     * Calculate SHA-256 hash of record content for tamper evidence
     */
    protected function calculateHash(): string
    {
        $payload = implode('|', [
            $this->log_ulid,
            $this->sequence_number,
            $this->previous_hash ?? '',
            $this->module,
            $this->action,
            $this->severity,
            $this->outcome,
            $this->actor_type,
            $this->actor_id ?? '',
            $this->actor_email ?? '',
            $this->target_type ?? '',
            $this->target_id ?? '',
            $this->klien_id ?? '',
            $this->description,
            $this->amount ?? '',
            json_encode($this->before_state ?? []),
            json_encode($this->after_state ?? []),
            json_encode($this->context ?? []),
            json_encode($this->evidence ?? []),
            $this->occurred_at,
        ]);

        return hash('sha256', $payload);
    }

    /**
     * Calculate retention date based on module type
     */
    protected function calculateRetentionDate(): void
    {
        if ($this->retention_until) {
            return; // Already set explicitly
        }

        $years = match ($this->module) {
            self::MODULE_WALLET, self::MODULE_BILLING, self::MODULE_INVOICE => self::RETENTION_FINANCIAL,
            self::MODULE_RISK, self::MODULE_APPROVAL => self::RETENTION_RISK,
            self::MODULE_ABUSE, self::MODULE_COMPLAINT => self::RETENTION_ABUSE,
            default => self::RETENTION_STANDARD,
        };

        $this->retention_until = now()->addYears($years)->toDateString();
    }

    // ==================== INTEGRITY VERIFICATION ====================

    /**
     * Verify this record's hash integrity
     */
    public function verifyIntegrity(): bool
    {
        return $this->record_hash === $this->calculateHash();
    }

    /**
     * Verify chain integrity from this record backwards
     */
    public static function verifyChain(int $fromId = null, int $limit = 100): array
    {
        $query = static::query()->orderByDesc('id');
        if ($fromId) {
            $query->where('id', '<=', $fromId);
        }

        $records = $query->limit($limit)->get();
        $results = ['valid' => true, 'checked' => 0, 'errors' => []];

        foreach ($records as $i => $record) {
            $results['checked']++;

            // Verify record hash
            if (!$record->verifyIntegrity()) {
                $results['valid'] = false;
                $results['errors'][] = [
                    'id' => $record->id,
                    'type' => 'hash_mismatch',
                    'message' => "Record #{$record->id} hash does not match content",
                ];
            }

            // Verify chain link (current record's previous_hash should match next record's hash)
            if (isset($records[$i + 1])) {
                $previousRecord = $records[$i + 1];
                if ($record->previous_hash !== $previousRecord->record_hash) {
                    $results['valid'] = false;
                    $results['errors'][] = [
                        'id' => $record->id,
                        'type' => 'chain_break',
                        'message' => "Chain break at record #{$record->id}: previous_hash doesn't match record #{$previousRecord->id}",
                    ];
                }
            }
        }

        return $results;
    }

    // ==================== QUERY HELPERS ====================

    /**
     * Get display label for severity
     */
    public function getSeverityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'Info',
            self::SEVERITY_WARNING => 'Warning',
            self::SEVERITY_CRITICAL => 'Critical',
            self::SEVERITY_LEGAL => 'Legal',
            default => ucfirst($this->severity),
        };
    }

    /**
     * Get badge class for severity
     */
    public function getSeverityBadgeClass(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'bg-gradient-info',
            self::SEVERITY_WARNING => 'bg-gradient-warning',
            self::SEVERITY_CRITICAL => 'bg-gradient-danger',
            self::SEVERITY_LEGAL => 'bg-gradient-dark',
            default => 'bg-gradient-secondary',
        };
    }
}
