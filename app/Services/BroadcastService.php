<?php

namespace App\Services;

use App\Models\MessageRate;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

/**
 * BroadcastService - Proses Kirim Broadcast WhatsApp
 *
 * ATURAN:
 * 1. Hitung estimasi biaya dari DB (MessageRate), BUKAN hardcode
 * 2. Panggil SaldoGuard::check() sebelum kirim
 * 3. Jika saldo kurang → broadcast GAGAL TOTAL, 0 pesan terkirim
 * 4. Jika saldo cukup  → lanjut kirim, pemotongan via WalletService
 */
class BroadcastService
{
    protected SaldoGuard $saldoGuard;
    protected WalletService $walletService;

    public function __construct(SaldoGuard $saldoGuard, WalletService $walletService)
    {
        $this->saldoGuard = $saldoGuard;
        $this->walletService = $walletService;
    }

    /**
     * Hitung estimasi biaya broadcast.
     * Tarif diambil dari tabel message_rates (database-driven).
     *
     * @param int    $recipientCount Jumlah penerima
     * @param string $category       Kategori pesan (marketing, utility, dll)
     * @return array{rate: float, total: float, count: int, category: string}
     */
    public function estimateCost(int $recipientCount, string $category = 'marketing'): array
    {
        $rate = MessageRate::getRateFor(MessageRate::TYPE_CAMPAIGN, $category);

        return [
            'rate'     => $rate,
            'total'    => $rate * $recipientCount,
            'count'    => $recipientCount,
            'category' => $category,
        ];
    }

    /**
     * Kirim broadcast.
     *
     * Flow:
     *   1. Hitung estimasi biaya dari DB
     *   2. SaldoGuard::check() → throw jika kurang
     *   3. Potong saldo via WalletService
     *   4. Dispatch pengiriman pesan (placeholder)
     *
     * @param int    $userId         ID user pengirim
     * @param array  $recipients     Daftar nomor tujuan
     * @param string $message        Isi pesan / template
     * @param string $category       Kategori pesan
     * @return array
     *
     * @throws \RuntimeException jika saldo tidak cukup
     */
    public function send(int $userId, array $recipients, string $message, string $category = 'marketing'): array
    {
        $recipientCount = count($recipients);

        if ($recipientCount === 0) {
            throw new \InvalidArgumentException('Daftar penerima tidak boleh kosong.');
        }

        // 1. Hitung estimasi biaya dari DB (database-driven, no hardcode)
        $estimate = $this->estimateCost($recipientCount, $category);

        // 2. SaldoGuard: BLOKIR jika saldo kurang (throw RuntimeException)
        $this->saldoGuard->check($userId, (int) $estimate['total']);

        // 3. Potong saldo + catat ledger (ATOMIC)
        $broadcastId = time(); // placeholder — ganti dengan ID broadcast asli
        $transaction = $this->walletService->deduct(
            $userId,
            (int) $estimate['total'],
            'broadcast',
            $broadcastId
        );

        Log::info('BroadcastService: saldo dipotong, broadcast dimulai', [
            'user_id'         => $userId,
            'recipient_count' => $recipientCount,
            'total_cost'      => $estimate['total'],
            'rate'            => $estimate['rate'],
            'category'        => $category,
            'transaction_id'  => $transaction->id,
        ]);

        // 4. TODO: dispatch job kirim pesan ke masing-masing recipient
        //    Ini akan dilakukan oleh job/queue terpisah.

        return [
            'success'         => true,
            'recipient_count' => $recipientCount,
            'total_cost'      => $estimate['total'],
            'rate_per_message' => $estimate['rate'],
            'transaction_id'  => $transaction->id,
            'balance_after'   => $transaction->balance_after,
        ];
    }
}
