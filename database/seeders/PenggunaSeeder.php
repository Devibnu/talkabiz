<?php

namespace Database\Seeders;

use App\Models\Pengguna;
use App\Models\Klien;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PenggunaSeeder extends Seeder
{
    public function run(): void
    {
        // Super Admin (tidak terikat klien)
        Pengguna::create([
            'klien_id' => null,
            'nama_lengkap' => 'Super Admin',
            'email' => 'superadmin@talka.biz',
            'password' => Hash::make('password'),
            'no_telepon' => '6281000000001',
            'role' => 'super_admin',
            'aktif' => true,
            'email_verified_at' => now(),
        ]);

        // Ambil semua klien dan buat user untuk masing-masing
        $klienList = Klien::all();

        foreach ($klienList as $klien) {
            $slug = str_replace('-', '', $klien->slug);
            
            // Owner
            Pengguna::create([
                'klien_id' => $klien->id,
                'nama_lengkap' => 'Owner ' . $klien->nama_perusahaan,
                'email' => "owner@{$slug}.com",
                'password' => Hash::make('password'),
                'no_telepon' => '628' . rand(1000000000, 9999999999),
                'role' => 'owner',
                'aktif' => true,
                'email_verified_at' => now(),
            ]);

            // Admin
            Pengguna::create([
                'klien_id' => $klien->id,
                'nama_lengkap' => 'Admin ' . $klien->nama_perusahaan,
                'email' => "admin@{$slug}.com",
                'password' => Hash::make('password'),
                'no_telepon' => '628' . rand(1000000000, 9999999999),
                'role' => 'admin',
                'aktif' => true,
                'email_verified_at' => now(),
            ]);

            // Sales 1
            Pengguna::create([
                'klien_id' => $klien->id,
                'nama_lengkap' => 'Sales 1 ' . $klien->nama_perusahaan,
                'email' => "sales1@{$slug}.com",
                'password' => Hash::make('password'),
                'no_telepon' => '628' . rand(1000000000, 9999999999),
                'role' => 'sales',
                'aktif' => true,
                'email_verified_at' => now(),
            ]);

            // Sales 2
            Pengguna::create([
                'klien_id' => $klien->id,
                'nama_lengkap' => 'Sales 2 ' . $klien->nama_perusahaan,
                'email' => "sales2@{$slug}.com",
                'password' => Hash::make('password'),
                'no_telepon' => '628' . rand(1000000000, 9999999999),
                'role' => 'sales',
                'aktif' => true,
                'email_verified_at' => now(),
            ]);
        }
    }
}
