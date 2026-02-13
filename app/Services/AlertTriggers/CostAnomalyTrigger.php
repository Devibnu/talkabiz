<?php

namespace App\Services\AlertTriggers;

use App\Services\AlertService;
use App\Services\LedgerService;
use App\Models\SaldoLedger;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

class CostAnomalyTrigger
{
    public function __construct(
        private AlertService $alertService,
        private LedgerService $ledgerService
    ) {}

    /**
     * Analyze cost patterns untuk specific user
     * 
     * TRIGGER POINT: Hourly analysis untuk detect spike
     */
    public function analyzeDailyCostPattern(int $userId, ?Carbon $date = null): ?array
    {
        $date = $date ?? now();
        
        try {
            Log::debug("Analyzing daily cost pattern", [
                'user_id' => $userId,
                'analysis_date' => $date->format('Y-m-d')
            ]);

            // Ambil cost hari ini
            $todayCost = $this->getDailyCost($userId, $date);
            
            // Ambil rata-rata cost 14 hari sebelumnya (exclude today)
            $averageCost = $this->getAverageDailyCost($userId, $date, 14);
            
            if ($averageCost == 0) {
                return null; // Tidak ada baseline untuk comparison
            }

            // Calculate percentage increase
            $percentageIncrease = (($todayCost - $averageCost) / $averageCost) * 100;
            
            $analysis = [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'today_cost' => $todayCost,
                'average_cost' => $averageCost,
                'percentage_increase' => $percentageIncrease,
                'is_anomaly' => $percentageIncrease >= config('alerts.cost_spike_threshold_percentage', 150),
                'analysis_time' => now()->toISOString()
            ];

            // Trigger alert jika ada spike
            if ($analysis['is_anomaly']) {
                Log::warning("Cost spike detected", $analysis);
                
                $this->alertService->triggerCostSpikeAlert(
                    $userId,
                    $averageCost,
                    $todayCost,
                    $percentageIncrease
                );
            }

            return $analysis;

        } catch (Exception $e) {
            Log::error("Failed to analyze daily cost pattern", [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Bulk analysis untuk semua active users
     * 
     * TRIGGER POINT: Daily job untuk cost monitoring
     */
    public function dailyCostAnomalyDetection(?Carbon $date = null): array
    {
        $date = $date ?? now();
        
        Log::info("Starting daily cost anomaly detection", [
            'analysis_date' => $date->format('Y-m-d')
        ]);

        $results = [
            'users_analyzed' => 0,
            'anomalies_detected' => 0,
            'alerts_triggered' => 0,
            'errors' => 0,
            'processing_time_seconds' => 0,
            'anomalies' => []
        ];

        $startTime = microtime(true);

        try {
            // Get active users yang ada activity hari ini
            $activeUsers = $this->getActiveUsersForDate($date);
            $results['users_analyzed'] = $activeUsers->count();

            foreach ($activeUsers as $user) {
                try {
                    $analysis = $this->analyzeDailyCostPattern($user->user_id, $date);
                    
                    if ($analysis && $analysis['is_anomaly']) {
                        $results['anomalies_detected']++;
                        $results['anomalies'][] = $analysis;
                        $results['alerts_triggered']++;
                    }

                } catch (Exception $e) {
                    $results['errors']++;
                    Log::error("Error analyzing user cost pattern", [
                        'user_id' => $user->user_id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $results['processing_time_seconds'] = round(microtime(true) - $startTime, 2);

            Log::info("Daily cost anomaly detection completed", $results);

        } catch (Exception $e) {
            $results['errors']++;
            $results['processing_time_seconds'] = round(microtime(true) - $startTime, 2);
            
            Log::error("Daily cost anomaly detection failed", [
                'error' => $e->getMessage(),
                'partial_results' => $results
            ]);
        }

        return $results;
    }

    /**
     * Analyze message failure rate untuk user
     * 
     * TRIGGER POINT: Real-time monitoring after message batch
     */
    public function analyzeMessageFailureRate(int $userId, ?Carbon $date = null): ?array
    {
        $date = $date ?? now();
        
        try {
            Log::debug("Analyzing message failure rate", [
                'user_id' => $userId,
                'analysis_date' => $date->format('Y-m-d')
            ]);

            // Ambil message stats hari ini
            $messageStats = $this->getDailyMessageStats($userId, $date);
            
            if ($messageStats['total'] == 0) {
                return null; // Tidak ada messages untuk analyze
            }

            $failureRate = ($messageStats['failed'] / $messageStats['total']) * 100;
            $threshold = config('alerts.failure_rate_threshold_percentage', 15);
            
            $analysis = [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'total_messages' => $messageStats['total'],
                'failed_messages' => $messageStats['failed'],
                'success_messages' => $messageStats['success'],
                'failure_rate' => $failureRate,
                'threshold' => $threshold,
                'is_anomaly' => $failureRate >= $threshold,
                'analysis_time' => now()->toISOString()
            ];

            // Trigger alert jika failure rate tinggi
            if ($analysis['is_anomaly'] && $messageStats['total'] >= 10) { // Minimal 10 messages
                Log::warning("High failure rate detected", $analysis);
                
                $this->alertService->triggerFailureRateAlert(
                    $userId,
                    $failureRate,
                    $messageStats['total'],
                    $messageStats['failed']
                );
            }

            return $analysis;

        } catch (Exception $e) {
            Log::error("Failed to analyze message failure rate", [
                'user_id' => $userId,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Real-time spike detection untuk immediate response
     * 
     * TRIGGER POINT: Setiap hour untuk detect current usage
     */
    public function realTimeUsageMonitoring(int $userId): ?array
    {
        try {
            $now = now();
            $hourStart = $now->copy()->startOfHour();
            
            Log::debug("Real-time usage monitoring", [
                'user_id' => $userId,
                'hour_start' => $hourStart->format('Y-m-d H:i:s')
            ]);

            // Cost dalam 1 jam terakhir
            $hourlyCost = $this->getHourlyCost($userId, $hourStart);
            
            // Average hourly cost dalam 7 hari terakhir untuk jam yang sama
            $averageHourlyCost = $this->getAverageHourlyCostForSameHour($userId, $now);
            
            if ($averageHourlyCost == 0) {
                return null;
            }

            $percentageIncrease = (($hourlyCost - $averageHourlyCost) / $averageHourlyCost) * 100;
            
            $analysis = [
                'user_id' => $userId,
                'hour' => $hourStart->format('Y-m-d H:00'),
                'hourly_cost' => $hourlyCost,
                'average_hourly_cost' => $averageHourlyCost,
                'percentage_increase' => $percentageIncrease,
                'is_real_time_spike' => $percentageIncrease >= 200, // 200% untuk real-time alert
                'analysis_time' => now()->toISOString()
            ];

            // Immediate alert untuk extreme spikes
            if ($analysis['is_real_time_spike'] && $hourlyCost > 50000) { // Minimal 50k per hour
                Log::critical("Real-time cost spike detected", $analysis);
                
                // Cache untuk avoid spam
                $cacheKey = "realtime_spike_alert_{$userId}";
                if (!Cache::has($cacheKey)) {
                    $this->alertService->triggerCostSpikeAlert(
                        $userId,
                        $averageHourlyCost,
                        $hourlyCost,
                        $percentageIncrease
                    );
                    
                    Cache::put($cacheKey, true, now()->addHours(2)); // 2 hour cooldown
                }
            }

            return $analysis;

        } catch (Exception $e) {
            Log::error("Failed real-time usage monitoring", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Detect unusual usage patterns (owner insight)
     */
    public function detectUnusualPatterns(int $userId, int $analysisDays = 30): array
    {
        try {
            $patterns = [
                'user_id' => $userId,
                'analysis_days' => $analysisDays,
                'patterns' => []
            ];

            // 1. Peak hour analysis
            $peakHours = $this->analyzePeakHours($userId, $analysisDays);
            if ($peakHours['is_unusual']) {
                $patterns['patterns'][] = [
                    'type' => 'unusual_peak_hours',
                    'description' => 'Aktivitas tinggi pada jam tidak biasa',
                    'data' => $peakHours
                ];
            }

            // 2. Weekend vs weekday patterns
            $weekendPattern = $this->analyzeWeekendPattern($userId, $analysisDays);
            if ($weekendPattern['is_unusual']) {
                $patterns['patterns'][] = [
                    'type' => 'unusual_weekend_activity',
                    'description' => 'Pola aktivitas weekend tidak biasa',
                    'data' => $weekendPattern
                ];
            }

            // 3. Burst message patterns
            $burstPattern = $this->analyzeBurstPattern($userId, $analysisDays);
            if ($burstPattern['is_unusual']) {
                $patterns['patterns'][] = [
                    'type' => 'message_burst_pattern',
                    'description' => 'Pola burst message yang tidak biasa',
                    'data' => $burstPattern
                ];
            }

            return $patterns;

        } catch (Exception $e) {
            Log::error("Failed to detect unusual patterns", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['user_id' => $userId, 'patterns' => [], 'error' => $e->getMessage()];
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get daily cost dari debit ledger entries
     */
    private function getDailyCost(int $userId, Carbon $date): float
    {
        return DB::table('saldo_ledgers')
            ->where('user_id', $userId)
            ->where('transaction_type', 'debit')
            ->whereDate('created_at', $date->format('Y-m-d'))
            ->sum('amount') ?? 0;
    }

    /**
     * Get average daily cost dalam period tertentu
     */
    private function getAverageDailyCost(int $userId, Carbon $excludeDate, int $days): float
    {
        $endDate = $excludeDate->copy()->subDay();
        $startDate = $endDate->copy()->subDays($days - 1);

        return DB::table('saldo_ledgers')
            ->where('user_id', $userId)
            ->where('transaction_type', 'debit')
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw('AVG(daily_total) as avg_cost')
            ->fromSub(function ($query) use ($userId, $startDate, $endDate) {
                $query->select(DB::raw('DATE(created_at) as date, SUM(amount) as daily_total'))
                      ->from('saldo_ledgers')
                      ->where('user_id', $userId)
                      ->where('transaction_type', 'debit')
                      ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                      ->groupBy('date');
            }, 'daily_costs')
            ->value('avg_cost') ?? 0;
    }

    /**
     * Get users yang aktif pada tanggal tertentu
     */
    private function getActiveUsersForDate(Carbon $date)
    {
        return DB::table('saldo_ledgers')
            ->select('user_id')
            ->where('transaction_type', 'debit')
            ->whereDate('created_at', $date->format('Y-m-d'))
            ->havingRaw('SUM(amount) >= ?', [10000]) // Minimal 10k activity
            ->groupBy('user_id')
            ->get();
    }

    /**
     * Get daily message statistics
     */
    private function getDailyMessageStats(int $userId, Carbon $date): array
    {
        $stats = DB::table('messages')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "SUCCESS" THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = "FAILED" THEN 1 ELSE 0 END) as failed
            ')
            ->where('user_id', $userId) // Asumsi ada user_id di messages table
            ->whereDate('created_at', $date->format('Y-m-d'))
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'success' => (int) ($stats->success ?? 0),
            'failed' => (int) ($stats->failed ?? 0)
        ];
    }

    /**
     * Get hourly cost
     */
    private function getHourlyCost(int $userId, Carbon $hourStart): float
    {
        return DB::table('saldo_ledgers')
            ->where('user_id', $userId)
            ->where('transaction_type', 'debit')
            ->whereBetween('created_at', [
                $hourStart,
                $hourStart->copy()->addHour()
            ])
            ->sum('amount') ?? 0;
    }

    /**
     * Get average hourly cost untuk jam yang sama dalam 7 hari terakhir
     */
    private function getAverageHourlyCostForSameHour(int $userId, Carbon $referenceTime): float
    {
        $hour = $referenceTime->hour;
        $endDate = $referenceTime->copy()->subDay(); // Exclude today
        $startDate = $endDate->copy()->subDays(6); // 7 days total

        return DB::table('saldo_ledgers')
            ->where('user_id', $userId)
            ->where('transaction_type', 'debit')
            ->whereBetween(DB::raw('DATE(created_at)'), [
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ])
            ->whereRaw('HOUR(created_at) = ?', [$hour])
            ->selectRaw('AVG(hourly_total) as avg_cost')
            ->fromSub(function ($query) use ($userId, $startDate, $endDate, $hour) {
                $query->select(DB::raw('DATE(created_at) as date, HOUR(created_at) as hour, SUM(amount) as hourly_total'))
                      ->from('saldo_ledgers')
                      ->where('user_id', $userId)
                      ->where('transaction_type', 'debit')
                      ->whereBetween(DB::raw('DATE(created_at)'), [
                          $startDate->format('Y-m-d'),
                          $endDate->format('Y-m-d')
                      ])
                      ->whereRaw('HOUR(created_at) = ?', [$hour])
                      ->groupBy('date', 'hour');
            }, 'hourly_costs')
            ->value('avg_cost') ?? 0;
    }

    /**
     * Analyze peak hours patterns
     */
    private function analyzePeakHours(int $userId, int $days): array
    {
        // TODO: Implement peak hours analysis
        return ['is_unusual' => false];
    }

    /**
     * Analyze weekend activity patterns
     */
    private function analyzeWeekendPattern(int $userId, int $days): array
    {
        // TODO: Implement weekend pattern analysis
        return ['is_unusual' => false];
    }

    /**
     * Analyze burst message patterns
     */
    private function analyzeBurstPattern(int $userId, int $days): array
    {
        // TODO: Implement burst pattern analysis
        return ['is_unusual' => false];
    }
}