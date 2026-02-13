<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\DompetSaldo;
use App\Models\WaPricing;
use App\Models\WaUsageLog;
use App\Models\TransaksiSaldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SaldoGuardService - ANTI BONCOS Protection
 * 
 * Service ini WAJIB dipanggil SEBELUM kirim pesan WA.
 * 
 * ATURAN KERAS:
 * 1. CEK saldo SEBELUM kirim
 * 2. POTONG saldo HANYA saat pesan SUCCESS
 * 3. Gunakan DB transaction + lockForUpdate
 * 4. Idempotent - no double charge
 * 
 * @package App\Services
 */
class SaldoGuardService
{
    protected LimitService $limitService;

    public function __construct(LimitService $limitService)
    {
        $this->limitService = $limitService;
    }

    /**
     * Pre-send check - Cek SEMUA syarat sebelum kirim
     * 
     * WAJIB dipanggil sebelum kirim pesan!
     * 
     * @param int $klienId
     * @param int $messageCount Jumlah pesan yang akan dikirim
     * @param string $category marketing|utility|authentication|service
     * @param string $feature campaign|inbox|broadcast
     * @return array{allowed: bool, reason?: string, code?: string, details: array}
     */
    public function preSendCheck(
        int $klienId, 
        int $messageCount = 1, 
        string $category = 'marketing',
        string $feature = 'campaign'
    ): array {
        // 1. Check limits first
        $limitCheck = $this->limitService->checkAllLimits($klienId, $messageCount, $feature);
        if (!$limitCheck['allowed']) {
            return $limitCheck;
        }

        // 2. Check saldo
        $saldoCheck = $this->checkSaldo($klienId, $messageCount, $category);
        if (!$saldoCheck['allowed']) {
            return $saldoCheck;
        }

        return [
            'allowed' => true,
            'details' => [
                'limit' => $limitCheck['details'],
                'saldo' => $saldoCheck['details'],
            ],
        ];
    }

    /**
     * Check if saldo is sufficient
     */
    public function checkSaldo(int $klienId, int $messageCount = 1, string $category = 'marketing'): array
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();

        if (!$dompet) {
            return [
                'allowed' => false,
                'reason' => 'Wallet tidak ditemukan',
                'code' => 'wallet_not_found',
                'details' => [],
            ];
        }

        // Get price for category
        $pricePerMessage = WaPricing::getPriceForCategory($category);
        $totalCost = $pricePerMessage * $messageCount;

        $saldoTersedia = $dompet->saldo_tersedia;

        if ($saldoTersedia < $totalCost) {
            return [
                'allowed' => false,
                'reason' => 'Saldo tidak mencukupi',
                'code' => WaUsageLog::REJECTION_INSUFFICIENT_BALANCE,
                'details' => [
                    'saldo_tersedia' => $saldoTersedia,
                    'total_cost' => $totalCost,
                    'price_per_message' => $pricePerMessage,
                    'message_count' => $messageCount,
                    'kekurangan' => $totalCost - $saldoTersedia,
                ],
            ];
        }

