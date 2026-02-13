<?php

namespace Database\Seeders;

use App\Models\Klien;
use App\Models\DompetSaldo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class KlienSeeder extends Seeder
{
    public function run(): void
    {
        $klienData = [
            [
                'nama_perusahaan' => 'Toko Sejahtera',
                'slug' => 'toko-sejahtera',
                'tipe_bisnis' => 'cv',
                'email' => 'admin@tokosejahtera.com',
                'no_telepon' => '021-12345678',
                'no_whatsapp' => '6281234567890',
                'status' => 'aktif',
                'tipe_paket' => 'umkm',
                'alamat' => 'Jl. Pasar Baru No. 123',
                'kota' => 'Jakarta Pusat',
                'provinsi' => 'DKI Jakarta',
            ],
            [
                'nama_perusahaan' => 'PT Maju Bersama',
                'slug' => 'pt-maju-bersama',
                'tipe_bisnis' => 'pt',
                'email' => 'admin@majubersama.co.id',
                'no_telepon' => '021-87654321',
                'no_whatsapp' => '6281987654321',
                'status' => 'aktif',
                'tipe_paket' => 'enterprise',
                'alamat' => 'Gedung Menara Jaya Lt. 15',
                'kota' => 'Jakarta Selatan',
                'provinsi' => 'DKI Jakarta',
            ],
            [
                'nama_perusahaan' => 'UD Berkah Jaya',
                'slug' => 'ud-berkah-jaya',
                'tipe_bisnis' => 'ud',
                'email' => 'berkah@gmail.com',
                'no_telepon' => '022-11223344',
                'no_whatsapp' => '6282112233445',
                'status' => 'trial',
                'tipe_paket' => 'umkm',
                'alamat' => 'Jl. Cihampelas No. 45',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
            ],
        ];

        foreach ($klienData as $data) {
            $data['tanggal_bergabung'] = now()->subDays(rand(30, 365));
            $data['tanggal_berakhir'] = $data['status'] === 'trial' 
                ? now()->addDays(14) 
                : now()->addYear();

            $klien = Klien::create($data);

            // Buat dompet untuk setiap klien
            DompetSaldo::create([
                'klien_id' => $klien->id,
                'saldo_tersedia' => $data['tipe_paket'] === 'enterprise' ? 5000000 : 500000,
                'saldo_tertahan' => 0,
                'batas_warning' => 500000,
                'batas_minimum' => 50000,
                'total_topup' => $data['tipe_paket'] === 'enterprise' ? 10000000 : 1000000,
                'total_terpakai' => $data['tipe_paket'] === 'enterprise' ? 5000000 : 500000,
                'terakhir_topup' => now()->subDays(rand(1, 30)),
            ]);
        }
    }
}
