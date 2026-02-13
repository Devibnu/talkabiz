<?php

namespace Database\Factories;

use App\Models\Pengguna;
use App\Models\Klien;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PenggunaFactory extends Factory
{
    protected $model = Pengguna::class;

    public function definition(): array
    {
        return [
            'klien_id' => Klien::factory(),
            'nama_lengkap' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'no_telepon' => $this->faker->phoneNumber(),
            'foto_profil' => null,
            'role' => 'sales',
            'aktif' => true,
            'email_verified_at' => now(),
            'terakhir_login' => now(),
            'preferensi' => [],
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Owner role
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'owner',
        ]);
    }

    /**
     * Admin role
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Sales role
     */
    public function sales(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'sales',
        ]);
    }

    /**
     * Super admin (tanpa klien)
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'klien_id' => null,
            'role' => 'super_admin',
        ]);
    }

    /**
     * Pengguna tidak aktif
     */
    public function tidakAktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif' => false,
        ]);
    }
}
