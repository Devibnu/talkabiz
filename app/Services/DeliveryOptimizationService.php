<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DeliveryOptimizationService - Smart Delivery Timing
 * 
 * Service ini mengoptimasi waktu pengiriman pesan untuk:
 * 1. Menghindari jam rawan (23:00 - 05:00)
 * 2. Menerapkan delay antar pesan
 * 3. Smooth burst (gradual sending)
 * 
 * PRINSIP:
 * ========
 * - SAFE: Tidak mengirim di jam yang bisa menyebabkan block
 * - SMOOTH: Delay antar pesan untuk hindari burst
 * - SMART: Schedule ulang ke waktu yang lebih baik
 * 
 * USAGE:
 * ======
 * $service = app(DeliveryOptimizationService::class);
 * 
 * // Check jika jam sekarang aman untuk kirim
 * if ($service->isSafeToSend()) {
 *     // Kirim pesan
 * } else {
 *     $delay = $service->getDelayUntilSafeHour();
 *     // Release job dengan delay
 * }
 * 
 * @author Senior SaaS Optimization Engineer
 */
class DeliveryOptimizationService
{
    // ==================== CONFIGURATION ====================

    /**
     * Jam mulai periode rawan (tidak disarankan kirim)
     * Default: 23:00 (11 PM)
     */
    const RISKY_HOUR_START = 23;

    /**
     * Jam akhir periode rawan
     * Default: 05:00 (5 AM)
     */
    const RISKY_HOUR_END = 5;

    /**
     * Delay default antar pesan (dalam detik)
     * Untuk menghindari burst yang terlalu cepat
     */
    const DEFAULT_MESSAGE_DELAY_SECONDS = 3;

    /**
     * Delay minimum antar pesan (dalam detik)
     */
    const MIN_MESSAGE_DELAY_SECONDS = 1;

    /**
     * Delay maksimum antar pesan (dalam detik)
     */
    const MAX_MESSAGE_DELAY_SECONDS = 10;

    /**
     * Jam optimal untuk kirim (prioritas tinggi)
     * 09:00 - 12:00 dan 14:00 - 17:00
     */
    const OPTIMAL_HOURS = [9, 10, 11, 14, 15, 16, 17];

    /**
     * Jam acceptable untuk kirim (prioritas medium)
     * 08:00, 12:00-13:00, 18:00-21:00
     */
    const ACCEPTABLE_HOURS = [8, 12, 13, 18, 19, 20, 21];

    // ==================== SAFE HOUR CHECKS ====================

    /**
     * Check apakah saat ini aman untuk mengirim pesan
     * 
     * @return bool
     */
    public function isSafeToSend(): bool
    {
        $hour = Carbon::now()->hour;
        return !$this->isRiskyHour($hour);
    }

    /**
     * Check apakah jam tertentu adalah jam rawan
     * 
     * @param int $hour (0-23)
     * @return bool
     */
    public function isRiskyHour(int $hour): bool
    {
        // Jam rawan: 23:00 - 05:00
        if (self::RISKY_HOUR_START < self::RISKY_HOUR_END) {
            // Normal range (e.g., 01:00 - 05:00)
            return $hour >= self::RISKY_HOUR_START && $hour < self::RISKY_HOUR_END;
        } else {
            // Wrap-around range (e.g., 23:00 - 05:00)
            return $hour >= self::RISKY_HOUR_START || $hour < self::RISKY_HOUR_END;
        }
    }

    /**
     * Check apakah jam tertentu adalah jam optimal
     * 
     * @param int $hour (0-23)
     * @return bool
     */
    public function isOptimalHour(int $hour): bool
    {
        return in_array($hour, self::OPTIMAL_HOURS);
    }

    /**
     * Get waktu tunggu sampai jam aman untuk kirim (dalam detik)
     * 
     * @return int Delay dalam detik
     */
    public function getDelayUntilSafeHour(): int
    {
        if ($this->isSafeToSend()) {
            return 0;
        }

        $now = Carbon::now();
        $safeTime = $now->copy();

        // Set ke jam aman berikutnya
        if ($now->hour >= self::RISKY_HOUR_START) {
            // Sudah lewat jam mulai rawan, pindah ke hari berikutnya
            $safeTime->addDay()->setHour(self::RISKY_HOUR_END)->setMinute(0)->setSecond(0);
        } else {
            // Masih di jam rawan awal hari, tunggu sampai jam aman
            $safeTime->setHour(self::RISKY_HOUR_END)->setMinute(0)->setSecond(0);
        }

        $delay = $now->diffInSeconds($safeTime);
        
        Log::channel('whatsapp')->info('DeliveryOptimization: Delaying until safe hour', [
            'current_hour' => $now->hour,
            'safe_hour' => self::RISKY_HOUR_END,
            'delay_seconds' => $delay,
            'resume_at' => $safeTime->toDateTimeString(),
        ]);

        return $delay;
    }

    /**
     * Get waktu terbaik untuk mengirim pesan hari ini
     * 
     * @return Carbon|null
     */
    public function getNextOptimalSendTime(): ?Carbon
    {
        $now = Carbon::now();
        $currentHour = $now->hour;

        // Cari jam optimal berikutnya
        foreach (self::OPTIMAL_HOURS as $hour) {
            if ($hour > $currentHour) {
                return $now->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            }
        }

        // Jika sudah lewat semua jam optimal hari ini, cari jam acceptable
        foreach (self::ACCEPTABLE_HOURS as $hour) {
            if ($hour > $currentHour) {
                return $now->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            }
        }

        // Jika sudah lewat semua jam bagus, schedule untuk besok pagi
        return $now->copy()->addDay()->setHour(self::OPTIMAL_HOURS[0])->setMinute(0)->setSecond(0);
    }

