<?php

namespace Tests\Unit;

use App\Models\Klien;
use App\Models\Pengguna;
use App\Models\DompetSaldo;
use App\Models\Kampanye;
use App\Models\TransaksiSaldo;
use App\Services\SaldoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Test untuk SaldoService
 * 
 * Menguji logika bisnis SaldoService dengan fokus pada ANTI-BONCOS
 */
class SaldoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SaldoService $saldoService;
    protected Klien $klien;
    protected Pengguna $admin;
    protected DompetSaldo $dompet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saldoService = new SaldoService();

        $this->klien = Klien::factory()->create();

        $this->admin = Pengguna::factory()->admin()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Buat dompet dengan saldo awal
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

    // ==================== TEST AMBIL SALDO ====================

    /**
     * @test
     */
    public function ambil_saldo_sukses(): void
    {
        $hasil = $this->saldoService->ambilSaldo($this->klien->id);

        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(100000, $hasil['data']['saldo_tersedia']);
        $this->assertEquals(0, $hasil['data']['saldo_tertahan']);
    }

    /**
     * @test
     */
    public function ambil_saldo_gagal_jika_dompet_tidak_ada(): void
    {
        $hasil = $this->saldoService->ambilSaldo(99999);

        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak ditemukan', $hasil['error']);
    }

    // ==================== TEST CEK SALDO CUKUP ====================

    /**
     * @test
     */
    public function cek_saldo_cukup_jika_nominal_lebih_kecil(): void
    {
        $hasil = $this->saldoService->cekSaldoCukup($this->klien->id, 50000);

        $this->assertTrue($hasil['cukup']);
        $this->assertEquals(100000, $hasil['saldo_tersedia']);
        $this->assertEquals(50000, $hasil['nominal_dibutuhkan']);
        $this->assertEquals(0, $hasil['kekurangan']);
        $this->assertEquals(50000, $hasil['sisa_setelah']);
    }

    /**
     * @test
     */
    public function cek_saldo_tidak_cukup_jika_nominal_lebih_besar(): void
    {
        $hasil = $this->saldoService->cekSaldoCukup($this->klien->id, 150000);

        $this->assertFalse($hasil['cukup']);
        $this->assertEquals(50000, $hasil['kekurangan']);
    }

    /**
     * @test
     */
    public function cek_saldo_tepat_sama(): void
    {
        $hasil = $this->saldoService->cekSaldoCukup($this->klien->id, 100000);

        $this->assertTrue($hasil['cukup']);
        $this->assertEquals(0, $hasil['kekurangan']);
        $this->assertEquals(0, $hasil['sisa_setelah']);
    }

    // ==================== TEST HOLD SALDO ====================

    /**
     * @test
     */
    public function hold_saldo_sukses(): void
    {
        // Arrange
        $kampanye = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'siap', // sync dengan migration enum: draft, siap, berjalan, jeda, selesai, gagal, dibatalkan
        ]);

        // Act
        $hasil = $this->saldoService->holdSaldo(
            $this->klien->id,
            50000,
            $kampanye->id,
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(50000, $hasil['nominal_dihold']);
        $this->assertEquals(100000, $hasil['saldo_sebelum']);
        $this->assertEquals(50000, $hasil['saldo_tersedia']);
        $this->assertEquals(50000, $hasil['saldo_tertahan']);

        // Cek database terupdate
        $this->dompet->refresh();
        $this->assertEquals(50000, $this->dompet->saldo_tersedia);
        $this->assertEquals(50000, $this->dompet->saldo_tertahan);

        // Cek transaksi tercatat
        $this->assertDatabaseHas('transaksi_saldo', [
            'klien_id' => $this->klien->id,
            'kampanye_id' => $kampanye->id,
            'jenis' => 'hold',
        ]);

        // Cek log aktivitas
        $this->assertDatabaseHas('log_aktivitas', [
            'klien_id' => $this->klien->id,
            'aksi' => 'hold',
            'modul' => 'saldo',
        ]);
    }

    /**
     * @test - ANTI BONCOS
     */
    public function hold_saldo_gagal_jika_tidak_cukup(): void
    {
        // Arrange
        $kampanye = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Act - coba hold lebih dari saldo tersedia
        $hasil = $this->saldoService->holdSaldo(
            $this->klien->id,
            150000, // lebih dari 100000
            $kampanye->id,
            $this->admin->id
        );

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak mencukupi', $hasil['error']);
        $this->assertEquals(50000, $hasil['kekurangan']);

        // Pastikan saldo tidak berubah
        $this->dompet->refresh();
        $this->assertEquals(100000, $this->dompet->saldo_tersedia);
        $this->assertEquals(0, $this->dompet->saldo_tertahan);
    }

    /**
     * @test
     */
    public function hold_saldo_gagal_jika_nominal_nol(): void
    {
        $kampanye = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
        ]);

        $hasil = $this->saldoService->holdSaldo(
            $this->klien->id,
            0,
            $kampanye->id,
            $this->admin->id
        );

        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('lebih dari 0', $hasil['error']);
    }

    // ==================== TEST POTONG SALDO ====================

    /**
     * @test
     */
    public function potong_saldo_sukses(): void
    {
        // Arrange - hold dulu
        $kampanye = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'saldo_dihold' => 50000,
        ]);

        // Set saldo tertahan
        $this->dompet->update([
            'saldo_tersedia' => 50000,
            'saldo_tertahan' => 50000,
        ]);

        // Act - potong 10 pesan @50
        $hasil = $this->saldoService->potongSaldo(
            $this->klien->id,
            $kampanye->id,
            10, // jumlah pesan
            50, // harga per pesan
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(500, $hasil['nominal_dipotong']); // 10 * 50
        $this->assertEquals(10, $hasil['jumlah_pesan']);
        $this->assertEquals(50000, $hasil['saldo_tertahan_sebelum']);
        $this->assertEquals(49500, $hasil['saldo_tertahan_sekarang']); // 50000 - 500

        // Cek database
        $this->dompet->refresh();
        $this->assertEquals(49500, $this->dompet->saldo_tertahan);
        $this->assertEquals(500, $this->dompet->total_terpakai);
    }

    // ==================== TEST LEPAS HOLD / REFUND SALDO ====================

    /**
     * @test
     */
    public function lepas_hold_saldo_sukses(): void
    {
        // Arrange - set kondisi sudah ada saldo tertahan
        $kampanye = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'saldo_dihold' => 30000,
        ]);

        $this->dompet->update([
            'saldo_tersedia' => 70000,
            'saldo_tertahan' => 30000,
        ]);

        // Act
        $hasil = $this->saldoService->lepasHold(
            $this->klien->id,
            30000,
            $kampanye->id,
            'Campaign dibatalkan',
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(30000, $hasil['nominal_dilepas']);

        // Cek saldo kembali ke tersedia
        $this->dompet->refresh();
        $this->assertEquals(100000, $this->dompet->saldo_tersedia);
        $this->assertEquals(0, $this->dompet->saldo_tertahan);

        // Cek transaksi tercatat
        $this->assertDatabaseHas('transaksi_saldo', [
            'klien_id' => $this->klien->id,
            'kampanye_id' => $kampanye->id,
            'jenis' => 'release',
        ]);
    }

    // ==================== TEST TAMBAH SALDO (TOPUP) ====================

    /**
     * @test
     */
    public function tambah_saldo_sukses(): void
    {
        // Arrange - buat transaksi topup pending
        $transaksiTopup = TransaksiSaldo::create([
            'kode_transaksi' => 'TOP-' . date('Ymd') . '-12345',
            'klien_id' => $this->klien->id,
            'dompet_id' => $this->dompet->id,
            'kampanye_id' => null,
            'jenis' => 'topup',
            'nominal' => 50000,
            'saldo_sebelum' => 100000,
            'saldo_sesudah' => 150000,
            'deskripsi' => 'Top up via transfer',
            'status_topup' => 'pending',
        ]);

        // Act
        $hasil = $this->saldoService->tambahSaldo(
            $this->klien->id,
            50000,
            $transaksiTopup->id,
            $this->admin->id,
            'Transfer Bank BCA'
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(50000, $hasil['nominal_ditambah']);
        $this->assertEquals(150000, $hasil['saldo_sesudah']); // 100000 + 50000

        // Cek database
        $this->dompet->refresh();
        $this->assertEquals(150000, $this->dompet->saldo_tersedia);
        $this->assertEquals(150000, $this->dompet->total_topup);
    }

    /**
     * @test
     */
    public function tambah_saldo_gagal_jika_nominal_negatif(): void
    {
        // Buat transaksi topup dummy
        $transaksiTopup = TransaksiSaldo::create([
            'kode_transaksi' => 'TOP-' . date('Ymd') . '-99999',
            'klien_id' => $this->klien->id,
            'dompet_id' => $this->dompet->id,
            'jenis' => 'topup',
            'nominal' => -10000,
            'saldo_sebelum' => 100000,
            'saldo_sesudah' => 100000,
            'deskripsi' => 'Test',
            'status_topup' => 'pending',
        ]);

        $hasil = $this->saldoService->tambahSaldo(
            $this->klien->id,
            -10000,
            $transaksiTopup->id,
            $this->admin->id
        );

        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('lebih dari 0', $hasil['error']);
    }

    // ==================== TEST CONCURRENT HOLD (ANTI BONCOS) ====================

    /**
     * @test
     * Pastikan tidak bisa hold lebih dari saldo total meskipun dipanggil bersamaan
     */
    public function double_hold_tidak_menyebabkan_boncos(): void
    {
        // Arrange
        $kampanye1 = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
        ]);
        $kampanye2 = Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Act - hold pertama berhasil
        $hasil1 = $this->saldoService->holdSaldo(
            $this->klien->id,
            60000,
            $kampanye1->id,
            $this->admin->id
        );

        // Hold kedua harus gagal karena sisa hanya 40000
        $hasil2 = $this->saldoService->holdSaldo(
            $this->klien->id,
            60000,
            $kampanye2->id,
            $this->admin->id
        );

        // Assert
        $this->assertTrue($hasil1['sukses']);
        $this->assertFalse($hasil2['sukses']);

        // Pastikan saldo tidak negatif
        $this->dompet->refresh();
        $this->assertGreaterThanOrEqual(0, $this->dompet->saldo_tersedia);
        $this->assertEquals(60000, $this->dompet->saldo_tertahan);
    }

    // ==================== TEST HITUNG ESTIMASI ====================

    /**
     * @test
     */
    public function hitung_estimasi_biaya_sukses(): void
    {
        $hasil = $this->saldoService->hitungEstimasi(
            $this->klien->id,
            100, // jumlah target
            50   // harga per pesan
        );

        $this->assertTrue($hasil['sukses']);
        $this->assertEquals(100, $hasil['jumlah_target']);
        $this->assertEquals(50, $hasil['harga_per_pesan']);
        $this->assertEquals(5000, $hasil['estimasi_biaya']);
        $this->assertTrue($hasil['cukup']); // 100000 > 5000
        $this->assertEquals(95000, $hasil['sisa_setelah_kirim']);
    }

    // ==================== TEST STATUS SALDO ====================

    /**
     * @test
     */
    public function status_saldo_aman_jika_diatas_warning(): void
    {
        $hasil = $this->saldoService->ambilSaldo($this->klien->id);

        $this->assertEquals('aman', $hasil['data']['status']);
    }

    /**
     * @test
     */
    public function status_saldo_warning_jika_dibawah_warning(): void
    {
        $this->dompet->update([
            'saldo_tersedia' => 40000, // dibawah batas_warning 50000
        ]);

        $hasil = $this->saldoService->ambilSaldo($this->klien->id);

        $this->assertEquals('menipis', $hasil['data']['status']);
    }

    /**
     * @test
     */
    public function status_saldo_kritis_jika_dibawah_minimum(): void
    {
        $this->dompet->update([
            'saldo_tersedia' => 5000, // dibawah batas_minimum 10000
        ]);

        $hasil = $this->saldoService->ambilSaldo($this->klien->id);

        $this->assertEquals('kritis', $hasil['data']['status']);
    }
}
