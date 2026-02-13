<?php

namespace Database\Factories;

use App\Models\TargetKampanye;
use App\Models\Kampanye;
use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TargetKampanye>
 */
class TargetKampanyeFactory extends Factory
{
    protected $model = TargetKampanye::class;

    public function definition(): array
    {
        return [
            'kampanye_id' => Kampanye::factory(),
            'klien_id' => Klien::factory(),
            'no_whatsapp' => '628' . fake()->numerify('##########'),
            'nama' => fake()->name(),
            'data_variabel' => [
                '1' => fake()->name(),
                '2' => 'Rp ' . number_format(fake()->numberBetween(100000, 1000000), 0, ',', '.'),
            ],
            'payload_kirim' => null,
            'status' => 'pending',
            'message_id' => null,
            'waktu_kirim' => null,
            'waktu_delivered' => null,
            'waktu_dibaca' => null,
            'catatan' => null,
            'urutan' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * State: Pending (menunggu dikirim)
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'message_id' => null,
            'waktu_kirim' => null,
        ]);
    }

    /**
     * State: Dalam antrian
     */
    public function antrian(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'antrian',
        ]);
    }

    /**
     * State: Terkirim
     */
    public function terkirim(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terkirim',
            'message_id' => 'msg_' . fake()->uuid(),
            'waktu_kirim' => now(),
        ]);
    }

    /**
     * State: Delivered (sampai)
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'message_id' => 'msg_' . fake()->uuid(),
            'waktu_kirim' => now()->subMinutes(5),
            'waktu_delivered' => now(),
        ]);
    }

    /**
     * State: Dibaca
     */
    public function dibaca(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dibaca',
            'message_id' => 'msg_' . fake()->uuid(),
            'waktu_kirim' => now()->subMinutes(10),
            'waktu_delivered' => now()->subMinutes(5),
            'waktu_dibaca' => now(),
        ]);
    }

    /**
     * State: Gagal
     */
    public function gagal(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'gagal',
            'message_id' => null,
            'waktu_kirim' => now(),
            'catatan' => fake()->randomElement([
                'Nomor tidak terdaftar di WhatsApp',
                'Rate limit exceeded',
                'Connection timeout',
            ]),
        ]);
    }

    /**
     * State: Nomor invalid
     */
    public function invalid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invalid',
            'catatan' => 'Nomor tidak valid',
        ]);
    }

    /**
     * State: Dengan variabel lengkap (untuk template dengan 2 variabel)
     */
    public function denganVariabelLengkap(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_variabel' => [
                '1' => fake()->name(),
                '2' => 'Rp ' . number_format(fake()->numberBetween(100000, 1000000), 0, ',', '.'),
            ],
        ]);
    }

    /**
     * State: Tanpa variabel (untuk test validasi gagal)
     */
    public function tanpaVariabel(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_variabel' => [],
        ]);
    }

    /**
     * State: Variabel tidak lengkap
     */
    public function variabelTidakLengkap(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_variabel' => [
                '1' => fake()->name(),
                // Missing '2'
            ],
        ]);
    }
}
