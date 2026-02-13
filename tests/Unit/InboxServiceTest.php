<?php

namespace Tests\Unit;

use App\Models\Klien;
use App\Models\Pengguna;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Services\InboxService;
use App\Events\Inbox\PesanMasukEvent;
use App\Events\Inbox\PercakapanDiambilEvent;
use App\Events\Inbox\PercakapanDilepasEvent;
use App\Events\Inbox\PesanDibacaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Unit Test untuk InboxService
 * 
 * Menguji logika bisnis InboxService secara terisolasi
 */
class InboxServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InboxService $inboxService;
    protected Klien $klien;
    protected Pengguna $sales1;
    protected Pengguna $sales2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxService = new InboxService();

        $this->klien = Klien::factory()->create([
            'no_whatsapp' => '6281234567890',
        ]);

        $this->sales1 = Pengguna::factory()->sales()->create([
            'klien_id' => $this->klien->id,
        ]);

        $this->sales2 = Pengguna::factory()->sales()->create([
            'klien_id' => $this->klien->id,
        ]);
    }

    // ==================== TEST PROSES PESAN MASUK ====================

    /**
     * @test
     */
    public function proses_pesan_masuk_sukses_menyimpan_pesan_baru(): void
    {
        // Arrange
        Event::fake();

        $data = [
            'no_bisnis' => '6281234567890',
            'no_customer' => '6289876543210',
            'wa_message_id' => 'wamid.test123456',
            'tipe' => 'text',
            'isi_pesan' => 'Halo, saya mau tanya produk',
            'timestamp' => time(),
        ];

        // Act
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('SUCCESS', $hasil['kode']);
        $this->assertArrayHasKey('data', $hasil);

        // Cek percakapan dibuat
        $this->assertDatabaseHas('percakapan_inbox', [
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6289876543210',
            'status' => 'baru',
        ]);

        // Cek pesan disimpan
        $this->assertDatabaseHas('pesan_inbox', [
            'wa_message_id' => 'wamid.test123456',
            'arah' => 'masuk',
            'tipe' => 'teks',
        ]);

        // Cek event di-dispatch
        Event::assertDispatched(PesanMasukEvent::class);
    }

    /**
     * @test
     */
    public function proses_pesan_masuk_idempotent_tidak_duplikat(): void
    {
        // Arrange
        $percakapan = PercakapanInbox::factory()->create([
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6289876543210',
        ]);

        PesanInbox::factory()->create([
            'percakapan_id' => $percakapan->id,
            'klien_id' => $this->klien->id,
            'wa_message_id' => 'wamid.existing123',
        ]);

        $data = [
            'no_bisnis' => '6281234567890',
            'no_customer' => '6289876543210',
            'wa_message_id' => 'wamid.existing123', // ID sama
            'tipe' => 'text',
            'isi_pesan' => 'Pesan duplikat',
        ];

        // Act
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertEquals('ALREADY_EXISTS', $hasil['kode']);
        $this->assertTrue($hasil['idempotent']);

        // Pastikan tidak ada duplikat
        $this->assertEquals(1, PesanInbox::where('wa_message_id', 'wamid.existing123')->count());
    }

    /**
     * @test
     */
    public function proses_pesan_masuk_gagal_jika_klien_tidak_ditemukan(): void
    {
        // Arrange
        $data = [
            'no_bisnis' => '6200000000000', // nomor tidak terdaftar
            'no_customer' => '6289876543210',
            'wa_message_id' => 'wamid.test456',
            'tipe' => 'text',
            'isi_pesan' => 'Test',
        ];

        // Act
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertEquals('KLIEN_NOT_FOUND', $hasil['kode']);
    }

    /**
     * @test
     */
    public function proses_pesan_masuk_gagal_jika_data_tidak_lengkap(): void
    {
        // Arrange
        $data = [
            'no_bisnis' => '6281234567890',
            // no_customer tidak ada
            // wa_message_id tidak ada
        ];

        // Act
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertEquals('INVALID_DATA', $hasil['kode']);
    }

    /**
     * @test
     */
    public function proses_pesan_masuk_update_statistik_percakapan(): void
    {
        // Arrange
        $percakapan = PercakapanInbox::factory()->create([
            'klien_id' => $this->klien->id,
            'no_whatsapp' => '6289876543210',
            'total_pesan' => 5,
            'pesan_belum_dibaca' => 2,
        ]);

        $data = [
            'no_bisnis' => '6281234567890',
            'no_customer' => '6289876543210',
            'wa_message_id' => 'wamid.new123',
            'tipe' => 'text',
            'isi_pesan' => 'Pesan baru',
        ];

        // Act
        $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $percakapan->refresh();
        $this->assertEquals(6, $percakapan->total_pesan);
        $this->assertEquals(3, $percakapan->pesan_belum_dibaca);
        $this->assertEquals('customer', $percakapan->pengirim_terakhir);
    }

    // ==================== TEST AMBIL PERCAKAPAN ====================

    /**
     * @test
     */
    public function ambil_percakapan_sukses(): void
    {
        // Arrange
        Event::fake();

        $percakapan = PercakapanInbox::factory()->baru()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Act
        $hasil = $this->inboxService->ambilPercakapan($percakapan->id, $this->sales1->id);

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertArrayHasKey('percakapan', $hasil);

        $percakapan->refresh();
        $this->assertEquals($this->sales1->id, $percakapan->ditangani_oleh);
        $this->assertEquals('aktif', $percakapan->status);
        $this->assertTrue($percakapan->terkunci);
        $this->assertNotNull($percakapan->waktu_diambil);

        Event::assertDispatched(PercakapanDiambilEvent::class);
    }

    /**
     * @test
     */
    public function ambil_percakapan_gagal_jika_sudah_dikunci_sales_lain(): void
    {
        // Arrange
        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $hasil = $this->inboxService->ambilPercakapan($percakapan->id, $this->sales2->id);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('sedang ditangani', $hasil['pesan']);
    }

    /**
     * @test
     */
    public function ambil_percakapan_tidak_ditemukan(): void
    {
        // Act
        $hasil = $this->inboxService->ambilPercakapan(99999, $this->sales1->id);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak ditemukan', $hasil['pesan']);
    }

    // ==================== TEST LEPAS PERCAKAPAN ====================

    /**
     * @test
     */
    public function lepas_percakapan_sukses(): void
    {
        // Arrange
        Event::fake();

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
                'pesan_belum_dibaca' => 0,
            ]);

        // Act
        $hasil = $this->inboxService->lepasPercakapan($percakapan->id, $this->sales1->id);

        // Assert
        $this->assertTrue($hasil['sukses']);

        $percakapan->refresh();
        $this->assertNull($percakapan->ditangani_oleh);
        $this->assertFalse($percakapan->terkunci);
        $this->assertEquals('baru', $percakapan->status); // karena pesan_belum_dibaca = 0

        Event::assertDispatched(PercakapanDilepasEvent::class);
    }

    /**
     * @test
     */
    public function lepas_percakapan_status_menjadi_belum_dibaca_jika_ada_pesan_baru(): void
    {
        // Arrange
        Event::fake();

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
                'pesan_belum_dibaca' => 3, // ada pesan belum dibaca
            ]);

        // Act
        $hasil = $this->inboxService->lepasPercakapan($percakapan->id, $this->sales1->id);

        // Assert
        $this->assertTrue($hasil['sukses']);

        $percakapan->refresh();
        $this->assertEquals('belum_dibaca', $percakapan->status);
    }

    /**
     * @test
     */
    public function lepas_percakapan_gagal_jika_bukan_penanggungjawab(): void
    {
        // Arrange
        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $hasil = $this->inboxService->lepasPercakapan($percakapan->id, $this->sales2->id);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak sedang menangani', $hasil['pesan']);
    }

    // ==================== TEST TANDAI SUDAH DIBACA ====================

    /**
     * @test
     */
    public function tandai_sudah_dibaca_sukses(): void
    {
        // Arrange
        Event::fake();

        $percakapan = PercakapanInbox::factory()->create([
            'klien_id' => $this->klien->id,
            'pesan_belum_dibaca' => 5,
        ]);

        // Buat 5 pesan belum dibaca
        PesanInbox::factory()->count(5)->masuk()->create([
            'percakapan_id' => $percakapan->id,
            'klien_id' => $this->klien->id,
            'dibaca_sales' => false,
        ]);

        // Act
        $hasil = $this->inboxService->tandaiSudahDibaca($percakapan->id, $this->sales1->id);

        // Assert
        $this->assertTrue($hasil['sukses']);

        // Cek semua pesan ditandai dibaca
        $belumDibaca = PesanInbox::where('percakapan_id', $percakapan->id)
            ->where('dibaca_sales', false)
            ->count();
        $this->assertEquals(0, $belumDibaca);

        // Cek counter direset
        $percakapan->refresh();
        $this->assertEquals(0, $percakapan->pesan_belum_dibaca);

        Event::assertDispatched(PesanDibacaEvent::class);
    }

    /**
     * @test
     */
    public function tandai_sudah_dibaca_tidak_ditemukan(): void
    {
        // Act
        $hasil = $this->inboxService->tandaiSudahDibaca(99999, $this->sales1->id);

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak ditemukan', $hasil['pesan']);
    }

    // ==================== TEST TRANSFER PERCAKAPAN ====================

    /**
     * @test
     */
    public function transfer_percakapan_sukses(): void
    {
        // Arrange
        Event::fake();

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $hasil = $this->inboxService->transferPercakapan(
            $percakapan->id,
            $this->sales1->id,
            $this->sales2->id,
            'Customer butuh expertise lain'
        );

        // Assert
        $this->assertTrue($hasil['sukses']);
        $this->assertStringContainsString($this->sales2->nama_lengkap, $hasil['pesan']);

        $percakapan->refresh();
        $this->assertEquals($this->sales2->id, $percakapan->ditangani_oleh);
        $this->assertTrue($percakapan->terkunci);

        // Cek log aktivitas
        $this->assertDatabaseHas('log_aktivitas', [
            'aksi' => 'transfer_percakapan',
            'klien_id' => $this->klien->id,
        ]);

        Event::assertDispatched(PercakapanDiambilEvent::class);
    }

    /**
     * @test
     */
    public function transfer_percakapan_gagal_jika_tujuan_tidak_aktif(): void
    {
        // Arrange
        $salesTidakAktif = Pengguna::factory()->tidakAktif()->create([
            'klien_id' => $this->klien->id,
        ]);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $hasil = $this->inboxService->transferPercakapan(
            $percakapan->id,
            $this->sales1->id,
            $salesTidakAktif->id
        );

        // Assert
        $this->assertFalse($hasil['sukses']);
        $this->assertStringContainsString('tidak valid', $hasil['pesan']);
    }

    /**
     * @test
     */
    public function transfer_percakapan_gagal_jika_tujuan_beda_klien(): void
    {
        // Arrange
        $klienLain = Klien::factory()->create();
        $salesKlienLain = Pengguna::factory()->sales()->create([
            'klien_id' => $klienLain->id,
        ]);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $hasil = $this->inboxService->transferPercakapan(
            $percakapan->id,
            $this->sales1->id,
            $salesKlienLain->id
        );

        // Assert
        $this->assertFalse($hasil['sukses']);
    }

    // ==================== TEST MAP TIPE PESAN ====================

    /**
     * @test
     * @dataProvider tipePesanProvider
     */
    public function proses_pesan_masuk_dengan_berbagai_tipe(string $tipeGupshup, string $tipeExpected): void
    {
        // Arrange
        $data = [
            'no_bisnis' => '6281234567890',
            'no_customer' => '6281111111111',
            'wa_message_id' => 'wamid.' . uniqid(),
            'tipe' => $tipeGupshup,
            'isi_pesan' => 'Test',
        ];

        // Act
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        // Assert
        $this->assertTrue($hasil['sukses']);

        $pesan = PesanInbox::where('wa_message_id', $data['wa_message_id'])->first();
        $this->assertEquals($tipeExpected, $pesan->tipe);
    }

    public static function tipePesanProvider(): array
    {
        return [
            'text menjadi teks' => ['text', 'teks'],
            'image menjadi gambar' => ['image', 'gambar'],
            'document menjadi dokumen' => ['document', 'dokumen'],
            'audio menjadi audio' => ['audio', 'audio'],
            'video menjadi video' => ['video', 'video'],
            'location menjadi lokasi' => ['location', 'lokasi'],
            'contact menjadi kontak' => ['contact', 'kontak'],
            'sticker menjadi sticker' => ['sticker', 'sticker'],
            'unknown menjadi teks' => ['unknown_type', 'teks'],
        ];
    }
}
