<?php

namespace App\Exceptions\Subscription;

/**
 * Thrown when user tries to access a feature not included in their plan
 */
class FeatureNotAllowedException extends SubscriptionException
{
    protected string $errorCode = 'FEATURE_NOT_ALLOWED';

    public function __construct(string $feature, string $planName = null)
    {
        $this->context = [
            'feature' => $feature,
            'plan_name' => $planName,
        ];
        parent::__construct("Feature '{$feature}' is not available in your current plan");
    }

    public function getUserMessage(): string
    {
        $feature = $this->context['feature'] ?? 'Fitur ini';
        $featureLabels = [
            'broadcast' => 'Broadcast Message',
            'auto_reply' => 'Auto Reply',
            'chatbot' => 'Chatbot AI',
            'api_access' => 'API Access',
            'analytics' => 'Analytics Dashboard',
            'multi_agent' => 'Multi Agent',
            'crm' => 'CRM Integration',
            'webhook' => 'Webhook Support',
            'priority_support' => 'Priority Support',
            'custom_branding' => 'Custom Branding',
        ];

        $label = $featureLabels[$feature] ?? ucfirst(str_replace('_', ' ', $feature));
        
        return "Fitur {$label} tidak tersedia di paket Anda. Upgrade untuk mengakses fitur ini.";
    }
}
