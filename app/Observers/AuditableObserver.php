<?php

namespace App\Observers;

use App\Services\AuditLogService;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * AuditableObserver - Auto-log Model Changes
 * 
 * Purpose:
 * - Automatically log create/update/delete on models
 * - Extract old/new values for audit trail
 * - Support correlation for related changes
 * 
 * Usage:
 * In model boot method:
 * static::observe(AuditableObserver::class);
 * 
 * Or register in AppServiceProvider:
 * MyModel::observe(AuditableObserver::class);
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class AuditableObserver
{
    protected AuditLogService $auditService;
    
    /**
     * Fields to exclude from logging
     */
    protected array $excludeFields = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'updated_at',
    ];
    
    /**
     * Models that should be logged with financial retention
     */
    protected array $financialModels = [
        'Transaction',
        'Payment',
        'Invoice',
        'QuotaTransaction',
        'Refund',
    ];
    
    /**
     * Models that should be logged with abuse retention
     */
    protected array $abuseModels = [
        'AbuseEvent',
        'AbuseRule',
        'UserRestriction',
        'SuspensionHistory',
        'RiskScore',
        'RiskEvent',
    ];
    
    public function __construct(AuditLogService $auditService)
    {
        $this->auditService = $auditService;
    }
    
    /**
     * Handle model created event
     */
    public function created(Model $model): void
    {
        try {
            $this->logChange($model, 'create', null, $this->getLoggableAttributes($model));
        } catch (\Exception $e) {
            Log::error('AuditableObserver: Failed to log create', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle model updated event
     */
    public function updated(Model $model): void
    {
        try {
            $oldValues = $this->getOriginalValues($model);
            $newValues = $this->getChangedValues($model);
            
            // Skip if no meaningful changes
            if (empty($newValues)) {
                return;
            }
            
            $this->logChange($model, 'update', $oldValues, $newValues);
        } catch (\Exception $e) {
            Log::error('AuditableObserver: Failed to log update', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle model deleted event
     */
    public function deleted(Model $model): void
    {
        try {
            $this->logChange($model, 'delete', $this->getLoggableAttributes($model), null);
        } catch (\Exception $e) {
            Log::error('AuditableObserver: Failed to log delete', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle model restored event (soft delete)
     */
    public function restored(Model $model): void
    {
        try {
            $this->logChange($model, 'restore', null, $this->getLoggableAttributes($model));
        } catch (\Exception $e) {
            Log::error('AuditableObserver: Failed to log restore', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle model force deleted event
     */
    public function forceDeleted(Model $model): void
    {
        try {
            $this->logChange($model, 'force_delete', $this->getLoggableAttributes($model), null);
        } catch (\Exception $e) {
            Log::error('AuditableObserver: Failed to log force delete', [
                'model' => get_class($model),
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Log the change
     */
    protected function logChange(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $entityType = $this->getEntityType($model);
        $entityId = $model->getKey();
        
        $this->auditService->log($action, $entityType, $entityId, [
            'klien_id' => $this->getKlienId($model),
            'category' => $this->getCategory($model),
            'retention' => $this->getRetention($model),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'entity_uuid' => $this->getEntityUuid($model),
        ]);
    }
    
    /**
     * Get entity type from model class
     */
    protected function getEntityType(Model $model): string
    {
        $className = class_basename($model);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
    }
    
    /**
     * Get entity UUID if available
     */
    protected function getEntityUuid(Model $model): ?string
    {
        if (isset($model->uuid)) {
            return $model->uuid;
        }
        if (method_exists($model, 'getUuid')) {
            return $model->getUuid();
        }
        return null;
    }
    
    /**
     * Get klien_id from model
     */
    protected function getKlienId(Model $model): ?int
    {
        if (isset($model->klien_id)) {
            return $model->klien_id;
        }
        if (method_exists($model, 'getKlienId')) {
            return $model->getKlienId();
        }
        return null;
    }
    
    /**
     * Get audit category for model
     */
    protected function getCategory(Model $model): string
    {
        $className = class_basename($model);
        
        if (in_array($className, $this->financialModels)) {
            return AuditLog::CATEGORY_BILLING;
        }
        
        if (in_array($className, $this->abuseModels)) {
            return AuditLog::CATEGORY_TRUST_SAFETY;
        }
        
        // Check if model has category hint
        if (method_exists($model, 'getAuditCategory')) {
            return $model->getAuditCategory();
        }
        
        return AuditLog::CATEGORY_CORE;
    }
    
    /**
     * Get retention category for model
     */
    protected function getRetention(Model $model): string
    {
        $className = class_basename($model);
        
        if (in_array($className, $this->financialModels)) {
            return AuditLog::RETENTION_FINANCIAL;
        }
        
        if (in_array($className, $this->abuseModels)) {
            return AuditLog::RETENTION_ABUSE;
        }
        
        return AuditLog::RETENTION_STANDARD;
    }
    
    /**
     * Get loggable attributes (excluding sensitive fields)
     */
    protected function getLoggableAttributes(Model $model): array
    {
        $attributes = $model->getAttributes();
        
        return array_diff_key($attributes, array_flip($this->excludeFields));
    }
    
    /**
     * Get original values that were changed
     */
    protected function getOriginalValues(Model $model): array
    {
        $changed = $model->getChanges();
        $original = [];
        
        foreach (array_keys($changed) as $key) {
            if (in_array($key, $this->excludeFields)) {
                continue;
            }
            $original[$key] = $model->getOriginal($key);
        }
        
        return $original;
    }
    
    /**
     * Get changed values
     */
    protected function getChangedValues(Model $model): array
    {
        $changed = $model->getChanges();
        
        return array_diff_key($changed, array_flip($this->excludeFields));
    }
}