        return [
            'allowed' => true,
            'details' => [
                'saldo_tersedia' => $saldoTersedia,
                'total_cost' => $totalCost,
                'price_per_message' => $pricePerMessage,
                'message_count' => $messageCount,
                'sisa_setelah' => $saldoTersedia - $totalCost,
            ],
        ];
    }

    /**
     * Estimate cost for messages
     * 
     * Untuk tampilan estimasi di UI sebelum blast
     */
    public function estimateCost(int $messageCount, string $category = 'marketing'): array
    {
        $pricePerMessage = WaPricing::getPriceForCategory($category);
        $totalCost = $pricePerMessage * $messageCount;

        return [
            'message_count' => $messageCount,
            'category' => $category,
            'price_per_message' => $pricePerMessage,
            'total_cost' => $totalCost,
            'formatted_price' => 'Rp ' . number_format($pricePerMessage, 0, ',', '.'),
            'formatted_total' => 'Rp ' . number_format($totalCost, 0, ',', '.'),
        ];
    }

    /**
     * Charge for a single message - ATOMIC OPERATION
     * 
     * HANYA dipanggil setelah pesan BERHASIL dikirim!
     * 
     * @param int $klienId
     * @param array $messageData Data pesan untuk logging
     * @param string $category Kategori pesan
     * @return array
     */
    public function chargeMessage(int $klienId, array $messageData, string $category = 'marketing'): array
    {
        $pricePerMessage = WaPricing::getPriceForCategory($category);

        return DB::transaction(function () use ($klienId, $messageData, $category, $pricePerMessage) {
            // Lock wallet
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new \DomainException('Wallet tidak ditemukan');
            }

            // Double check saldo
            if ($dompet->saldo_tersedia < $pricePerMessage) {
                // Log rejection
                WaUsageLog::logRejection([
                    'klien_id' => $klienId,
                    'pengguna_id' => $messageData['pengguna_id'] ?? null,
                    'kampanye_id' => $messageData['kampanye_id'] ?? null,
                    'target_kampanye_id' => $messageData['target_kampanye_id'] ?? null,
                    'percakapan_inbox_id' => $messageData['percakapan_inbox_id'] ?? null,
                    'nomor_tujuan' => $messageData['nomor_tujuan'] ?? '',
                    'message_type' => $messageData['message_type'] ?? 'text',
                    'message_category' => $category,
                    'price_per_message' => $pricePerMessage,
                    'saldo_before' => $dompet->saldo_tersedia,
                    'saldo_after' => $dompet->saldo_tersedia,
                ], WaUsageLog::REJECTION_INSUFFICIENT_BALANCE);

                return [
                    'success' => false,
                    'reason' => 'Saldo tidak mencukupi',
                    'code' => WaUsageLog::REJECTION_INSUFFICIENT_BALANCE,
                ];
            }

            $saldoBefore = $dompet->saldo_tersedia;

            // Deduct saldo
            $dompet->saldo_tersedia -= $pricePerMessage;
            $dompet->total_terpakai += $pricePerMessage;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            $saldoAfter = $dompet->saldo_tersedia;

            // Log usage
            $usageLog = WaUsageLog::logSuccess([
                'klien_id' => $klienId,
                'pengguna_id' => $messageData['pengguna_id'] ?? null,
                'kampanye_id' => $messageData['kampanye_id'] ?? null,
                'target_kampanye_id' => $messageData['target_kampanye_id'] ?? null,
                'percakapan_inbox_id' => $messageData['percakapan_inbox_id'] ?? null,
                'nomor_tujuan' => $messageData['nomor_tujuan'] ?? '',
                'message_type' => $messageData['message_type'] ?? 'text',
                'message_category' => $category,
                'price_per_message' => $pricePerMessage,
                'total_cost' => $pricePerMessage,
                'saldo_before' => $saldoBefore,
                'saldo_after' => $saldoAfter,
                'provider_message_id' => $messageData['provider_message_id'] ?? null,
                'provider_status' => $messageData['provider_status'] ?? null,
            ]);

            // Invalidate cache
            $this->limitService->invalidateCache($klienId);

            Log::info('SaldoGuard: Message charged', [
                'klien_id' => $klienId,
                'nomor_tujuan' => $messageData['nomor_tujuan'] ?? '',
                'category' => $category,
                'price' => $pricePerMessage,
                'saldo_before' => $saldoBefore,
                'saldo_after' => $saldoAfter,
                'usage_log_id' => $usageLog->id,
            ]);

            return [
                'success' => true,
                'price_charged' => $pricePerMessage,
                'saldo_before' => $saldoBefore,
                'saldo_after' => $saldoAfter,
                'usage_log_id' => $usageLog->id,
            ];
        });
    }

    /**
     * Charge for multiple messages (batch) - ATOMIC OPERATION
     * 
     * Untuk campaign yang kirim batch sekaligus
     * 
     * @param int $klienId
     * @param array $messages Array of message data
     * @param string $category
     * @return array
     */
    public function chargeMessages(int $klienId, array $messages, string $category = 'marketing'): array
    {
        $pricePerMessage = WaPricing::getPriceForCategory($category);
        $totalCost = $pricePerMessage * count($messages);

        return DB::transaction(function () use ($klienId, $messages, $category, $pricePerMessage, $totalCost) {
            // Lock wallet
            $dompet = DompetSaldo::where('klien_id', $klienId)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                throw new \DomainException('Wallet tidak ditemukan');
            }

            // Check saldo
            if ($dompet->saldo_tersedia < $totalCost) {
                return [
                    'success' => false,
                    'reason' => 'Saldo tidak mencukupi untuk batch',
                    'code' => WaUsageLog::REJECTION_INSUFFICIENT_BALANCE,
                    'details' => [
                        'required' => $totalCost,
                        'available' => $dompet->saldo_tersedia,
                    ],
                ];
            }

            $saldoBefore = $dompet->saldo_tersedia;

            // Deduct total
            $dompet->saldo_tersedia -= $totalCost;
            $dompet->total_terpakai += $totalCost;
            $dompet->terakhir_transaksi = now();
            $dompet->save();

            $saldoAfter = $dompet->saldo_tersedia;

            // Log each message
            $runningBalance = $saldoBefore;
            foreach ($messages as $msg) {
                $msgSaldoBefore = $runningBalance;
                $runningBalance -= $pricePerMessage;

                WaUsageLog::logSuccess([
                    'klien_id' => $klienId,
                    'pengguna_id' => $msg['pengguna_id'] ?? null,
                    'kampanye_id' => $msg['kampanye_id'] ?? null,
                    'target_kampanye_id' => $msg['target_kampanye_id'] ?? null,
                    'percakapan_inbox_id' => $msg['percakapan_inbox_id'] ?? null,
                    'nomor_tujuan' => $msg['nomor_tujuan'] ?? '',
                    'message_type' => $msg['message_type'] ?? 'text',
                    'message_category' => $category,
                    'price_per_message' => $pricePerMessage,
                    'total_cost' => $pricePerMessage,
                    'saldo_before' => $msgSaldoBefore,
                    'saldo_after' => $runningBalance,
                    'provider_message_id' => $msg['provider_message_id'] ?? null,
                    'provider_status' => $msg['provider_status'] ?? null,
                ]);
            }

            // Invalidate cache
            $this->limitService->invalidateCache($klienId);

            Log::info('SaldoGuard: Batch charged', [
                'klien_id' => $klienId,
                'message_count' => count($messages),
                'total_cost' => $totalCost,
                'saldo_before' => $saldoBefore,
                'saldo_after' => $saldoAfter,
            ]);

            return [
                'success' => true,
                'message_count' => count($messages),
                'price_per_message' => $pricePerMessage,
                'total_charged' => $totalCost,
                'saldo_before' => $saldoBefore,
                'saldo_after' => $saldoAfter,
            ];
        });
    }

    /**
     * Log rejected message (not charged)
     */
    public function logRejection(int $klienId, array $messageData, string $reason, string $category = 'marketing'): void
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();
        $saldo = $dompet?->saldo_tersedia ?? 0;

        WaUsageLog::logRejection([
            'klien_id' => $klienId,
            'pengguna_id' => $messageData['pengguna_id'] ?? null,
            'kampanye_id' => $messageData['kampanye_id'] ?? null,
            'target_kampanye_id' => $messageData['target_kampanye_id'] ?? null,
            'percakapan_inbox_id' => $messageData['percakapan_inbox_id'] ?? null,
            'nomor_tujuan' => $messageData['nomor_tujuan'] ?? '',
            'message_type' => $messageData['message_type'] ?? 'text',
            'message_category' => $category,
            'price_per_message' => WaPricing::getPriceForCategory($category),
            'saldo_before' => $saldo,
            'saldo_after' => $saldo,
        ], $reason);
    }

    /**
     * Log failed message (provider error, not charged)
     */
    public function logFailure(int $klienId, array $messageData, string $category = 'marketing'): void
    {
        $dompet = DompetSaldo::where('klien_id', $klienId)->first();
        $saldo = $dompet?->saldo_tersedia ?? 0;

        WaUsageLog::logFailure([
            'klien_id' => $klienId,
            'pengguna_id' => $messageData['pengguna_id'] ?? null,
            'kampanye_id' => $messageData['kampanye_id'] ?? null,
            'target_kampanye_id' => $messageData['target_kampanye_id'] ?? null,
            'percakapan_inbox_id' => $messageData['percakapan_inbox_id'] ?? null,
            'nomor_tujuan' => $messageData['nomor_tujuan'] ?? '',
            'message_type' => $messageData['message_type'] ?? 'text',
            'message_category' => $category,
            'price_per_message' => WaPricing::getPriceForCategory($category),
            'saldo_before' => $saldo,
            'saldo_after' => $saldo,
            'provider_message_id' => $messageData['provider_message_id'] ?? null,
            'provider_status' => $messageData['provider_status'] ?? 'failed',
        ]);
    }
}
