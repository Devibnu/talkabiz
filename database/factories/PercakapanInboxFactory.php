<?php

namespace Database\Factories;

use App\Models\PercakapanInbox;
use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;

class PercakapanInboxFactory extends Factory
{
    protected $model = PercakapanInbox::class;

    public function definition(): array
    {
        return [
            'klien_id' => Klien::factory(),
            'no_whatsapp' => '628' . $this->faker->numerify('##########'),
            'nama_customer' => $this->faker->name(),
            'foto_profil' => null,
            'ditangani_oleh' => null,
            'waktu_diambil' => null,
            'terkunci' => false,
            'status' => 'baru',
            'pesan_terakhir' => $this->faker->sentence(),
            'pengirim_terakhir' => 'customer',
            'waktu_pesan_terakhir' => now(),
            'total_pesan' => $this->faker->numberBetween(1, 10),
            'pesan_belum_dibaca' => $this->faker->numberBetween(0, 5),
            'label' => [],
            'prioritas' => 'normal',
            'catatan' => null,
        ];
    }

    /**
     * Status baru
     */
    public function baru(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'baru',
            'ditangani_oleh' => null,
            'terkunci' => false,
            'pesan_belum_dibaca' => 1,
        ]);
    }

    /**
     * Status belum dibaca
     */
    public function belumDibaca(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'belum_dibaca',
            'pesan_belum_dibaca' => $this->faker->numberBetween(1, 5),
        ]);
    }

    /**
     * Status aktif (sudah diambil)
     */
    public function aktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'aktif',
            'terkunci' => true,
            'waktu_diambil' => now(),
        ]);
    }

    /**
     * Status selesai
     */
    public function selesai(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'selesai',
            'terkunci' => false,
            'pesan_belum_dibaca' => 0,
        ]);
    }

    /**
     * Prioritas tinggi
     */
    public function prioritasTinggi(): static
    {
        return $this->state(fn (array $attributes) => [
            'prioritas' => 'tinggi',
        ]);
    }

    /**
     * Sudah ditangani seseorang
     */
    public function ditanganiOleh(int $penggunaId): static
    {
        return $this->state(fn (array $attributes) => [
            'ditangani_oleh' => $penggunaId,
            'waktu_diambil' => now(),
            'terkunci' => true,
            'status' => 'aktif',
        ]);
    }
}
