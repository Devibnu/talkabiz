<?php

namespace Database\Factories;

use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class KlienFactory extends Factory
{
    protected $model = Klien::class;

    public function definition(): array
    {
        $namaPerusahaan = $this->faker->company();
        
        return [
            'nama_perusahaan' => $namaPerusahaan,
            'slug' => Str::slug($namaPerusahaan) . '-' . Str::random(4),
            'tipe_bisnis' => $this->faker->randomElement(['perorangan', 'cv', 'pt', 'ud', 'lainnya']),
            'alamat' => $this->faker->address(),
            'kota' => $this->faker->city(),
            'provinsi' => $this->faker->state(),
            'kode_pos' => $this->faker->postcode(),
            'email' => $this->faker->unique()->companyEmail(),
            'no_telepon' => $this->faker->phoneNumber(),
            'no_whatsapp' => '628' . $this->faker->numerify('##########'),
            'wa_phone_number_id' => $this->faker->numerify('##############'),
            'wa_business_account_id' => $this->faker->numerify('##############'),
            'wa_access_token' => Str::random(64),
            'wa_terhubung' => true,
            'wa_terakhir_sync' => now(),
            'status' => 'aktif',
            'tipe_paket' => 'umkm',
            'tanggal_bergabung' => now(),
            'tanggal_berakhir' => now()->addYear(),
            'pengaturan' => [],
        ];
    }

    /**
     * Klien tidak aktif
     */
    public function tidakAktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'nonaktif',
        ]);
    }

    /**
     * WhatsApp tidak terhubung
     */
    public function waTidakTerhubung(): static
    {
        return $this->state(fn (array $attributes) => [
            'wa_terhubung' => false,
        ]);
    }
}