    // ==================== DELAY CALCULATION ====================

    /**
     * Get delay yang direkomendasikan antar pesan (dalam detik)
     * Menggunakan smooth calculation berdasarkan load
     * 
     * @param int $pendingCount Jumlah pesan pending dalam queue
     * @param string $tier Tier klien (starter, growth, pro)
     * @return int
     */
    public function getRecommendedDelay(int $pendingCount = 0, string $tier = 'starter'): int
    {
        // Base delay
        $delay = self::DEFAULT_MESSAGE_DELAY_SECONDS;

        // Adjust berdasarkan tier
        switch ($tier) {
            case 'starter':
                $delay = 5; // Lebih lambat untuk starter
                break;
            case 'growth':
                $delay = 3;
                break;
            case 'pro':
            case 'corporate':
                $delay = 2;
                break;
        }

        // Adjust berdasarkan pending count (smooth burst)
        if ($pendingCount > 100) {
            $delay += 2; // Tambah delay jika banyak pending
        } elseif ($pendingCount > 50) {
            $delay += 1;
        }

        // Clamp to min/max
        return max(self::MIN_MESSAGE_DELAY_SECONDS, min(self::MAX_MESSAGE_DELAY_SECONDS, $delay));
    }

    /**
     * Calculate staggered delay untuk batch sending
     * Menghindari semua pesan dikirim sekaligus
     * 
     * @param int $index Index pesan dalam batch (0, 1, 2, ...)
     * @param int $batchSize Total pesan dalam batch
     * @param string $tier Tier klien
     * @return int Delay dalam detik untuk pesan ini
     */
    public function getStaggeredDelay(int $index, int $batchSize, string $tier = 'starter'): int
    {
        $baseDelay = $this->getRecommendedDelay(0, $tier);
        
        // Stagger: setiap pesan punya delay berbeda
        // Pesan 0: 0 detik, Pesan 1: baseDelay, Pesan 2: baseDelay*2, dst
        return $index * $baseDelay;
    }

    // ==================== VALIDATION METHODS ====================

    /**
     * Validate apakah waktu scheduled campaign masuk akal
     * 
     * @param Carbon $scheduledTime
     * @return array ['valid' => bool, 'warning' => string|null, 'suggested' => Carbon|null]
     */
    public function validateScheduledTime(Carbon $scheduledTime): array
    {
        $hour = $scheduledTime->hour;

        // Check jam rawan
        if ($this->isRiskyHour($hour)) {
            return [
                'valid' => false,
                'warning' => 'Waktu yang dipilih berada di jam rawan (23:00 - 05:00). Pengiriman di jam ini berisiko tinggi untuk di-block.',
                'suggested' => $this->getNextOptimalSendTime(),
            ];
        }

        // Check jam acceptable tapi tidak optimal
        if (!$this->isOptimalHour($hour) && !in_array($hour, self::ACCEPTABLE_HOURS)) {
            return [
                'valid' => true,
                'warning' => 'Waktu ini bukan jam optimal untuk pengiriman. Pertimbangkan jam 09:00-12:00 atau 14:00-17:00 untuk hasil terbaik.',
                'suggested' => $this->getNextOptimalSendTime(),
            ];
        }

        // Jam optimal
        if ($this->isOptimalHour($hour)) {
            return [
                'valid' => true,
                'warning' => null,
                'suggested' => null,
            ];
        }

        // Jam acceptable
        return [
            'valid' => true,
            'warning' => null,
            'suggested' => null,
        ];
    }

    // ==================== DELIVERY TIPS ====================

    /**
     * Get tips pengiriman untuk user
     * 
     * @param string $tier
     * @return array
     */
    public function getDeliveryTips(string $tier = 'starter'): array
    {
        $tips = [
            [
                'icon' => 'clock',
                'text' => 'Kirim di jam 09:00-12:00 atau 14:00-17:00 untuk hasil terbaik',
            ],
            [
                'icon' => 'pause',
                'text' => 'Kirim bertahap agar aman - hindari mengirim semua pesan sekaligus',
            ],
            [
                'icon' => 'file-alt',
                'text' => 'Gunakan template yang jelas dan tidak berlebihan',
            ],
            [
                'icon' => 'ban',
                'text' => 'Hindari kata promosi berlebihan seperti GRATIS!!! atau DISKON BESAR!!!',
            ],
        ];

        // Tips tambahan untuk starter
        if ($tier === 'starter') {
            $tips[] = [
                'icon' => 'users',
                'text' => 'Paket Starter dibatasi 100 pesan/hari. Prioritaskan kontak paling penting.',
            ];
        }

        return $tips;
    }

    /**
     * Get warning message berdasarkan kondisi
     * 
     * @param string $condition
     * @return string|null
     */
    public function getWarningMessage(string $condition): ?string
    {
        $messages = [
            'risky_hour' => '⚠️ Saat ini adalah jam rawan (23:00-05:00). Pengiriman akan dijadwalkan ulang ke jam 05:00.',
            'high_volume' => '⚠️ Mengirim banyak pesan sekaligus dapat meningkatkan risiko block. Sistem akan mengirim secara bertahap.',
            'quota_low' => '⚠️ Kuota Anda hampir habis. Pertimbangkan upgrade paket untuk melanjutkan pengiriman.',
            'template_spam' => '⚠️ Template Anda mengandung kata yang berpotensi ditandai sebagai spam. Pertimbangkan untuk merevisi.',
        ];

        return $messages[$condition] ?? null;
    }
}
