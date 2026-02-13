<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TemplatePesan;
use App\Models\Klien;

/**
 * TemplateSeeder
 * 
 * Seeder untuk mengisi data template pesan WhatsApp.
 * Membuat 3 template per klien:
 * - 1 marketing template (approved)
 * - 1 utility template (approved)
 * - 1 rejected template
 * 
 * @author TalkaBiz Team
 */
class TemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ambil semua klien
        $kliens = Klien::all();

        if ($kliens->isEmpty()) {
            $this->command->info('Tidak ada klien, skip TemplateSeeder');
            return;
        }

        foreach ($kliens as $klien) {
            $this->seedTemplatesForKlien($klien);
        }

        $total = TemplatePesan::count();
        $this->command->info("TemplateSeeder: {$total} template pesan dibuat");
    }

    /**
     * Seed templates untuk 1 klien
     */
    protected function seedTemplatesForKlien(Klien $klien): void
    {
        // =====================================================
        // TEMPLATE 1: Marketing - Promo Bulanan (Approved)
        // =====================================================
        TemplatePesan::create([
            'klien_id' => $klien->id,
            'nama_template' => 'promo_bulanan',
            'nama_tampilan' => 'Promo Bulanan',
            'kategori' => TemplatePesan::KATEGORI_MARKETING,
            'bahasa' => 'id',
            'header' => '🎉 PROMO SPESIAL UNTUK ANDA!',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'body' => "Halo {{1}}! 👋\n\nKami punya promo spesial bulan ini:\n\n📦 *{{2}}*\n💰 Diskon hingga *{{3}}*\n⏰ Berlaku sampai {{4}}\n\nJangan sampai kelewatan ya! Klik tombol di bawah untuk info lengkap.",
            'footer' => 'TalkaBiz - Solusi Bisnis Digital',
            'buttons' => [
                [
                    'type' => 'url',
                    'text' => 'Lihat Promo',
                    'url' => 'https://example.com/promo',
                ],
                [
                    'type' => 'quick_reply',
                    'text' => 'Hubungi CS',
                ],
            ],
            'contoh_variabel' => [
                '1' => 'Budi Santoso',
                '2' => 'Paket Premium',
                '3' => '50%',
                '4' => '31 Januari 2024',
            ],
            'status' => TemplatePesan::STATUS_APPROVED,
            'provider_template_id' => 'promo_bulanan_' . $klien->id,
            'submitted_at' => now()->subDays(7),
            'approved_at' => now()->subDays(5),
            'total_terkirim' => rand(100, 500),
            'total_dibaca' => rand(50, 200),
            'aktif' => true,
            'dibuat_oleh' => 1,
        ]);

        // =====================================================
        // TEMPLATE 2: Utility - Konfirmasi Pesanan (Approved)
        // =====================================================
        TemplatePesan::create([
            'klien_id' => $klien->id,
            'nama_template' => 'konfirmasi_pesanan',
            'nama_tampilan' => 'Konfirmasi Pesanan',
            'kategori' => TemplatePesan::KATEGORI_UTILITY,
            'bahasa' => 'id',
            'header' => '✅ Pesanan Dikonfirmasi',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'body' => "Halo {{1}},\n\nPesanan Anda telah kami terima:\n\n🛒 *No. Pesanan:* {{2}}\n📦 *Produk:* {{3}}\n💵 *Total:* Rp {{4}}\n📍 *Alamat:* {{5}}\n\nPesanan sedang diproses dan akan dikirim dalam 1-2 hari kerja.\n\nTerima kasih telah berbelanja!",
            'footer' => 'Hubungi kami jika ada pertanyaan',
            'buttons' => [
                [
                    'type' => 'url',
                    'text' => 'Lacak Pesanan',
                    'url' => 'https://example.com/track',
                ],
                [
                    'type' => 'phone',
                    'text' => 'Hubungi CS',
                    'phone' => '+6281234567890',
                ],
            ],
            'contoh_variabel' => [
                '1' => 'Siti Rahayu',
                '2' => 'INV-2024-001234',
                '3' => 'Laptop Asus ROG',
                '4' => '15.000.000',
                '5' => 'Jl. Sudirman No. 123, Jakarta',
            ],
            'status' => TemplatePesan::STATUS_APPROVED,
            'provider_template_id' => 'konfirmasi_pesanan_' . $klien->id,
            'submitted_at' => now()->subDays(10),
            'approved_at' => now()->subDays(8),
            'total_terkirim' => rand(200, 800),
            'total_dibaca' => rand(150, 600),
            'aktif' => true,
            'dibuat_oleh' => 1,
        ]);

        // =====================================================
        // TEMPLATE 3: Authentication - OTP (Approved)
        // =====================================================
        TemplatePesan::create([
            'klien_id' => $klien->id,
            'nama_template' => 'otp_verifikasi',
            'nama_tampilan' => 'OTP Verifikasi',
            'kategori' => TemplatePesan::KATEGORI_AUTHENTICATION,
            'bahasa' => 'id',
            'header' => '🔐 Kode Verifikasi',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'body' => "Kode OTP Anda adalah:\n\n*{{1}}*\n\nKode ini berlaku selama 5 menit.\n\n⚠️ Jangan berikan kode ini kepada siapapun, termasuk pihak yang mengaku dari kami.",
            'footer' => 'Sistem otomatis - jangan balas pesan ini',
            'buttons' => [
                [
                    'type' => 'copy_code',
                    'text' => 'Salin Kode',
                    'copy_code' => '{{1}}',
                ],
            ],
            'contoh_variabel' => [
                '1' => '123456',
            ],
            'status' => TemplatePesan::STATUS_APPROVED,
            'provider_template_id' => 'otp_verifikasi_' . $klien->id,
            'submitted_at' => now()->subDays(14),
            'approved_at' => now()->subDays(12),
            'total_terkirim' => rand(500, 2000),
            'total_dibaca' => rand(400, 1500),
            'aktif' => true,
            'dibuat_oleh' => 1,
        ]);

        // =====================================================
        // TEMPLATE 4: Marketing (REJECTED)
        // =====================================================
        TemplatePesan::create([
            'klien_id' => $klien->id,
            'nama_template' => 'flash_sale_gagal',
            'nama_tampilan' => 'Flash Sale',
            'kategori' => TemplatePesan::KATEGORI_MARKETING,
            'bahasa' => 'id',
            'header' => '🔥 FLASH SALE!!!',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'body' => "BURUAN BELI SEKARANG!!!\n\nDISKON GILA-GILAAN {{1}}!!!\n\nSTOK TERBATAS HANYA {{2}} UNIT!!!\n\nKLIK SEKARANG JUGA!!!",
            'footer' => null,
            'buttons' => [
                [
                    'type' => 'url',
                    'text' => 'BELI SEKARANG!!!',
                    'url' => 'https://example.com/flash-sale',
                ],
            ],
            'contoh_variabel' => [
                '1' => '90%',
                '2' => '100',
            ],
            'status' => TemplatePesan::STATUS_REJECTED,
            'provider_template_id' => null,
            'submitted_at' => now()->subDays(3),
            'approved_at' => null,
            'catatan_reject' => 'Template ditolak karena: 1) Menggunakan terlalu banyak huruf kapital dan tanda seru. 2) Konten bersifat spam dan terlalu agresif. 3) Tidak memenuhi kebijakan Meta untuk template marketing. Silakan perbaiki dan submit ulang.',
            'total_terkirim' => 0,
            'total_dibaca' => 0,
            'aktif' => true,
            'dibuat_oleh' => 1,
        ]);

        // =====================================================
        // TEMPLATE 5: Draft (belum submit)
        // =====================================================
        TemplatePesan::create([
            'klien_id' => $klien->id,
            'nama_template' => 'reminder_pembayaran',
            'nama_tampilan' => 'Reminder Pembayaran',
            'kategori' => TemplatePesan::KATEGORI_UTILITY,
            'bahasa' => 'id',
            'header' => '⏰ Pengingat Pembayaran',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'body' => "Halo {{1}},\n\nIni adalah pengingat untuk invoice {{2}}:\n\n💰 *Jumlah:* Rp {{3}}\n📅 *Jatuh Tempo:* {{4}}\n\nSilakan lakukan pembayaran sebelum tanggal jatuh tempo untuk menghindari denda.\n\nTerima kasih!",
            'footer' => 'Abaikan jika sudah bayar',
            'buttons' => [
                [
                    'type' => 'url',
                    'text' => 'Bayar Sekarang',
                    'url' => 'https://example.com/payment',
                ],
            ],
            'contoh_variabel' => [
                '1' => 'Ahmad Wijaya',
                '2' => 'INV-2024-000789',
                '3' => '2.500.000',
                '4' => '15 Februari 2024',
            ],
            'status' => TemplatePesan::STATUS_DRAFT,
            'provider_template_id' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'total_terkirim' => 0,
            'total_dibaca' => 0,
            'aktif' => true,
            'dibuat_oleh' => 1,
        ]);
    }
}
