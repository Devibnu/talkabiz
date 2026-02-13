<?php

namespace App\DTOs;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * EXECUTIVE OWNER DASHBOARD DTO
 * 
 * Data Transfer Object untuk Executive Dashboard khusus Owner.
 * Format response yang decision-ready dan non-teknis.
 * 
 * Target: Owner/C-Level yang non-teknis
 * Prinsip: Simple, Tenang, Actionable
 */
class ExecutiveOwnerDashboardDTO implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly int $healthScore,
        public readonly string $healthStatus,
        public readonly string $healthMessage,
        public readonly array $topRisks,
        public readonly array $platformStability,
        public readonly array $revenueAtRisk,
        public readonly array $incidentSummary,
        public readonly string $actionRecommendation,
        public readonly string $generatedAt,
        public readonly int $cacheExpiresIn,
    ) {}

    /**
     * Create from raw data
     */
    public static function fromData(array $data): self
    {
        return new self(
            healthScore: $data['health_score'] ?? 0,
            healthStatus: $data['health_status'] ?? 'unknown',
            healthMessage: $data['health_message'] ?? 'Data tidak tersedia',
            topRisks: $data['top_risks'] ?? [],
            platformStability: $data['platform_stability'] ?? [],
            revenueAtRisk: $data['revenue_at_risk'] ?? [],
            incidentSummary: $data['incident_summary'] ?? [],
            actionRecommendation: $data['action_recommendation'] ?? 'Tidak ada aksi mendesak.',
            generatedAt: $data['generated_at'] ?? now()->toIso8601String(),
            cacheExpiresIn: $data['cache_expires_in'] ?? 60,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'health' => [
                'score' => $this->healthScore,
                'status' => $this->healthStatus,
                'message' => $this->healthMessage,
            ],
            'top_risks' => $this->topRisks,
            'platform_stability' => $this->platformStability,
            'revenue_at_risk' => $this->revenueAtRisk,
            'incident_summary' => $this->incidentSummary,
            'action_recommendation' => $this->actionRecommendation,
            'meta' => [
                'generated_at' => $this->generatedAt,
                'cache_expires_in' => $this->cacheExpiresIn,
                'note' => 'Data di-refresh setiap 60-120 detik',
            ],
        ];
    }

    /**
     * JSON serialization
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get health emoji based on score
     */
    public function getHealthEmoji(): string
    {
        return match (true) {
            $this->healthScore >= 90 => '🟢',
            $this->healthScore >= 70 => '🟡',
            $this->healthScore >= 50 => '🟠',
            default => '🔴',
        };
    }

    /**
     * Check if any critical issues exist
     */
    public function hasCriticalIssues(): bool
    {
        return $this->healthScore < 50 || 
               count(array_filter($this->topRisks, fn($r) => ($r['severity'] ?? '') === 'critical')) > 0;
    }
}
