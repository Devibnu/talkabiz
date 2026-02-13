<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WhatsApp Health Score History Model
 * 
 * Menyimpan snapshot harian untuk trending 7 hari.
 */
class WhatsappHealthScoreHistory extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_health_score_history';

    protected $fillable = [
        'connection_id',
        'date',
        'score',
        'status',
        'delivery_rate',
        'failure_rate',
        'sent_count',
        'delivered_count',
        'failed_count',
    ];

    protected $casts = [
        'date' => 'date',
        'score' => 'decimal:2',
        'delivery_rate' => 'decimal:2',
        'failure_rate' => 'decimal:2',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsappConnection::class, 'connection_id');
    }

    // ==========================================
    // STATIC METHODS
    // ==========================================

    /**
     * Get or create today's history record
     */
    public static function getOrCreateToday(int $connectionId): self
    {
        return self::firstOrCreate(
            [
                'connection_id' => $connectionId,
                'date' => now()->toDateString(),
            ],
            [
                'score' => 100,
                'status' => WhatsappHealthScore::STATUS_EXCELLENT,
                'delivery_rate' => 100,
                'failure_rate' => 0,
                'sent_count' => 0,
                'delivered_count' => 0,
                'failed_count' => 0,
            ]
        );
    }

    /**
     * Update today's snapshot from health score
     */
    public static function updateFromHealthScore(WhatsappHealthScore $healthScore): self
    {
        return self::updateOrCreate(
            [
                'connection_id' => $healthScore->connection_id,
                'date' => now()->toDateString(),
            ],
            [
                'score' => $healthScore->score,
                'status' => $healthScore->status,
                'delivery_rate' => $healthScore->delivery_rate,
                'failure_rate' => $healthScore->failure_rate,
                'sent_count' => $healthScore->total_sent,
                'delivered_count' => $healthScore->total_delivered,
                'failed_count' => $healthScore->total_failed,
            ]
        );
    }

    /**
     * Get trend for connection (last N days)
     */
    public static function getTrend(int $connectionId, int $days = 7): array
    {
        $history = self::where('connection_id', $connectionId)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date', 'asc')
            ->get();

        // Fill missing dates with default values
        $trend = [];
        $startDate = now()->subDays($days - 1);
        
        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->toDateString();
            $record = $history->firstWhere('date', $date);
            
            $trend[] = [
                'date' => $date,
                'score' => $record?->score ?? null,
                'status' => $record?->status ?? null,
                'delivery_rate' => $record?->delivery_rate ?? null,
                'failure_rate' => $record?->failure_rate ?? null,
                'sent_count' => $record?->sent_count ?? 0,
            ];
        }

        return $trend;
    }

    /**
     * Calculate trend direction
     */
    public static function getTrendDirection(int $connectionId, int $days = 7): string
    {
        $history = self::where('connection_id', $connectionId)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date', 'asc')
            ->pluck('score')
            ->toArray();

        if (count($history) < 2) {
            return 'stable';
        }

        $firstHalf = array_slice($history, 0, intval(count($history) / 2));
        $secondHalf = array_slice($history, intval(count($history) / 2));

        $firstAvg = count($firstHalf) > 0 ? array_sum($firstHalf) / count($firstHalf) : 0;
        $secondAvg = count($secondHalf) > 0 ? array_sum($secondHalf) / count($secondHalf) : 0;

        $diff = $secondAvg - $firstAvg;

        if ($diff > 5) {
            return 'up';
        }
        if ($diff < -5) {
            return 'down';
        }
        return 'stable';
    }
}
