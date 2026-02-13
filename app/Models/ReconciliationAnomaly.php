<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Reconciliation Anomaly Model
 * 
 * IMMUTABLE RECORD - sekali terdeteksi anomali, tidak boleh diubah.
 * Hanya resolution status dan notes yang boleh diupdate.
 * 
 * @property int $reconciliation_report_id
 * @property string $anomaly_type
 * @property string $severity
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property int|null $user_id
 * @property string $description
 * @property float|null $expected_amount
 * @property float|null $actual_amount
 * @property float|null $difference_amount
 * @property array|null $entity_data
 * @property array|null $related_records
 * @property array|null $system_state
 * @property string $resolution_status
 * @property string|null $resolution_notes
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property bool $auto_resolution_attempted
 * @property string|null $auto_resolution_result
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReconciliationAnomaly extends Model
{
    // Anomaly types
    const TYPE_INVOICE_LEDGER_MISMATCH = 'invoice_ledger_mismatch';
    const TYPE_MESSAGE_DEBIT_MISMATCH = 'message_debit_mismatch';
    const TYPE_REFUND_MISSING = 'refund_missing';
    const TYPE_NEGATIVE_BALANCE = 'negative_balance';
    const TYPE_DUPLICATE_TRANSACTION = 'duplicate_transaction';
    const TYPE_ORPHANED_LEDGER_ENTRY = 'orphaned_ledger_entry';
    const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';
    const TYPE_TIMING_ANOMALY = 'timing_anomaly';

    // Severity levels
    const SEVERITY_CRITICAL = 'critical';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_LOW = 'low';

    // Resolution status
    const RESOLUTION_PENDING = 'pending';
    const RESOLUTION_INVESTIGATING = 'investigating';
    const RESOLUTION_RESOLVED = 'resolved';
    const RESOLUTION_FALSE_POSITIVE = 'false_positive';
    const RESOLUTION_ACCEPTED_RISK = 'accepted_risk';

    protected $fillable = [
        'reconciliation_report_id',
        'anomaly_type',
        'severity',
        'entity_type',
        'entity_id',
        'user_id',
        'description',
        'expected_amount',
        'actual_amount',
        'difference_amount',
        'entity_data',
        'related_records',
        'system_state',
        'resolution_status',
        'resolution_notes',
        'resolved_at',
        'resolved_by_user_id',
        'auto_resolution_attempted',
        'auto_resolution_result'
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'entity_data' => 'array',
        'related_records' => 'array',
        'system_state' => 'array',
        'resolved_at' => 'datetime',
        'auto_resolution_attempted' => 'boolean'
    ];

    /**
     * Relationship dengan reconciliation report
     */
    public function reconciliationReport(): BelongsTo
    {
        return $this->belongsTo(ReconciliationReport::class);
    }

    /**
     * Relationship dengan user yang terkait anomali
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship dengan user yang resolve anomali
     */
    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Check if anomaly is resolved
     */
    public function isResolved(): bool
    {
        return in_array($this->resolution_status, [
            self::RESOLUTION_RESOLVED,
            self::RESOLUTION_FALSE_POSITIVE,
            self::RESOLUTION_ACCEPTED_RISK
        ]);
    }

    /**
     * Check if anomaly is critical
     */
    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    /**
     * Format amounts untuk display
     */
    public function getFormattedAmountsAttribute(): array
    {
        return [
            'expected' => $this->expected_amount ? 'Rp ' . number_format($this->expected_amount, 2, ',', '.') : '-',
            'actual' => $this->actual_amount ? 'Rp ' . number_format($this->actual_amount, 2, ',', '.') : '-',
            'difference' => $this->difference_amount ? 'Rp ' . number_format($this->difference_amount, 2, ',', '.') : '-',
        ];
    }

    /**
     * Get time since anomaly was created
     */
    public function getAgeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get resolution time (if resolved)
     */
    public function getResolutionTimeAttribute(): ?string
    {
        if (!$this->isResolved() || !$this->resolved_at) {
            return null;
        }
        
        return $this->created_at->diffInHours($this->resolved_at) . ' hours';
    }

    /**
     * Factory method untuk create anomali Invoice ↔ Ledger mismatch
     */
    public static function createInvoiceLedgerMismatch(
        int $reconciliationReportId,
        $invoiceData,
        $ledgerData,
        int $userId
    ): self {
        return self::create([
            'reconciliation_report_id' => $reconciliationReportId,
            'anomaly_type' => self::TYPE_INVOICE_LEDGER_MISMATCH,
            'severity' => self::SEVERITY_HIGH,
            'entity_type' => 'invoice',
            'entity_id' => $invoiceData['id'] ?? null,
            'user_id' => $userId,
            'description' => "Invoice PAID tidak memiliki corresponding credit di ledger",
            'expected_amount' => $invoiceData['amount'] ?? 0,
            'actual_amount' => $ledgerData['total_credits'] ?? 0,
            'difference_amount' => ($invoiceData['amount'] ?? 0) - ($ledgerData['total_credits'] ?? 0),
            'entity_data' => [
                'invoice' => $invoiceData,
                'ledger_search_result' => $ledgerData
            ],
            'related_records' => [
                'invoice_id' => $invoiceData['id'] ?? null,
                'invoice_number' => $invoiceData['invoice_number'] ?? null,
                'ledger_entries_count' => $ledgerData['entries_count'] ?? 0
            ]
        ]);
    }

    /**
     * Factory method untuk create anomali Message tidak ada debit
     */
    public static function createMessageDebitMismatch(
        int $reconciliationReportId,
        array $messageData,
        int $userId
    ): self {
        return self::create([
            'reconciliation_report_id' => $reconciliationReportId,
            'anomaly_type' => self::TYPE_MESSAGE_DEBIT_MISMATCH,
            'severity' => self::SEVERITY_MEDIUM,
            'entity_type' => 'message',
            'entity_id' => $messageData['message_id'] ?? null,
            'user_id' => $userId,
            'description' => "Message SUCCESS tidak memiliki corresponding debit di ledger",
            'expected_amount' => $messageData['expected_cost'] ?? 0,
            'actual_amount' => 0,
            'difference_amount' => $messageData['expected_cost'] ?? 0,
            'entity_data' => [
                'message' => $messageData,
                'expected_transaction_code' => $messageData['transaction_code'] ?? null
            ],
            'related_records' => [
                'message_id' => $messageData['message_id'] ?? null,
                'campaign_id' => $messageData['campaign_id'] ?? null,
                'transaction_code' => $messageData['transaction_code'] ?? null
            ]
        ]);
    }

    /**
     * Factory method untuk create anomali Negative Balance
     */
    public static function createNegativeBalance(
        int $reconciliationReportId,
        int $userId,
        float $negativeAmount,
        array $ledgerContext
    ): self {
        return self::create([
            'reconciliation_report_id' => $reconciliationReportId,
            'anomaly_type' => self::TYPE_NEGATIVE_BALANCE,
            'severity' => self::SEVERITY_CRITICAL,
            'entity_type' => 'balance',
            'entity_id' => "user_{$userId}",
            'user_id' => $userId,
            'description' => "User memiliki saldo negatif: " . number_format($negativeAmount, 2),
            'expected_amount' => 0,
            'actual_amount' => $negativeAmount,
            'difference_amount' => $negativeAmount,
            'entity_data' => $ledgerContext,
            'related_records' => [
                'last_ledger_entry_id' => $ledgerContext['last_entry_id'] ?? null,
                'balance_calculation_method' => 'ledger_sum'
            ]
        ]);
    }

    /**
     * Resolve anomaly
     */
    public function resolve(
        string $resolutionStatus, 
        string $notes, 
        int $resolvedByUserId
    ): void {
        if (!in_array($resolutionStatus, [
            self::RESOLUTION_RESOLVED,
            self::RESOLUTION_FALSE_POSITIVE,
            self::RESOLUTION_ACCEPTED_RISK
        ])) {
            throw new \InvalidArgumentException("Invalid resolution status: {$resolutionStatus}");
        }

        $this->update([
            'resolution_status' => $resolutionStatus,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
            'resolved_by_user_id' => $resolvedByUserId
        ]);
    }

    /**
     * Scope untuk filter by severity
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope untuk unresolved anomalies
     */
    public function scopeUnresolved($query)
    {
        return $query->where('resolution_status', self::RESOLUTION_PENDING);
    }

    /**
     * Scope untuk critical anomalies
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope untuk specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}