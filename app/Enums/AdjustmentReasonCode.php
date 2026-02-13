<?php

namespace App\Enums;

enum AdjustmentReasonCode: string
{
    case SYSTEM_ERROR = 'system_error';
    case PAYMENT_ERROR = 'payment_error';
    case REFUND_MANUAL = 'refund_manual';
    case BONUS_CAMPAIGN = 'bonus_campaign';
    case COMPENSATION = 'compensation';
    case MIGRATION = 'migration';
    case TECHNICAL_ISSUE = 'technical_issue';
    case FRAUD_RECOVERY = 'fraud_recovery';
    case PROMOTION_BONUS = 'promotion_bonus';
    case LOYALTY_REWARD = 'loyalty_reward';
    case CHARGEBACK = 'chargeback';
    case DISPUTE_RESOLUTION = 'dispute_resolution';
    case TEST_CORRECTION = 'test_correction';
    case DATA_CORRECTION = 'data_correction';
    case MANUAL_OVERRIDE = 'manual_override';
    case OTHER = 'other';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match($this) {
            self::SYSTEM_ERROR => 'System Error',
            self::PAYMENT_ERROR => 'Payment Processing Error',
            self::REFUND_MANUAL => 'Manual Refund',
            self::BONUS_CAMPAIGN => 'Bonus Campaign',
            self::COMPENSATION => 'Service Compensation',
            self::MIGRATION => 'Data Migration',
            self::TECHNICAL_ISSUE => 'Technical Issue',
            self::FRAUD_RECOVERY => 'Fraud Recovery',
            self::PROMOTION_BONUS => 'Promotion Bonus',
            self::LOYALTY_REWARD => 'Loyalty Reward',
            self::CHARGEBACK => 'Chargeback',
            self::DISPUTE_RESOLUTION => 'Dispute Resolution',
            self::TEST_CORRECTION => 'Test Correction',
            self::DATA_CORRECTION => 'Data Correction',
            self::MANUAL_OVERRIDE => 'Manual Override',
            self::OTHER => 'Other'
        };
    }

    /**
     * Get description
     */
    public function description(): string
    {
        return match($this) {
            self::SYSTEM_ERROR => 'Balance adjustment due to system errors or technical failures',
            self::PAYMENT_ERROR => 'Corrections for failed or incorrect payment processing',
            self::REFUND_MANUAL => 'Manual customer refund processing outside normal flow',
            self::BONUS_CAMPAIGN => 'Marketing campaign bonus distribution to users',
            self::COMPENSATION => 'Compensation for service issues or downtime',
            self::MIGRATION => 'Balance migration from legacy or external systems',
            self::TECHNICAL_ISSUE => 'Adjustments due to technical problems or bugs',
            self::FRAUD_RECOVERY => 'Recovery or correction related to fraudulent activities',
            self::PROMOTION_BONUS => 'Promotional bonuses and special offers',
            self::LOYALTY_REWARD => 'Loyalty program rewards and points conversion',
            self::CHARGEBACK => 'Payment chargeback processing and corrections',
            self::DISPUTE_RESOLUTION => 'Resolution of payment or service disputes',
            self::TEST_CORRECTION => 'Corrections for test transactions or data',
            self::DATA_CORRECTION => 'Corrections for incorrect data entry or import',
            self::MANUAL_OVERRIDE => 'Manual override for exceptional circumstances',
            self::OTHER => 'Other adjustment reasons not covered by standard categories'
        };
    }

    /**
     * Get risk level
     */
    public function riskLevel(): string
    {
        return match($this) {
            self::SYSTEM_ERROR, 
            self::TECHNICAL_ISSUE, 
            self::BONUS_CAMPAIGN, 
            self::PROMOTION_BONUS, 
            self::LOYALTY_REWARD,
            self::TEST_CORRECTION => 'low',

            self::PAYMENT_ERROR, 
            self::COMPENSATION, 
            self::DATA_CORRECTION, 
            self::OTHER => 'medium',

            self::REFUND_MANUAL, 
            self::MIGRATION, 
            self::FRAUD_RECOVERY, 
            self::CHARGEBACK, 
            self::DISPUTE_RESOLUTION, 
            self::MANUAL_OVERRIDE => 'high'
        };
    }

    /**
     * Get allowed directions
     */
    public function allowedDirections(): array
    {
        return match($this) {
            // Credit only
            self::BONUS_CAMPAIGN, 
            self::COMPENSATION, 
            self::PROMOTION_BONUS, 
            self::LOYALTY_REWARD => ['credit'],

            // Debit only
            self::REFUND_MANUAL, 
            self::CHARGEBACK => ['debit'],

            // Both directions
            default => ['credit', 'debit']
        };
    }

    /**
     * Get default auto-approval limit
     */
    public function defaultAutoApprovalLimit(): float
    {
        return match($this) {
            // High limits for low-risk operations
            self::SYSTEM_ERROR => 100000.00,
            self::PAYMENT_ERROR => 200000.00,
            self::BONUS_CAMPAIGN => 50000.00,
            self::COMPENSATION => 150000.00,
            self::TECHNICAL_ISSUE => 75000.00,
            self::PROMOTION_BONUS => 25000.00,
            self::LOYALTY_REWARD => 30000.00,
            self::TEST_CORRECTION => 10000.00,
            self::DATA_CORRECTION => 50000.00,

            // No auto-approval for high-risk operations
            self::REFUND_MANUAL, 
            self::MIGRATION, 
            self::FRAUD_RECOVERY, 
            self::CHARGEBACK, 
            self::DISPUTE_RESOLUTION, 
            self::MANUAL_OVERRIDE => 0.00,

            // Medium limit for others
            self::OTHER => 25000.00
        };
    }

    /**
     * Get documentation requirements
     */
    public function documentationRequirements(): array
    {
        return match($this) {
            // Minimal documentation
            self::BONUS_CAMPAIGN, 
            self::PROMOTION_BONUS, 
            self::LOYALTY_REWARD => ['detailed_notes'],

            // Standard documentation
            self::SYSTEM_ERROR, 
            self::TECHNICAL_ISSUE, 
            self::COMPENSATION, 
            self::TEST_CORRECTION, 
            self::DATA_CORRECTION => ['detailed_notes', 'supporting_evidence'],

            // Enhanced documentation
            self::PAYMENT_ERROR => ['detailed_notes', 'supporting_evidence', 'payment_proof'],

            // Maximum documentation
            self::REFUND_MANUAL, 
            self::MIGRATION, 
            self::FRAUD_RECOVERY, 
            self::CHARGEBACK, 
            self::DISPUTE_RESOLUTION, 
            self::MANUAL_OVERRIDE => ['detailed_notes', 'supporting_evidence', 'manager_approval', 'attachment'],

            // Other with standard requirements
            self::OTHER => ['detailed_notes', 'manager_approval']
        };
    }

    /**
     * Check if reason requires manager approval regardless of amount
     */
    public function requiresManagerApproval(): bool
    {
        return in_array('manager_approval', $this->documentationRequirements());
    }

    /**
     * Check if reason requires attachment
     */
    public function requiresAttachment(): bool
    {
        return in_array('attachment', $this->documentationRequirements());
    }

    /**
     * Get icon for UI display
     */
    public function icon(): string
    {
        return match($this) {
            self::SYSTEM_ERROR, self::TECHNICAL_ISSUE => 'fa-bug',
            self::PAYMENT_ERROR => 'fa-credit-card-alt',
            self::REFUND_MANUAL => 'fa-undo',
            self::BONUS_CAMPAIGN, self::PROMOTION_BONUS => 'fa-gift',
            self::COMPENSATION => 'fa-handshake',
            self::MIGRATION => 'fa-database',
            self::FRAUD_RECOVERY => 'fa-shield-alt',
            self::LOYALTY_REWARD => 'fa-star',
            self::CHARGEBACK => 'fa-ban',
            self::DISPUTE_RESOLUTION => 'fa-gavel',
            self::TEST_CORRECTION => 'fa-flask',
            self::DATA_CORRECTION => 'fa-edit',
            self::MANUAL_OVERRIDE => 'fa-user-cog',
            self::OTHER => 'fa-question-circle'
        };
    }

    /**
     * Get color for UI display
     */
    public function color(): string
    {
        return match($this->riskLevel()) {
            'low' => 'success',
            'medium' => 'warning',
            'high' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Get all reason codes as options array
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /**
     * Get options by direction
     */
    public static function optionsByDirection(string $direction): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            if (in_array($direction, $case->allowedDirections())) {
                $options[$case->value] = $case->label();
            }
        }
        return $options;
    }

    /**
     * Get options by risk level
     */
    public static function optionsByRiskLevel(string $riskLevel): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            if ($case->riskLevel() === $riskLevel) {
                $options[$case->value] = $case->label();
            }
        }
        return $options;
    }

    /**
     * Validate reason code
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, array_column(self::cases(), 'value'));
    }

    /**
     * Get reason code by value
     */
    public static function fromValue(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }
        return null;
    }

    /**
     * Get grouped options for select dropdown
     */
    public static function groupedOptions(): array
    {
        $groups = [
            'Low Risk' => [],
            'Medium Risk' => [],
            'High Risk' => []
        ];

        foreach (self::cases() as $case) {
            $groupKey = match($case->riskLevel()) {
                'low' => 'Low Risk',
                'medium' => 'Medium Risk',
                'high' => 'High Risk'
            };
            
            $groups[$groupKey][$case->value] = $case->label();
        }

        return $groups;
    }

    /**
     * Get statistics data
     */
    public static function getStatistics(): array
    {
        $stats = [];
        
        foreach (self::cases() as $case) {
            $stats[] = [
                'code' => $case->value,
                'label' => $case->label(),
                'risk_level' => $case->riskLevel(),
                'auto_approval_limit' => $case->defaultAutoApprovalLimit(),
                'requires_manager_approval' => $case->requiresManagerApproval(),
                'requires_attachment' => $case->requiresAttachment(),
                'allowed_directions' => $case->allowedDirections(),
                'icon' => $case->icon(),
                'color' => $case->color()
            ];
        }

        return $stats;
    }
}