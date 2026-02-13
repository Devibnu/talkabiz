<?php

namespace Database\Factories;

use App\Models\DompetSaldo;
use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DompetSaldo>
 */
class DompetSaldoFactory extends Factory
{
    protected $model = DompetSaldo::class;

    public function definition(): array
    {
        $totalTopup = fake()->numberBetween(1000000, 10000000);
        $terpakai = fake()->numberBetween(0, $totalTopup);
        $saldoTersedia = $totalTopup - $terpakai;

        return [
            'klien_id' => Klien::factory(),
            'saldo_tersedia' => $saldoTersedia,
            'saldo_tertahan' => 0,
            'batas_warning' => 500000,
            'batas_minimum' => 50000,
            'total_topup' => $totalTopup,
            'total_terpakai' => $terpakai,
            'terakhir_topup' => fake()->optional()->dateTimeBetween('-30 days', 'now'),
            'terakhir_transaksi' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Saldo tinggi
     */
    public function kaya(): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_tersedia' => 10000000,
            'total_topup' => 15000000,
            'total_terpakai' => 5000000,
        ]);
    }

    /**
     * Saldo rendah (warning level)
     */
    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_tersedia' => 400000, // dibawah warning 500000
            'batas_warning' => 500000,
        ]);
    }

    /**
     * Saldo kritis (dibawah minimum)
     */
    public function kritis(): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_tersedia' => 30000, // dibawah minimum 50000
            'batas_minimum' => 50000,
        ]);
    }

    /**
     * Saldo kosong
     */
    public function kosong(): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_tersedia' => 0,
            'saldo_tertahan' => 0,
        ]);
    }

    /**
     * Dengan saldo tertahan
     */
    public function denganHold(int $nominal): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_tertahan' => $nominal,
        ]);
    }
}
