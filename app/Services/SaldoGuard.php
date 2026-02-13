<?php

namespace App\Services;

use App\Models\Wallet;

class SaldoGuard
{
    /**
     * Cek apakah saldo user cukup untuk estimasi biaya.
     * Jika tidak cukup → throw exception, pengiriman diblokir.
     * Jika cukup → tidak terjadi apa-apa, lanjut.
     *
     * @param int $userId
     * @param int $estimatedCost Estimasi biaya dalam Rupiah
     * @return void
     *
     * @throws \RuntimeException
     */
    public function check(int $userId, int $estimatedCost): void
    {
        $wallet = Wallet::where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if (!$wallet) {
            throw new \RuntimeException(
                'Wallet belum tersedia. Silakan topup saldo terlebih dahulu.'
            );
        }

        if ($wallet->balance < $estimatedCost) {
            $kurang = $estimatedCost - $wallet->balance;

            throw new \RuntimeException(
                "Saldo tidak cukup. Saldo: Rp " . number_format($wallet->balance, 0, ',', '.') .
                ", Dibutuhkan: Rp " . number_format($estimatedCost, 0, ',', '.') .
                ". Silakan topup minimal Rp " . number_format($kurang, 0, ',', '.') . "."
            );
        }
    }
}
