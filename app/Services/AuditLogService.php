<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AdminActionLog;
use App\Models\AccessLog;
use App\Models\ConfigChangeLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AuditLogService - Main Logging Engine
 * 
 * Purpose:
 * - Unified interface untuk semua audit logging
 * - Auto-detect actor & context
 * - Support correlation untuk linked logs
 * - Integration dengan DataMaskingService
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class AuditLogService
{
    protected DataMaskingService $maskingService;
    protected ?string $correlationId = null;
    protected ?string $sessionId = null;
    
    public function __construct(DataMaskingService $maskingService)
    {
        $this->maskingService = $maskingService;
    }
    
    // ==================== CORRELATION ====================
    
    /**
     * Set correlation ID for linking related logs
     */
    public function setCorrelationId(?string $id): self
    {
        $this->correlationId = $id;
        return $this;
    }
    
    /**
     * Generate new correlation ID
     */
    public function newCorrelation(): string
    {
        $this->correlationId = 'corr_' . Str::uuid();
        return $this->correlationId;
    }
    
    /**
     * Set session ID
     */
    public function setSessionId(?string $id): self
    {
        $this->sessionId = $id;
        return $this;
    }
    
    // ==================== MAIN AUDIT LOG ====================
    
    /**
     * Log general activity
     */
    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $options = []
    ): AuditLog {
        $actor = $this->detectActor();
        
        $data = [
            // Actor
            'actor_type' => $options['actor_type'] ?? $actor['type'],
            'actor_id' => $options['actor_id'] ?? $actor['id'],
            'actor_email' => $options['actor_email'] ?? $actor['email'],
            'actor_ip' => $options['actor_ip'] ?? request()->ip(),
            'actor_user_agent' => request()->userAgent(),
            
            // Entity
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_uuid' => $options['entity_uuid'] ?? null,
            
            // Context
            'klien_id' => $options['klien_id'] ?? $actor['klien_id'] ?? null,
            'correlation_id' => $options['correlation_id'] ?? $this->correlationId,
            'session_id' => $options['session_id'] ?? $this->sessionId ?? session()->getId(),
            
            // Action
            'action' => $action,
            'action_category' => $options['category'] ?? $this->detectCategory($entityType),
            
            // Data
            'old_values' => $this->maskValues($options['old_values'] ?? null),
            'new_values' => $this->maskValues($options['new_values'] ?? null),
            'context' => $options['context'] ?? null,
            'description' => $options['description'] ?? null,
            
            // Status
            'status' => $options['status'] ?? 'success',
            'failure_reason' => $options['failure_reason'] ?? null,
            
            // Classification
            'data_classification' => $options['classification'] ?? AuditLog::CLASS_INTERNAL,
            'contains_pii' => $options['contains_pii'] ?? $this->detectPii($options),
            'is_masked' => $options['is_masked'] ?? true,
            
            // Retention
            'retention_category' => $options['retention'] ?? $this->detectRetention($entityType),
            
            'occurred_at' => $options['occurred_at'] ?? now(),
        ];
        
        return AuditLog::create($data);
    }
    
    /**
     * Log successful action
     */
    public function logSuccess(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $options = []
    ): AuditLog {
        return $this->log($action, $entityType, $entityId, array_merge($options, [
            'status' => 'success',
        ]));
    }
    
    /**
     * Log failed action
     */
    public function logFailure(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $reason = null,
        array $options = []
    ): AuditLog {
        return $this->log($action, $entityType, $entityId, array_merge($options, [
            'status' => 'failed',
            'failure_reason' => $reason,
        ]));
    }
    
    // ==================== ENTITY-SPECIFIC LOGGING ====================
    
    /**
     * Log model creation
     */
    public function logCreate($model, array $options = []): AuditLog
    {
        return $this->log('create', $this->getEntityType($model), $model->id ?? null, array_merge($options, [
            'new_values' => $model->toArray(),
        ]));
    }
    
    /**
     * Log model update
     */
    public function logUpdate($model, array $oldValues, array $options = []): AuditLog
    {
        return $this->log('update', $this->getEntityType($model), $model->id ?? null, array_merge($options, [
            'old_values' => $oldValues,
            'new_values' => $model->toArray(),
        ]));
    }
    
    /**
     * Log model deletion
     */
    public function logDelete($model, array $options = []): AuditLog
    {
        return $this->log('delete', $this->getEntityType($model), $model->id ?? null, array_merge($options, [
            'old_values' => $model->toArray(),
        ]));
    }
    
    // ==================== BILLING LOGS ====================
    
    /**
     * Log financial ledger entry (immutable record creation)
     * Used when creating wallet_transactions, payment_transactions, etc.
     */
    public function logLedgerEntry(
        string $ledgerType,
        int $entryId,
        array $entryData,
        array $options = []
    ): AuditLog {
        return $this->log('ledger_entry', $ledgerType, $entryId, array_merge($options, [
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'classification' => AuditLog::CLASS_CONFIDENTIAL,
            'new_values' => $entryData,
            'description' => $options['description'] ?? "Ledger entry created: {$ledgerType} #{$entryId}",
        ]));
    }

    /**
     * Log reversal of a financial record
     * Links the new adjustment record to the original via correlation
     */
    public function logReversal(
        string $ledgerType,
        int $originalId,
        int $reversalId,
        array $reversalData,
        string $reason,
        array $options = []
    ): AuditLog {
        return $this->log('reversal', $ledgerType, $reversalId, array_merge($options, [
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'classification' => AuditLog::CLASS_CONFIDENTIAL,
            'old_values' => ['original_record_id' => $originalId, 'reason' => $reason],
            'new_values' => $reversalData,
            'description' => "Reversal for {$ledgerType} #{$originalId}: {$reason}",
        ]));
    }

    /**
     * Log closing/finalization of a period
     */
    public function logClosing(
        string $entityType,
        int $entityId,
        string $action,
        array $closingData,
        array $options = []
    ): AuditLog {
        return $this->log($action, $entityType, $entityId, array_merge($options, [
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'classification' => AuditLog::CLASS_CONFIDENTIAL,
            'new_values' => $closingData,
        ]));
    }

    /**
     * Log reconciliation action
     */
    public function logReconciliation(
        string $source,
        int $logId,
        string $status,
        array $reconData,
        array $options = []
    ): AuditLog {
        return $this->log('reconcile', "reconciliation_{$source}", $logId, array_merge($options, [
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'classification' => AuditLog::CLASS_CONFIDENTIAL,
            'new_values' => array_merge($reconData, ['status' => $status]),
        ]));
    }

    /**
     * Log transaction
     */
    public function logTransaction(
        string $action,
        int $transactionId,
        int $klienId,
        array $data,
        array $options = []
    ): AuditLog {
        return $this->log($action, 'transaction', $transactionId, array_merge($options, [
            'klien_id' => $klienId,
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'new_values' => $data,
        ]));
    }
    
    /**
     * Log quota change
     */
    public function logQuotaChange(
        string $action,
        int $klienId,
        array $before,
        array $after,
        array $options = []
    ): AuditLog {
        return $this->log($action, 'quota', null, array_merge($options, [
            'klien_id' => $klienId,
            'category' => AuditLog::CATEGORY_BILLING,
            'retention' => AuditLog::RETENTION_FINANCIAL,
            'old_values' => $before,
            'new_values' => $after,
        ]));
    }
    
    // ==================== MESSAGE LOGS ====================
    
    /**
     * Log message send
     */
    public function logMessageSend(
        int $messageId,
        int $klienId,
        array $data,
        array $options = []
    ): AuditLog {
        return $this->log('send', 'message', $messageId, array_merge($options, [
            'klien_id' => $klienId,
            'category' => AuditLog::CATEGORY_MESSAGE,
            'retention' => AuditLog::RETENTION_MESSAGE,
            'new_values' => $data,
            'contains_pii' => true,
        ]));
    }
    
    /**
     * Log delivery report
     */
    public function logDeliveryReport(
        int $messageId,
        string $status,
        array $data,
        array $options = []
    ): AuditLog {
        return $this->log("delivery_{$status}", 'message', $messageId, array_merge($options, [
            'category' => AuditLog::CATEGORY_WEBHOOK,
            'retention' => AuditLog::RETENTION_MESSAGE,
            'new_values' => $data,
        ]));
    }
    
    // ==================== TRUST & SAFETY LOGS ====================
    
    /**
     * Log abuse event
     */
    public function logAbuseEvent(
        string $action,
        int $klienId,
        array $data,
        array $options = []
    ): AuditLog {
        return $this->log($action, 'abuse', null, array_merge($options, [
            'klien_id' => $klienId,
            'category' => AuditLog::CATEGORY_TRUST_SAFETY,
            'retention' => AuditLog::RETENTION_ABUSE,
            'new_values' => $data,
        ]));
    }
    
    /**
     * Log risk event
     */
    public function logRiskEvent(
        string $action,
        string $entityType,
        int $entityId,
        array $data,
        array $options = []
    ): AuditLog {
        return $this->log($action, $entityType, $entityId, array_merge($options, [
            'category' => AuditLog::CATEGORY_TRUST_SAFETY,
            'retention' => AuditLog::RETENTION_ABUSE,
            'new_values' => $data,
        ]));
    }
    
    // ==================== AUTH LOGS ====================
    
    /**
     * Log login attempt
     */
    public function logLogin(
        bool $success,
        ?int $userId = null,
        ?string $email = null,
        array $options = []
    ): AuditLog {
        return $this->log($success ? 'login_success' : 'login_failed', 'auth', $userId, array_merge($options, [
            'actor_id' => $userId,
            'actor_email' => $email,
            'category' => AuditLog::CATEGORY_AUTH,
            'status' => $success ? 'success' : 'failed',
            'failure_reason' => $options['failure_reason'] ?? ($success ? null : 'Invalid credentials'),
        ]));
    }
    
    /**
     * Log logout
     */
    public function logLogout(int $userId, array $options = []): AuditLog
    {
        return $this->log('logout', 'auth', $userId, array_merge($options, [
            'category' => AuditLog::CATEGORY_AUTH,
        ]));
    }
    
    // ==================== ADMIN ACTION LOG ====================
    
    /**
     * Log admin action
     */
    public function logAdminAction(
        string $action,
        string $category,
        string $targetType,
        ?int $targetId = null,
        array $options = []
    ): AdminActionLog {
        $admin = Auth::user();
        
        if (!$admin) {
            throw new \RuntimeException('Admin action log requires authenticated admin');
        }
        
        return AdminActionLog::create([
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'admin_role' => $options['admin_role'] ?? $admin->role ?? 'admin',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'action' => $action,
            'action_category' => $category,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_klien_id' => $options['klien_id'] ?? null,
            'action_params' => $options['params'] ?? null,
            'before_state' => $this->maskValues($options['before'] ?? null),
            'after_state' => $this->maskValues($options['after'] ?? null),
            'reason' => $options['reason'] ?? null,
            'notes' => $options['notes'] ?? null,
            'status' => $options['status'] ?? 'success',
            'error_message' => $options['error'] ?? null,
            'requires_approval' => $options['requires_approval'] ?? false,
            'performed_at' => now(),
        ]);
    }
    
    // ==================== ACCESS LOG ====================
    
    /**
     * Log data access
     */
    public function logAccess(
        string $resourceType,
        ?int $resourceId = null,
        string $accessType = AccessLog::ACCESS_VIEW,
        array $options = []
    ): AccessLog {
        $actor = $this->detectActor();
        
        return AccessLog::create([
            'accessor_type' => $options['accessor_type'] ?? $actor['type'],
            'accessor_id' => $options['accessor_id'] ?? $actor['id'],
            'accessor_email' => $options['accessor_email'] ?? $actor['email'],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'resource_description' => $options['description'] ?? null,
            'access_type' => $accessType,
            'access_scope' => $options['scope'] ?? AccessLog::SCOPE_SINGLE,
            'klien_id' => $options['klien_id'] ?? $actor['klien_id'] ?? null,
            'endpoint' => $options['endpoint'] ?? request()->path(),
            'query_params' => $this->maskQueryParams(request()->query()),
            'data_classification' => $options['classification'] ?? AccessLog::CLASS_INTERNAL,
            'contains_pii' => $options['contains_pii'] ?? false,
            'records_accessed' => $options['records'] ?? 1,
            'purpose' => $options['purpose'] ?? null,
            'justification_code' => $options['justification'] ?? null,
            'status' => $options['status'] ?? AccessLog::STATUS_ALLOWED,
            'denial_reason' => $options['denial_reason'] ?? null,
            'accessed_at' => now(),
        ]);
    }
    
    /**
     * Log PII access (stricter)
     */
    public function logPiiAccess(
        string $resourceType,
        ?int $resourceId = null,
        ?string $purpose = null,
        array $options = []
    ): AccessLog {
        return $this->logAccess($resourceType, $resourceId, AccessLog::ACCESS_VIEW, array_merge($options, [
            'classification' => AccessLog::CLASS_RESTRICTED,
            'contains_pii' => true,
            'purpose' => $purpose,
        ]));
    }
    
    /**
     * Log bulk data access
     */
    public function logBulkAccess(
        string $resourceType,
        int $recordCount,
        ?string $purpose = null,
        array $options = []
    ): AccessLog {
        return $this->logAccess($resourceType, null, AccessLog::ACCESS_VIEW, array_merge($options, [
            'scope' => AccessLog::SCOPE_BULK,
            'records' => $recordCount,
            'purpose' => $purpose,
        ]));
    }
    
    /**
     * Log export
     */
    public function logExport(
        string $resourceType,
        int $recordCount,
        string $format = 'csv',
        array $options = []
    ): AccessLog {
        return $this->logAccess($resourceType, null, AccessLog::ACCESS_EXPORT, array_merge($options, [
            'scope' => AccessLog::SCOPE_REPORT,
            'records' => $recordCount,
            'description' => "Export {$recordCount} records as {$format}",
        ]));
    }
    
    // ==================== CONFIG CHANGE LOG ====================
    
    /**
     * Log config change
     */
    public function logConfigChange(
        string $group,
        string $key,
        $oldValue,
        $newValue,
        array $options = []
    ): ConfigChangeLog {
        $actor = $this->detectActor();
        
        return ConfigChangeLog::create([
            'changed_by_type' => $options['changed_by_type'] ?? $actor['type'],
            'changed_by_id' => $options['changed_by_id'] ?? $actor['id'],
            'changed_by_email' => $options['changed_by_email'] ?? $actor['email'],
            'config_group' => $group,
            'config_key' => $key,
            'old_value' => $this->serializeConfigValue($oldValue),
            'new_value' => $this->serializeConfigValue($newValue),
            'value_type' => $this->detectValueType($newValue),
            'klien_id' => $options['klien_id'] ?? null,
            'reason' => $options['reason'] ?? null,
            'source' => $options['source'] ?? ConfigChangeLog::SOURCE_ADMIN_PANEL,
            'impact_level' => $options['impact'] ?? ConfigChangeLog::IMPACT_MEDIUM,
            'requires_restart' => $options['requires_restart'] ?? false,
            'affects_billing' => $options['affects_billing'] ?? false,
            'requires_approval' => $options['requires_approval'] ?? false,
            'is_rollback' => $options['is_rollback'] ?? false,
            'rollback_of_id' => $options['rollback_of'] ?? null,
            'changed_at' => now(),
        ]);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Detect actor from current context
     */
    protected function detectActor(): array
    {
        $user = Auth::user();
        
        if ($user) {
            // Check if admin or regular user
            $isAdmin = method_exists($user, 'isAdmin') ? $user->isAdmin() : ($user->role === 'admin' ?? false);
            
            return [
                'type' => $isAdmin ? AuditLog::ACTOR_ADMIN : AuditLog::ACTOR_USER,
                'id' => $user->id,
                'email' => $user->email,
                'klien_id' => $user->klien_id ?? null,
            ];
        }
        
        // Check for API token
        if (request()->bearerToken()) {
            return [
                'type' => AuditLog::ACTOR_API,
                'id' => null,
                'email' => null,
                'klien_id' => null,
            ];
        }
        
        // System/cron
        return [
            'type' => AuditLog::ACTOR_SYSTEM,
            'id' => null,
            'email' => null,
            'klien_id' => null,
        ];
    }
    
    /**
     * Detect category from entity type
     */
    protected function detectCategory(string $entityType): string
    {
        return match (true) {
            in_array($entityType, ['transaction', 'payment', 'invoice', 'quota']) => AuditLog::CATEGORY_BILLING,
            in_array($entityType, ['message', 'campaign', 'template']) => AuditLog::CATEGORY_MESSAGE,
            in_array($entityType, ['abuse', 'risk', 'restriction']) => AuditLog::CATEGORY_TRUST_SAFETY,
            in_array($entityType, ['auth', 'login', 'password']) => AuditLog::CATEGORY_AUTH,
            in_array($entityType, ['config', 'setting']) => AuditLog::CATEGORY_CONFIG,
            in_array($entityType, ['webhook']) => AuditLog::CATEGORY_WEBHOOK,
            default => AuditLog::CATEGORY_CORE,
        };
    }
    
    /**
     * Detect retention category
     */
    protected function detectRetention(string $entityType): string
    {
        return match (true) {
            in_array($entityType, ['transaction', 'payment', 'invoice', 'quota']) => AuditLog::RETENTION_FINANCIAL,
            in_array($entityType, ['message', 'campaign']) => AuditLog::RETENTION_MESSAGE,
            in_array($entityType, ['abuse', 'risk', 'restriction']) => AuditLog::RETENTION_ABUSE,
            in_array($entityType, ['debug', 'error']) => AuditLog::RETENTION_DEBUG,
            default => AuditLog::RETENTION_STANDARD,
        };
    }
    
    /**
     * Detect if data contains PII
     */
    protected function detectPii(array $options): bool
    {
        if ($options['contains_pii'] ?? false) {
            return true;
        }
        
        $piiFields = ['phone', 'email', 'name', 'address', 'nomor_wa', 'recipient'];
        $allValues = array_merge(
            $options['old_values'] ?? [],
            $options['new_values'] ?? [],
            $options['context'] ?? []
        );
        
        foreach ($piiFields as $field) {
            if (isset($allValues[$field])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Mask sensitive values
     */
    protected function maskValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        
        return $this->maskingService->maskArray($values);
    }
    
    /**
     * Mask query params
     */
    protected function maskQueryParams(array $params): ?string
    {
        if (empty($params)) {
            return null;
        }
        
        $masked = $this->maskingService->maskArray($params);
        return http_build_query($masked);
    }
    
    /**
     * Get entity type from model
     */
    protected function getEntityType($model): string
    {
        $className = class_basename($model);
        return Str::snake($className);
    }
    
    /**
     * Serialize config value
     */
    protected function serializeConfigValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return (string) $value;
    }
    
    /**
     * Detect value type
     */
    protected function detectValueType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_array($value)) {
            return 'json';
        }
        return 'string';
    }
}
