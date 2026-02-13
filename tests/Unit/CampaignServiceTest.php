<?php

namespace Tests\Unit;

use App\Models\Klien;
use App\Models\Pengguna;
use App\Models\DompetSaldo;
use App\Models\Kampanye;
use App\Models\TargetKampanye;
use App\Services\CampaignService;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Test untuk CampaignService
 * 
 * Menguji logika bisnis CampaignService
 * NOTE: Beberapa test di-skip karena inkonsistensi antara konstanta di 
 *       CampaignService (siap, jeda) dengan migration (menunggu, pause)
 */
class CampaignServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CampaignService $campaignService;
    protected SaldoService $saldoService;
    protected Klien $klien;
    protected Pengguna $admin;
    protected DompetSaldo $dompet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saldoService = new SaldoService();
        $this->campaignService = new CampaignService($this->saldoService);

        $this->klien = Klien::factory()->create();

        $this->admin = Pengguna::factory()->admin()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Buat dompet dengan saldo cukup
        $this->dompet = DompetSaldo::create([
            'klien_id' => $this->klien->id,
            'saldo_tersedia' => 100000,
            'saldo_tertahan' => 0,
            'total_topup' => 100000,
            'total_terpakai' => 0,
            'batas_warning' => 50000,
            'batas_minimum' => 10000,
            'terakhir_topup' => now(),
        ]);
    }

    // ==================== HELPER ====================

    /**
     * Buat kampanye dengan data minimal
     */
    protected function buatKampanye(array $attributes = []): Kampanye
    {
        return Kampanye::create(array_merge([
            'kode_kampanye' => 'CMP-' . date('Ymd') . '-' . rand(10000, 99999),
            'klien_id' => $this->klien->id,
            'dibuat_oleh' => $this->admin->id,
            'nama_kampanye' => 'Test Campaign',
            'template_pesan' => 'Halo {{nama}}, ini pesan test!', // renamed from isi_pesan
            'tipe_pesan' => 'teks',
            'total_target' => 0,
            'status' => 'draft',
            'harga_per_pesan' => 50,
        ], $attributes));
    }

    /**
     * Buat target kampanye
     */
    protected function buatTarget(Kampanye $kampanye, int $jumlah = 10): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            TargetKampanye::create([
                'kampanye_id' => $kampanye->id,
                'klien_id' => $this->klien->id,
                'no_whatsapp' => '62812345' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'nama' => 'Penerima ' . ($i + 1),
                'status' => 'pending',
            ]);
        }

        $kampanye->update(['total_target' => $jumlah]);
    }

    // ==================== TEST HITUNG ESTIMASI BIAYA ====================

    /**
     * @test
     */
    public function hitung_estimasi_biaya_dengan_jumlah_target(): void
    {
        $hasil = $this->campaignService->hitungEstimasiBiaya(
            $this->klien->id,
            null, // tanpa kampanye id
            100,  // jumlah target
            50    // harga per pesan
        );

        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(100, $hasil['jumlah_target']);
        $this->assertEquals(50, $hasil['harga_per_pesan']);
        $this->assertEquals(5000, $hasil['estimasi_biaya']);
        $this->assertTrue($hasil['cukup']); // 100000 > 5000
    }

    /**
     * @test
     */
    public function hitung_estimasi_biaya_saldo_tidak_cukup(): void
    {
        $hasil = $this->campaignService->hitungEstimasiBiaya(
            $this->klien->id,
            null,
            5000, // jumlah target banyak
            50
        );

        $this->assertTrue($hasil['sukses']);
        $this->assertFalse($hasil['cukup']); // 5000 * 50 = 250000 > 100000
        $this->assertEquals(150000, $hasil['kekurangan']);
    }

    /**
     * @test
     */
    public function hitung_estimasi_dari_kampanye(): void
    {
        // Arrange - buat kampanye dengan target
        $kampanye = $this->buatKampanye();
        $this->buatTarget($kampanye, 10);

        // Act
        $hasil = $this->campaignService->hitungEstimasiBiaya(
            $this->klien->id,
            $kampanye->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(10, $hasil['jumlah_target']);
        $this->assertEquals(500, $hasil['estimasi_biaya']); // 10 * 50
    }

    // ==================== TEST STATISTIK CAMPAIGN ====================

    /**
     * @test
     */
    public function hitung_statistik_campaign(): void
    {
        // Arrange - buat kampanye dengan target yang punya berbagai status
        $kampanye = $this->buatKampanye();
        
        // Buat 5 target terkirim
        for ($i = 0; $i < 5; $i++) {
            TargetKampanye::create([
                'kampanye_id' => $kampanye->id,
                'klien_id' => $this->klien->id,
                'no_whatsapp' => '62812345' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'nama' => 'Terkirim ' . $i,
                'status' => 'terkirim',
            ]);
        }
        
        // Buat 1 target gagal
        TargetKampanye::create([
            'kampanye_id' => $kampanye->id,
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6281234599999',
            'nama' => 'Gagal',
            'status' => 'gagal',
        ]);
        
        // Buat 4 target pending
        for ($i = 0; $i < 4; $i++) {
            TargetKampanye::create([
                'kampanye_id' => $kampanye->id,
                'klien_id' => $this->klien->id,
                'no_whatsapp' => '62812346' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'nama' => 'Pending ' . $i,
                'status' => 'pending',
            ]);
        }

        // Act
        $hasil = $this->campaignService->hitungStatistikCampaign($kampanye->id);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(10, $hasil['statistik']['total_target']);
        $this->assertEquals(5, $hasil['statistik']['terkirim']);
        $this->assertEquals(1, $hasil['statistik']['gagal']);
        $this->assertEquals(4, $hasil['statistik']['pending']);
    }

    // ==================== TEST VALIDASI SEBELUM KIRIM ====================

    /**
     * @test
     */
    public function validasi_sebelum_kirim_sukses(): void
    {
        // Arrange - kampanye dengan status siap dan target
        $kampanye = $this->buatKampanye([
            'status' => 'siap',
            'template_pesan' => 'Halo {{nama}}, ini promo spesial!',
        ]);
        $this->buatTarget($kampanye, 10);

        // Act
        $hasil = $this->campaignService->validasiSebelumKirim($this->klien->id, $kampanye->id);

        // Assert
        $this->assertTrue($hasil['valid']);
        $this->assertEquals('VALID', $hasil['kode']);
        $this->assertEquals(10, $hasil['target']['pending']);
        $this->assertTrue($hasil['saldo']['cukup']);
        $this->assertEmpty($hasil['errors']);
    }

    /**
     * @test
     */
    public function validasi_gagal_jika_status_bukan_siap(): void
    {
        // Arrange - kampanye dengan status draft
        $kampanye = $this->buatKampanye([
            'status' => 'draft',
        ]);
        $this->buatTarget($kampanye, 1);

        // Act
        $hasil = $this->campaignService->validasiSebelumKirim($this->klien->id, $kampanye->id);

        // Assert
        $this->assertFalse($hasil['valid']);
        $this->assertNotEmpty($hasil['errors']);
    }

    /**
     * @test
     */
    public function validasi_gagal_jika_tidak_ada_target(): void
    {
        // Arrange - kampanye tanpa target
        $kampanye = $this->buatKampanye([
            'status' => 'siap',
        ]);

        // Act
        $hasil = $this->campaignService->validasiSebelumKirim($this->klien->id, $kampanye->id);

        // Assert
        $this->assertFalse($hasil['valid']);
        $this->assertStringContainsString('tidak ada target', strtolower(implode('', $hasil['errors'])));
    }

    /**
     * @test
     */
    public function validasi_gagal_jika_saldo_tidak_cukup(): void
    {
        // Arrange - saldo rendah
        $this->dompet->update(['saldo_tersedia' => 100]);

        $kampanye = $this->buatKampanye([
            'status' => 'siap',
            'harga_per_pesan' => 50,
        ]);
        $this->buatTarget($kampanye, 10); // butuh 500, saldo hanya 100

        // Act
        $hasil = $this->campaignService->validasiSebelumKirim($this->klien->id, $kampanye->id);

        // Assert
        $this->assertFalse($hasil['valid']);
        $this->assertFalse($hasil['saldo']['cukup']);
    }

    // ==================== TEST MULAI CAMPAIGN ====================

    /**
     * @test
     */
    public function mulai_campaign_sukses(): void
    {
        // Arrange
        $kampanye = $this->buatKampanye([
            'status' => 'siap',
            'template_pesan' => 'Halo {{nama}}!',
            'harga_per_pesan' => 50,
        ]);
        $this->buatTarget($kampanye, 10);

        // Act
        $hasil = $this->campaignService->mulaiCampaign(
            $this->klien->id,
            $kampanye->id,
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('CAMPAIGN_STARTED', $hasil['kode']);
        $this->assertEquals(10, $hasil['proses']['target_pending']);
        $this->assertEquals(500, $hasil['proses']['biaya_dihold']); // 10 * 50

        // Cek status kampanye berubah
        $kampanye->refresh();
        $this->assertEquals('berjalan', $kampanye->status);
        $this->assertNotNull($kampanye->waktu_mulai);

        // Cek saldo dihold
        $this->dompet->refresh();
        $this->assertEquals(99500, $this->dompet->saldo_tersedia); // 100000 - 500
        $this->assertEquals(500, $this->dompet->saldo_tertahan);
    }

    /**
     * @test
     */
    public function mulai_campaign_gagal_validasi(): void
    {
        // Arrange - kampanye draft (tidak bisa dimulai)
        $kampanye = $this->buatKampanye([
            'status' => 'draft',
        ]);

        // Act
        $hasil = $this->campaignService->mulaiCampaign(
            $this->klien->id,
            $kampanye->id,
            $this->admin->id
        );

        // Assert
        $this->assertFalse($hasil['sukses']);
    }

    // ==================== TEST JEDA CAMPAIGN ====================

    /**
     * @test
     */
    public function jeda_campaign_sukses(): void
    {
        // Arrange - kampanye berjalan
        $kampanye = $this->buatKampanye([
            'status' => 'berjalan',
            'waktu_mulai' => now(),
            'total_target' => 100,
            'saldo_dihold' => 5000,
        ]);

        // Set saldo tertahan
        $this->dompet->update([
            'saldo_tersedia' => 95000,
            'saldo_tertahan' => 5000,
        ]);

        // Act
        $hasil = $this->campaignService->jedaCampaign(
            $this->klien->id,
            $kampanye->id,
            'Perlu cek data dulu',
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('CAMPAIGN_PAUSED', $hasil['kode']);

        // Cek status berubah ke jeda
        $kampanye->refresh();
        $this->assertEquals('jeda', $kampanye->status);

        // Saldo TETAP tertahan (belum dilepas)
        $this->dompet->refresh();
        $this->assertEquals(5000, $this->dompet->saldo_tertahan);
    }

    // ==================== TEST LANJUTKAN CAMPAIGN ====================

    /**
     * @test
     */
    public function lanjutkan_campaign_dari_jeda(): void
    {
        // Arrange - kampanye status jeda
        $kampanye = $this->buatKampanye([
            'status' => 'jeda',
            'waktu_mulai' => now()->subHour(),
            'harga_per_pesan' => 50,
        ]);
        $this->buatTarget($kampanye, 5);

        // Act
        $hasil = $this->campaignService->lanjutkanCampaign(
            $this->klien->id,
            $kampanye->id,
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('CAMPAIGN_RESUMED', $hasil['kode']);

        // Status kembali berjalan
        $kampanye->refresh();
        $this->assertEquals('berjalan', $kampanye->status);
    }

    // ==================== TEST DAFTAR CAMPAIGN ====================

    /**
     * @test
     */
    public function daftar_campaign_klien(): void
    {
        // Arrange - buat beberapa kampanye
        for ($i = 0; $i < 3; $i++) {
            $this->buatKampanye(['nama_kampanye' => "Campaign $i"]);
        }

        // Act
        $hasil = $this->campaignService->daftarCampaign($this->klien->id);

        // Assert
        $this->assertEquals(3, $hasil->total());
    }

    /**
     * @test
     */
    public function daftar_campaign_dengan_filter_status(): void
    {
        // Arrange
        $this->buatKampanye(['nama_kampanye' => 'Draft 1', 'status' => 'draft']);
        $this->buatKampanye(['nama_kampanye' => 'Selesai 1', 'status' => 'selesai']);

        // Act - filter hanya draft
        $hasil = $this->campaignService->daftarCampaign($this->klien->id, ['status' => 'draft']);

        // Assert
        $this->assertEquals(1, $hasil->total());
        $this->assertEquals('draft', $hasil->first()->status);
    }

    // ==================== TEST DETAIL CAMPAIGN ====================

    /**
     * @test
     */
    public function detail_campaign_sukses(): void
    {
        // Arrange
        $kampanye = $this->buatKampanye(['nama_kampanye' => 'Test Detail']);

        // Act
        $hasil = $this->campaignService->detailCampaign($this->klien->id, $kampanye->id);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals($kampanye->id, $hasil['kampanye']['id']);
    }

    /**
     * @test
     */
    public function detail_campaign_tidak_ditemukan(): void
    {
        $hasil = $this->campaignService->detailCampaign($this->klien->id, 99999);

        $this->assertFalse($hasil['sukses']);
    }

    // ==================== TEST AMBIL BATCH TARGET ====================

    /**
     * @test
     */
    public function ambil_batch_target_pending(): void
    {
        // Arrange - kampanye harus dalam status berjalan
        $kampanye = $this->buatKampanye(['status' => 'berjalan']);
        $this->buatTarget($kampanye, 20);

        // Act - ambil batch 10
        $hasil = $this->campaignService->ambilBatchTarget($kampanye->id, 10);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertCount(10, $hasil['targets']);
        $this->assertEquals(10, $hasil['sisa_pending']);
    }

    /**
     * @test
     */
    public function ambil_batch_gagal_jika_status_bukan_berjalan(): void
    {
        // Arrange - kampanye dalam status draft
        $kampanye = $this->buatKampanye(['status' => 'draft']);

        // Act
        $hasil = $this->campaignService->ambilBatchTarget($kampanye->id, 10);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak dalam status berjalan', strtolower($hasil['pesan']));
    }

    // ==================== TEST UPDATE STATUS TARGET ====================

    /**
     * @test
     */
    public function update_status_target_ke_terkirim(): void
    {
        // Arrange
        $kampanye = $this->buatKampanye();
        $target = TargetKampanye::create([
            'kampanye_id' => $kampanye->id,
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6281234567890',
            'nama' => 'Test',
            'status' => 'pending',
        ]);

        // Act - field sudah sync: catatan dan message_id
        $hasil = $this->campaignService->updateStatusTarget(
            $target->id,
            'terkirim',
            null,           // catatan
            'MSG-123456'    // message_id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('terkirim', $hasil['status']);

        // Verify database
        $target->refresh();
        $this->assertEquals('terkirim', $target->status);
        $this->assertEquals('MSG-123456', $target->message_id);
    }

    /**
     * @test
     */
    public function update_status_target_ke_gagal(): void
    {
        // Arrange
        $kampanye = $this->buatKampanye();
        $target = TargetKampanye::create([
            'kampanye_id' => $kampanye->id,
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6281234567890',
            'nama' => 'Test',
            'status' => 'pending',
        ]);

        // Act
        $hasil = $this->campaignService->updateStatusTarget(
            $target->id,
            'gagal',
            'Nomor tidak valid', // catatan
            null
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('gagal', $hasil['status']);

        // Verify database
        $target->refresh();
        $this->assertEquals('gagal', $target->status);
        $this->assertEquals('Nomor tidak valid', $target->catatan);
    }

    // ==================== TEST CEK HARUS BERHENTI ====================

    /**
     * @test
     */
    public function cek_harus_berhenti_jika_selesai(): void
    {
        // Arrange - semua target sudah terproses (tidak ada pending di database)
        $kampanye = $this->buatKampanye([
            'status' => 'berjalan',
            'total_target' => 10,
        ]);
        
        // Buat 8 target terkirim dan 2 gagal (semua sudah diproses)
        for ($i = 0; $i < 8; $i++) {
            TargetKampanye::create([
                'kampanye_id' => $kampanye->id,
                'klien_id' => $this->klien->id,
                'no_whatsapp' => '6281234' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'nama' => 'Terkirim ' . $i,
                'status' => 'terkirim',
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            TargetKampanye::create([
                'kampanye_id' => $kampanye->id,
                'klien_id' => $this->klien->id,
                'no_whatsapp' => '6289999' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'nama' => 'Gagal ' . $i,
                'status' => 'gagal',
            ]);
        }

        // Act
        $hasil = $this->campaignService->cekHarusBerhenti($kampanye->id);

        // Assert
        $this->assertTrue($hasil['harus_berhenti']);
        // Alasan bisa bervariasi tergantung implementasi
    }

    /**
     * @test
     */
    public function cek_tidak_harus_berhenti_jika_masih_pending(): void
    {
        // Arrange - masih ada pending
        $kampanye = $this->buatKampanye([
            'status' => 'berjalan',
            'total_target' => 10,
            'terkirim' => 3,
            'gagal' => 0,
            'pending' => 7,
        ]);

        // Buat target pending actual
        $this->buatTarget($kampanye, 7);

        // Act
        $hasil = $this->campaignService->cekHarusBerhenti($kampanye->id);

        // Assert
        $this->assertFalse($hasil['harus_berhenti']);
    }
}

