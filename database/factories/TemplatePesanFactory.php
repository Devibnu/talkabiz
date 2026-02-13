<?php

namespace Database\Factories;

use App\Models\TemplatePesan;
use App\Models\Klien;
use App\Models\Pengguna;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TemplatePesan>
 */
class TemplatePesanFactory extends Factory
{
    protected $model = TemplatePesan::class;

    public function definition(): array
    {
        $kategori = fake()->randomElement(['marketing', 'utility', 'authentication']);
        $namaTemplate = fake()->unique()->words(3, true);
        $namaTemplate = strtolower(str_replace(' ', '_', $namaTemplate));

        return [
            'klien_id' => Klien::factory(),
            'dibuat_oleh' => Pengguna::factory(),
            'nama_template' => $namaTemplate,
            'nama_tampilan' => ucwords(str_replace('_', ' ', $namaTemplate)),
            'kategori' => $kategori,
            'bahasa' => 'id',
            'header' => null,
            'header_type' => 'none',
            'header_media_url' => null,
            'body' => 'Halo {{1}}, terima kasih telah berbelanja di toko kami. Total pesanan Anda: {{2}}',
            'footer' => 'Talkabiz - WhatsApp Business',
            'buttons' => null,
            'contoh_variabel' => ['1' => 'Budi', '2' => 'Rp 500.000'],
            'status' => 'draft',
            'provider_template_id' => null,
            'catatan_reject' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'total_terkirim' => 0,
            'total_dibaca' => 0,
            'aktif' => true,
        ];
    }

    /**
     * State: Draft
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'provider_template_id' => null,
            'dipakai_count' => 0,
        ]);
    }

    /**
     * State: Diajukan (pending review)
     */
    public function diajukan(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'diajukan',
            'provider_template_id' => 'gupshup_' . fake()->uuid(),
            'submitted_at' => now()->subHours(rand(1, 48)),
        ]);
    }

    /**
     * State: Pending review (alias for diajukan)
     */
    public function pending(): static
    {
        return $this->diajukan();
    }

    /**
     * State: Disetujui (approved)
     */
    public function disetujui(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disetujui',
            'provider_template_id' => 'gupshup_' . fake()->uuid(),
            'submitted_at' => now()->subDays(rand(3, 7)),
            'approved_at' => now()->subDays(rand(1, 2)),
            'total_terkirim' => fake()->numberBetween(100, 10000),
            'total_dibaca' => fake()->numberBetween(50, 5000),
        ]);
    }

    /**
     * State: Approved (alias for disetujui)
     */
    public function approved(): static
    {
        return $this->disetujui();
    }

    /**
     * State: Ditolak (rejected)
     */
    public function ditolak(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ditolak',
            'provider_template_id' => 'gupshup_' . fake()->uuid(),
            'submitted_at' => now()->subDays(rand(1, 3)),
            'alasan_penolakan' => fake()->randomElement([
                'Template contains prohibited content',
                'Variable format is incorrect',
                'Template name already exists',
                'Body text exceeds maximum length',
                'Missing required component',
            ]),
            'catatan_reject' => fake()->randomElement([
                'Template contains prohibited content',
                'Variable format is incorrect',
            ]),
        ]);
    }

    /**
     * State: Rejected (alias for ditolak)
     */
    public function rejected(): static
    {
        return $this->ditolak();
    }

    /**
     * State: Arsip (disabled)
     */
    public function arsip(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'arsip',
            'aktif' => false,
        ]);
    }

    /**
     * State: Marketing category
     */
    public function marketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'kategori' => 'marketing',
            'body' => 'Hai {{1}}! 🎉 Promo spesial untuk Anda: Diskon {{2}} untuk semua produk. Berlaku sampai {{3}}. Jangan lewatkan!',
            'contoh_variabel' => ['1' => 'Budi', '2' => '50%', '3' => '31 Januari 2026'],
            'buttons' => [
                ['type' => 'quick_reply', 'text' => 'Lihat Promo'],
                ['type' => 'quick_reply', 'text' => 'Tidak Tertarik'],
            ],
        ]);
    }

    /**
     * State: Utility category
     */
    public function utility(): static
    {
        return $this->state(fn (array $attributes) => [
            'kategori' => 'utility',
            'body' => 'Pesanan {{1}} telah dikonfirmasi. Total: {{2}}. Estimasi pengiriman: {{3}}. Terima kasih!',
            'contoh_variabel' => ['1' => 'ORD-2026-001', '2' => 'Rp 350.000', '3' => '2-3 hari kerja'],
        ]);
    }

    /**
     * State: Authentication (OTP)
     */
    public function authentication(): static
    {
        return $this->state(fn (array $attributes) => [
            'kategori' => 'authentication',
            'body' => 'Kode OTP Anda: {{1}}. Berlaku 5 menit. Jangan bagikan kode ini kepada siapapun.',
            'contoh_variabel' => ['1' => '123456'],
            'footer' => null,
            'buttons' => [
                ['type' => 'copy_code', 'text' => 'Salin Kode'],
            ],
        ]);
    }

    /**
     * State: Dengan header gambar
     */
    public function denganHeaderGambar(): static
    {
        return $this->state(fn (array $attributes) => [
            'header_type' => 'image',
            'header_media_url' => 'https://example.com/promo-banner.jpg',
        ]);
    }

    /**
     * State: Tidak aktif
     */
    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif' => false,
        ]);
    }
}
