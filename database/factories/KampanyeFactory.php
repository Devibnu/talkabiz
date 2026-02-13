<?php

namespace Database\Factories;

use App\Models\Kampanye;
use App\Models\Klien;
use App\Models\Pengguna;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Kampanye>
 */
class KampanyeFactory extends Factory
{
    protected $model = Kampanye::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'kode_kampanye' => 'CMP-' . date('Ymd') . '-' . strtoupper(fake()->unique()->bothify('#####')),
            'klien_id' => Klien::factory(),
            'dibuat_oleh' => Pengguna::factory(),
            'nama_kampanye' => fake()->sentence(3),
            'deskripsi' => fake()->optional()->paragraph(),
            'tipe_pesan' => fake()->randomElement(['teks', 'gambar', 'dokumen', 'template']),
            'template_pesan' => fake()->paragraph(), // renamed from isi_pesan
            'media_url' => null,
            'template_id' => null,
            'variabel_pesan' => null,
            'catatan' => null, // untuk jeda/berhenti
            'total_target' => fake()->numberBetween(10, 1000),
            'sumber_target' => fake()->randomElement(['manual', 'import_csv', 'grup_kontak', 'filter']),
            'tipe_jadwal' => 'langsung',
            'jadwal_kirim' => null,
            'waktu_mulai' => null,
            'waktu_selesai' => null,
            'status' => 'draft',
            'terkirim' => 0,
            'gagal' => 0,
            'pending' => 0,
            'dibaca' => 0,
            'harga_per_pesan' => 50,
            'estimasi_biaya' => 0,
            'saldo_dihold' => 0,
            'biaya_aktual' => 0,
        ];
    }

    /**
     * State: Draft
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * State: Siap dijalankan
     */
    public function siap(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'siap',
        ]);
    }

    /**
     * State: Sedang berjalan
     */
    public function berjalan(): static
    {
        return $this->state(function (array $attributes) {
            $totalTarget = $attributes['total_target'] ?? 100;
            $terkirim = (int) ($totalTarget * 0.3);
            
            return [
                'status' => 'berjalan',
                'waktu_mulai' => now(),
                'terkirim' => $terkirim,
                'pending' => $totalTarget - $terkirim,
                'estimasi_biaya' => $totalTarget * ($attributes['harga_per_pesan'] ?? 50),
                'saldo_dihold' => $totalTarget * ($attributes['harga_per_pesan'] ?? 50),
                'biaya_aktual' => $terkirim * ($attributes['harga_per_pesan'] ?? 50),
            ];
        });
    }

    /**
     * State: Jeda (di-pause)
     */
    public function jeda(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'jeda',
                'waktu_mulai' => now()->subMinutes(30),
                'catatan' => 'Di-jeda oleh pengguna',
            ];
        });
    }

    /**
     * State: Selesai
     */
    public function selesai(): static
    {
        return $this->state(function (array $attributes) {
            $totalTarget = $attributes['total_target'] ?? 100;
            $gagal = (int) ($totalTarget * 0.05);
            $terkirim = $totalTarget - $gagal;
            $hargaPerPesan = $attributes['harga_per_pesan'] ?? 50;
            
            return [
                'status' => 'selesai',
                'waktu_mulai' => now()->subMinutes(30),
                'waktu_selesai' => now(),
                'terkirim' => $terkirim,
                'gagal' => $gagal,
                'pending' => 0,
                'dibaca' => (int) ($terkirim * 0.7),
                'estimasi_biaya' => $totalTarget * $hargaPerPesan,
                'saldo_dihold' => 0,
                'biaya_aktual' => $terkirim * $hargaPerPesan,
            ];
        });
    }

    /**
     * State: Dibatalkan
     */
    public function dibatalkan(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dibatalkan',
            'catatan' => 'Dibatalkan oleh pengguna',
        ]);
    }

    /**
     * State: Dengan saldo dihold
     */
    public function denganSaldoHold(int $nominal): static
    {
        return $this->state(fn (array $attributes) => [
            'saldo_dihold' => $nominal,
        ]);
    }
}
