<?php

namespace Database\Factories;

use App\Models\PesanInbox;
use App\Models\PercakapanInbox;
use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PesanInboxFactory extends Factory
{
    protected $model = PesanInbox::class;

    public function definition(): array
    {
        return [
            'percakapan_id' => PercakapanInbox::factory(),
            'klien_id' => Klien::factory(),
            'pengguna_id' => null,
            'pesan_id' => null,
            'wa_message_id' => 'wamid.' . Str::random(24),
            'arah' => 'masuk',
            'no_pengirim' => '628' . $this->faker->numerify('##########'),
            'tipe' => 'teks',
            'isi_pesan' => $this->faker->paragraph(),
            'media_url' => null,
            'media_mime_type' => null,
            'nama_file' => null,
            'ukuran_file' => null,
            'caption' => null,
            'reply_to' => null,
            'status' => 'delivered',
            'dibaca_sales' => false,
            'waktu_dibaca_sales' => null,
            'waktu_pesan' => now(),
        ];
    }

    /**
     * Pesan masuk dari customer
     */
    public function masuk(): static
    {
        return $this->state(fn (array $attributes) => [
            'arah' => 'masuk',
            'pengguna_id' => null,
        ]);
    }

    /**
     * Pesan keluar dari sales
     */
    public function keluar(int $penggunaId): static
    {
        return $this->state(fn (array $attributes) => [
            'arah' => 'keluar',
            'pengguna_id' => $penggunaId,
            'dibaca_sales' => true,
        ]);
    }

    /**
     * Pesan sudah dibaca
     */
    public function sudahDibaca(): static
    {
        return $this->state(fn (array $attributes) => [
            'dibaca_sales' => true,
            'waktu_dibaca_sales' => now(),
        ]);
    }

    /**
     * Pesan gambar
     */
    public function gambar(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipe' => 'gambar',
            'isi_pesan' => null,
            'media_url' => $this->faker->imageUrl(),
            'media_mime_type' => 'image/jpeg',
            'caption' => $this->faker->sentence(),
        ]);
    }

    /**
     * Pesan dokumen
     */
    public function dokumen(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipe' => 'dokumen',
            'isi_pesan' => null,
            'media_url' => $this->faker->url(),
            'media_mime_type' => 'application/pdf',
            'nama_file' => 'dokumen.pdf',
            'ukuran_file' => $this->faker->numberBetween(1000, 100000),
        ]);
    }
}
