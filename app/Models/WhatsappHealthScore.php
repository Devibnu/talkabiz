<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WhatsApp Health Score Model
 * 
 * Menyimpan skor deliverability health per nomor WhatsApp.
 * Dihitung setiap 30 menit atau saat webhook delivery masuk.
 * 
 * SKOR 0-100:
 * - EXCELLENT (85-100): Deliverability optimal
 * - GOOD (70-84): Deliverability baik
 * - WARNING (50-69): Perlu perhatian, auto-reduce batch
 * - CRITICAL (0-49): Risiko tinggi, pause semua aktivitas
 */
class WhatsappHealthScore extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_health_scores';

    // ==========================================
    // STATUS CONSTANTS
    // ==========================================
    public const STATUS_EXCELLENT = 'excellent';
    public const STATUS_GOOD = 'good';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';

    // ==========================================
    // SCORE THRESHOLDS
    // ==========================================
    public const THRESHOLD_EXCELLENT = 85;
    public const THRESHOLD_GOOD = 70;
    public const THRESHOLD_WARNING = 50;
    // Below 50 = CRITICAL

    // ==========================================
    // WEIGHT CONSTANTS
    // ==========================================
    public const WEIGHT_DELIVERY = 40;    // 40%
    public const WEIGHT_FAILURE = 25;     // 25%
    public const WEIGHT_USER_SIGNAL = 20; // 20%
    public const WEIGHT_PATTERN = 10;     // 10%
    public const WEIGHT_TEMPLATE_MIX = 5; // 5%

    // ==========================================
    // RATE THRESHOLDS FOR INDIVIDUAL SCORES
    // ==========================================
    public const DELIVERY_RATE_EXCELLENT = 95; // >= 95% delivery = 100 score
    public const DELIVERY_RATE_GOOD = 85;      // >= 85% = 80 score
    public const DELIVERY_RATE_WARNING = 70;   // >= 70% = 50 score
    public const DELIVERY_RATE_CRITICAL = 50;  // >= 50% = 25 score

    public const FAILURE_RATE_EXCELLENT = 2;   // <= 2% failure = 100 score
    public const FAILURE_RATE_GOOD = 5;        // <= 5% = 80 score
    public const FAILURE_RATE_WARNING = 10;    // <= 10% = 50 score
    public const FAILURE_RATE_CRITICAL = 20;   // <= 20% = 25 score

    public const BLOCK_RATE_EXCELLENT = 0.1;   // <= 0.1% block = 100 score
    public const BLOCK_RATE_GOOD = 0.5;        // <= 0.5% = 80 score
    public const BLOCK_RATE_WARNING = 1;       // <= 1% = 50 score
    public const BLOCK_RATE_CRITICAL = 2;      // <= 2% = 25 score

    // Spike factor thresholds
    public const SPIKE_FACTOR_EXCELLENT = 1.5; // <= 1.5x normal
    public const SPIKE_FACTOR_GOOD = 2;        // <= 2x
    public const SPIKE_FACTOR_WARNING = 3;     // <= 3x
    public const SPIKE_FACTOR_CRITICAL = 5;    // <= 5x

    // Template mix (unique templates used in window)
    public const TEMPLATE_MIX_EXCELLENT = 5;   // >= 5 unique templates
    public const TEMPLATE_MIX_GOOD = 3;        // >= 3
    public const TEMPLATE_MIX_WARNING = 2;     // >= 2
    public const TEMPLATE_MIX_CRITICAL = 1;    // 1 only

    // ==========================================
    // AUTO-ACTION THRESHOLDS
    // ==========================================
    public const ACTION_REDUCE_BATCH_SCORE = 69;     // Score <= 69 → reduce batch
    public const ACTION_ADD_DELAY_SCORE = 60;        // Score <= 60 → add delay
    public const ACTION_PAUSE_CAMPAIGN_SCORE = 49;   // Score <= 49 → pause campaigns
    public const ACTION_PAUSE_WARMUP_SCORE = 45;     // Score <= 45 → pause warmup
    public const ACTION_BLOCK_RECONNECT_SCORE = 40;  // Score <= 40 → block reconnect

    // Auto-action parameters
    public const REDUCED_BATCH_SIZE = 50;     // Reduce to 50 per batch
    public const ADDED_DELAY_MS = 5000;       // Add 5 seconds between batches

    protected $fillable = [
        'connection_id',
        'klien_id',
        'score',
        'status',
        'previous_status',
        'delivery_score',
        'failure_score',
        'user_signal_score',
        'pattern_score',
        'template_mix_score',
        'total_sent',
        'total_delivered',
        'total_failed',
        'total_blocked',
        'total_reported',
        'delivery_rate',
        'failure_rate',
        'block_rate',
        'send_spike_factor',
        'unique_templates_used',
        'peak_hourly_sends',
        'avg_hourly_sends',
        'calculation_window',
        'window_start',
        'window_end',
        'batch_size_reduced',
        'delay_added',
        'campaign_paused',
        'warmup_paused',
        'reconnect_blocked',
        'breakdown_details',
        'recommendations',
        'calculated_at',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'delivery_score' => 'decimal:2',
        'failure_score' => 'decimal:2',
        'user_signal_score' => 'decimal:2',
        'pattern_score' => 'decimal:2',
        'template_mix_score' => 'decimal:2',
        'delivery_rate' => 'decimal:2',
        'failure_rate' => 'decimal:2',
        'block_rate' => 'decimal:2',
        'send_spike_factor' => 'decimal:2',
        'batch_size_reduced' => 'boolean',
        'delay_added' => 'boolean',
        'campaign_paused' => 'boolean',
        'warmup_paused' => 'boolean',
        'reconnect_blocked' => 'boolean',
        'breakdown_details' => 'array',
        'recommendations' => 'array',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'calculated_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'klien_id');
    }

    // ==========================================
    // STATIC METHODS
    // ==========================================

    /**
     * Get all statuses with labels
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_EXCELLENT => [
                'label' => 'Excellent',
                'color' => 'success',
                'icon' => 'fa-check-circle',
                'description' => 'Deliverability optimal, tidak perlu tindakan',
                'badge_class' => 'bg-gradient-success',
            ],
            self::STATUS_GOOD => [
                'label' => 'Good',
                'color' => 'info',
                'icon' => 'fa-thumbs-up',
                'description' => 'Deliverability baik, pantau terus',
                'badge_class' => 'bg-gradient-info',
            ],
            self::STATUS_WARNING => [
                'label' => 'Warning',
                'color' => 'warning',
                'icon' => 'fa-exclamation-triangle',
                'description' => 'Perlu perhatian, batch dikurangi otomatis',
                'badge_class' => 'bg-gradient-warning',
            ],
            self::STATUS_CRITICAL => [
                'label' => 'Critical',
                'color' => 'danger',
                'icon' => 'fa-times-circle',
                'description' => 'Risiko tinggi, aktivitas di-pause',
                'badge_class' => 'bg-gradient-danger',
            ],
        ];
    }

    /**
     * Get status from score
     */
    public static function getStatusFromScore(float $score): string
    {
        if ($score >= self::THRESHOLD_EXCELLENT) {
            return self::STATUS_EXCELLENT;
        }
        if ($score >= self::THRESHOLD_GOOD) {
            return self::STATUS_GOOD;
        }
        if ($score >= self::THRESHOLD_WARNING) {
            return self::STATUS_WARNING;
        }
        return self::STATUS_CRITICAL;
    }

    /**
     * Calculate individual score from rate (for delivery rate - higher is better)
     */
    public static function calculateDeliveryScore(float $rate): float
    {
        if ($rate >= self::DELIVERY_RATE_EXCELLENT) {
            return 100;
        }
        if ($rate >= self::DELIVERY_RATE_GOOD) {
            return 80 + (($rate - self::DELIVERY_RATE_GOOD) / 10) * 20;
        }
        if ($rate >= self::DELIVERY_RATE_WARNING) {
            return 50 + (($rate - self::DELIVERY_RATE_WARNING) / 15) * 30;
        }
        if ($rate >= self::DELIVERY_RATE_CRITICAL) {
            return 25 + (($rate - self::DELIVERY_RATE_CRITICAL) / 20) * 25;
        }
        return max(0, ($rate / self::DELIVERY_RATE_CRITICAL) * 25);
    }

    /**
     * Calculate failure score (inverse - lower failure rate is better)
     */
    public static function calculateFailureScore(float $rate): float
    {
        if ($rate <= self::FAILURE_RATE_EXCELLENT) {
            return 100;
        }
        if ($rate <= self::FAILURE_RATE_GOOD) {
            return 100 - (($rate - self::FAILURE_RATE_EXCELLENT) / 3) * 20;
        }
        if ($rate <= self::FAILURE_RATE_WARNING) {
            return 80 - (($rate - self::FAILURE_RATE_GOOD) / 5) * 30;
        }
        if ($rate <= self::FAILURE_RATE_CRITICAL) {
            return 50 - (($rate - self::FAILURE_RATE_WARNING) / 10) * 25;
        }
        return max(0, 25 - (($rate - self::FAILURE_RATE_CRITICAL) / 10) * 25);
    }

    /**
     * Calculate user signal score (from block + report rate)
     */
    public static function calculateUserSignalScore(float $blockRate, float $reportRate = 0): float
    {
        $combinedRate = $blockRate + ($reportRate * 2); // Reports weighted higher

        if ($combinedRate <= self::BLOCK_RATE_EXCELLENT) {
            return 100;
        }
        if ($combinedRate <= self::BLOCK_RATE_GOOD) {
            return 80 + ((self::BLOCK_RATE_GOOD - $combinedRate) / 0.4) * 20;
        }
        if ($combinedRate <= self::BLOCK_RATE_WARNING) {
            return 50 + ((self::BLOCK_RATE_WARNING - $combinedRate) / 0.5) * 30;
        }
        if ($combinedRate <= self::BLOCK_RATE_CRITICAL) {
            return 25 + ((self::BLOCK_RATE_CRITICAL - $combinedRate) / 1) * 25;
        }
        return max(0, 25 - (($combinedRate - self::BLOCK_RATE_CRITICAL) / 2) * 25);
    }

    /**
     * Calculate pattern score (from spike factor)
     */
    public static function calculatePatternScore(float $spikeFactor): float
    {
        if ($spikeFactor <= self::SPIKE_FACTOR_EXCELLENT) {
            return 100;
        }
        if ($spikeFactor <= self::SPIKE_FACTOR_GOOD) {
            return 80 + ((self::SPIKE_FACTOR_GOOD - $spikeFactor) / 0.5) * 20;
        }
        if ($spikeFactor <= self::SPIKE_FACTOR_WARNING) {
            return 50 + ((self::SPIKE_FACTOR_WARNING - $spikeFactor) / 1) * 30;
        }
        if ($spikeFactor <= self::SPIKE_FACTOR_CRITICAL) {
            return 25 + ((self::SPIKE_FACTOR_CRITICAL - $spikeFactor) / 2) * 25;
        }
        return max(0, 25 - (($spikeFactor - self::SPIKE_FACTOR_CRITICAL) / 5) * 25);
    }

    /**
     * Calculate template mix score
     */
    public static function calculateTemplateMixScore(int $uniqueTemplates): float
    {
        if ($uniqueTemplates >= self::TEMPLATE_MIX_EXCELLENT) {
            return 100;
        }
        if ($uniqueTemplates >= self::TEMPLATE_MIX_GOOD) {
            return 80;
        }
        if ($uniqueTemplates >= self::TEMPLATE_MIX_WARNING) {
            return 50;
        }
        return 25; // Only 1 template
    }

    // ==========================================
    // INSTANCE METHODS
    // ==========================================

    /**
     * Check if score dropped from previous calculation
     */
    public function hasDropped(): bool
    {
        return $this->previous_status !== null 
            && $this->getStatusPriority($this->status) > $this->getStatusPriority($this->previous_status);
    }

    /**
     * Get status priority (lower = better)
     */
    private function getStatusPriority(string $status): int
    {
        return match ($status) {
            self::STATUS_EXCELLENT => 1,
            self::STATUS_GOOD => 2,
            self::STATUS_WARNING => 3,
            self::STATUS_CRITICAL => 4,
            default => 5,
        };
    }

    /**
     * Check if any auto-action should be applied
     */
    public function shouldApplyAutoAction(): bool
    {
        return $this->score <= self::ACTION_REDUCE_BATCH_SCORE;
    }

    /**
     * Get required auto-actions based on score
     */
    public function getRequiredActions(): array
    {
        $actions = [];

        if ($this->score <= self::ACTION_BLOCK_RECONNECT_SCORE) {
            $actions['block_reconnect'] = true;
        }
        if ($this->score <= self::ACTION_PAUSE_WARMUP_SCORE) {
            $actions['pause_warmup'] = true;
        }
        if ($this->score <= self::ACTION_PAUSE_CAMPAIGN_SCORE) {
            $actions['pause_campaign'] = true;
        }
        if ($this->score <= self::ACTION_ADD_DELAY_SCORE) {
            $actions['add_delay'] = self::ADDED_DELAY_MS;
        }
        if ($this->score <= self::ACTION_REDUCE_BATCH_SCORE) {
            $actions['reduce_batch'] = self::REDUCED_BATCH_SIZE;
        }

        return $actions;
    }

    /**
     * Get recommendations based on current metrics
     */
    public function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->delivery_rate < self::DELIVERY_RATE_GOOD) {
            $recommendations[] = [
                'type' => 'delivery',
                'priority' => 'high',
                'message' => 'Tingkatkan delivery rate dengan memperlambat pengiriman',
            ];
        }

        if ($this->failure_rate > self::FAILURE_RATE_GOOD) {
            $recommendations[] = [
                'type' => 'failure',
                'priority' => 'high',
                'message' => 'Kurangi kegagalan dengan validasi nomor sebelum kirim',
            ];
        }

        if ($this->block_rate > self::BLOCK_RATE_GOOD) {
            $recommendations[] = [
                'type' => 'block',
                'priority' => 'critical',
                'message' => 'Block rate tinggi - review konten template dan target audience',
            ];
        }

        if ($this->send_spike_factor > self::SPIKE_FACTOR_GOOD) {
            $recommendations[] = [
                'type' => 'pattern',
                'priority' => 'medium',
                'message' => 'Ratakan pengiriman untuk menghindari spike detection',
            ];
        }

        if ($this->unique_templates_used < self::TEMPLATE_MIX_GOOD) {
            $recommendations[] = [
                'type' => 'template',
                'priority' => 'low',
                'message' => 'Variasikan template untuk mengurangi flag spam',
            ];
        }

        return $recommendations;
    }

    /**
     * Get status info
     */
    public function getStatusInfo(): array
    {
        $statuses = self::getStatuses();
        return $statuses[$this->status] ?? $statuses[self::STATUS_CRITICAL];
    }

    /**
     * Get score color for UI
     */
    public function getScoreColor(): string
    {
        return match ($this->status) {
            self::STATUS_EXCELLENT => '#2dce89',
            self::STATUS_GOOD => '#11cdef',
            self::STATUS_WARNING => '#fb6340',
            self::STATUS_CRITICAL => '#f5365c',
            default => '#8898aa',
        };
    }
}
