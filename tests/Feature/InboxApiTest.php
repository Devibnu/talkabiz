<?php

namespace Tests\Feature;

use App\Models\Klien;
use App\Models\Pengguna;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature Test untuk API Inbox
 * 
 * Menguji endpoint API Inbox dengan fokus pada:
 * - Role-based access control
 * - State percakapan
 * - Validasi business logic
 */
class InboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected Klien $klien;
    protected Pengguna $sales1;
    protected Pengguna $sales2;
    protected Pengguna $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup klien dan pengguna
        $this->klien = Klien::factory()->create();
        
        $this->sales1 = Pengguna::factory()->sales()->create([
            'klien_id' => $this->klien->id,
            'nama_lengkap' => 'Sales Satu',
        ]);

        $this->sales2 = Pengguna::factory()->sales()->create([
            'klien_id' => $this->klien->id,
            'nama_lengkap' => 'Sales Dua',
        ]);

        $this->admin = Pengguna::factory()->admin()->create([
            'klien_id' => $this->klien->id,
            'nama_lengkap' => 'Admin Klien',
        ]);
    }

    // ==================== TEST AMBIL PERCAKAPAN ====================

    /**
     * @test
     */
    public function sales_dapat_mengambil_percakapan_yang_belum_diambil(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()->baru()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/ambil");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'pesan' => 'Percakapan berhasil diambil',
            ]);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'ditangani_oleh' => $this->sales1->id,
            'status' => 'aktif',
            'terkunci' => true,
        ]);
    }

    /**
     * @test
     */
    public function percakapan_tidak_dapat_diambil_ganda_oleh_sales_lain(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales2);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/ambil");

        // Assert
        $response->assertStatus(422)
            ->assertJson([
                'sukses' => false,
                'pesan' => 'Percakapan sedang ditangani oleh sales lain',
            ]);

        // Pastikan tidak berubah
        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'ditangani_oleh' => $this->sales1->id,
        ]);
    }

    /**
     * @test
     */
    public function sales_yang_sama_dapat_mengambil_ulang_percakapannya(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/ambil");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
            ]);
    }

    // ==================== TEST LEPAS PERCAKAPAN ====================

    /**
     * @test
     */
    public function sales_dapat_melepas_percakapan_yang_ditangani(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
                'pesan_belum_dibaca' => 2,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/lepas");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'pesan' => 'Percakapan berhasil dilepas',
            ]);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'ditangani_oleh' => null,
            'terkunci' => false,
            'status' => 'belum_dibaca', // karena masih ada pesan belum dibaca
        ]);
    }

    /**
     * @test
     */
    public function sales_tidak_dapat_melepas_percakapan_sales_lain(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales2);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/lepas");

        // Assert
        $response->assertStatus(422)
            ->assertJson([
                'sukses' => false,
                'pesan' => 'Anda tidak sedang menangani percakapan ini',
            ]);
    }

    // ==================== TEST TRANSFER PERCAKAPAN ====================

    /**
     * @test
     */
    public function sales_dapat_transfer_percakapan_ke_sales_lain(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/transfer", [
            'pengguna_id' => $this->sales2->id,
            'catatan' => 'Customer butuh follow up lebih lanjut',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
            ]);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'ditangani_oleh' => $this->sales2->id,
            'terkunci' => true,
        ]);

        // Cek log aktivitas
        $this->assertDatabaseHas('log_aktivitas', [
            'klien_id' => $this->klien->id,
            'aksi' => 'transfer_percakapan',
        ]);
    }

    /**
     * @test
     */
    public function admin_dapat_transfer_percakapan_meskipun_bukan_penanggungjawab(): void
    {
        // Arrange
        Sanctum::actingAs($this->admin);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/transfer", [
            'pengguna_id' => $this->sales2->id,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
            ]);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'ditangani_oleh' => $this->sales2->id,
        ]);
    }

    /**
     * @test
     */
    public function sales_tidak_dapat_transfer_percakapan_sales_lain(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales2);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/transfer", [
            'pengguna_id' => $this->sales2->id,
        ]);

        // Assert
        $response->assertStatus(403)
            ->assertJson([
                'sukses' => false,
            ]);
    }

    // ==================== TEST KIRIM PESAN ====================

    /**
     * @test
     */
    public function sales_dapat_kirim_pesan_jika_menangani_percakapan(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Mock WhatsApp service menggunakan swap
        $mockWhatsApp = \Mockery::mock(\App\Contracts\WhatsAppProviderInterface::class);
        $mockWhatsApp->shouldReceive('kirimPesan')
            ->andReturn([
                'sukses' => true,
                'pesan' => 'Pesan terkirim',
                'message_id' => 'wamid.test123',
                'data' => []
            ]);
        $this->swap('whatsapp', $mockWhatsApp);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/kirim", [
            'tipe' => 'teks',
            'isi_pesan' => 'Halo, ada yang bisa kami bantu?',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'pesan' => 'Pesan berhasil dikirim',
            ]);
    }

    /**
     * @test
     */
    public function sales_tidak_dapat_kirim_pesan_jika_tidak_menangani(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales2);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/kirim", [
            'tipe' => 'teks',
            'isi_pesan' => 'Test pesan',
        ]);

        // Assert
        $response->assertStatus(403)
            ->assertJson([
                'sukses' => false,
                'pesan' => 'Anda harus mengambil percakapan ini terlebih dahulu',
            ]);
    }

    /**
     * @test
     */
    public function validasi_gagal_jika_tipe_teks_tanpa_isi_pesan(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/kirim", [
            'tipe' => 'teks',
            // isi_pesan tidak ada
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['isi_pesan']);
    }

    // ==================== TEST TANDAI BACA ====================

    /**
     * @test
     */
    public function sales_dapat_menandai_pesan_sudah_dibaca(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()->create([
            'klien_id' => $this->klien->id,
            'pesan_belum_dibaca' => 3,
        ]);

        // Buat beberapa pesan belum dibaca
        PesanInbox::factory()->count(3)->masuk()->create([
            'percakapan_id' => $percakapan->id,
            'klien_id' => $this->klien->id,
            'dibaca_sales' => false,
        ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/baca");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'pesan' => 'Pesan ditandai sudah dibaca',
            ]);

        // Cek semua pesan sudah dibaca
        $this->assertEquals(0, PesanInbox::where('percakapan_id', $percakapan->id)
            ->where('dibaca_sales', false)
            ->count());

        // Cek counter direset
        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'pesan_belum_dibaca' => 0,
        ]);
    }

    // ==================== TEST BADGE COUNTER ====================

    /**
     * @test
     */
    public function badge_counter_menampilkan_jumlah_yang_benar(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        // Buat percakapan baru (belum diambil)
        PercakapanInbox::factory()->count(3)->baru()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Buat percakapan yang diambil sales1 dengan pesan belum dibaca
        PercakapanInbox::factory()->count(2)
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
                'pesan_belum_dibaca' => 1,
            ]);

        // Buat percakapan yang diambil sales2
        PercakapanInbox::factory()
            ->ditanganiOleh($this->sales2->id)
            ->create([
                'klien_id' => $this->klien->id,
                'pesan_belum_dibaca' => 1,
            ]);

        // Act
        $response = $this->getJson('/api/inbox/counter');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'data' => [
                    'total_baru' => 3, // percakapan baru/belum dibaca
                    'milik_saya' => 2, // yang ditangani sales1 dengan pesan belum dibaca
                    'belum_diambil' => 3, // yang belum ada penanggungjawab
                ],
            ]);
    }

    /**
     * @test
     */
    public function counter_berkurang_setelah_mengambil_percakapan(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()->baru()->create([
            'klien_id' => $this->klien->id,
        ]);

        // Cek counter awal
        $responseBefore = $this->getJson('/api/inbox/counter');
        $belumDiambilBefore = $responseBefore->json('data.belum_diambil');

        // Act - ambil percakapan
        $this->postJson("/api/inbox/{$percakapan->id}/ambil");

        // Assert - counter berkurang
        $responseAfter = $this->getJson('/api/inbox/counter');
        $responseAfter->assertJson([
            'data' => [
                'belum_diambil' => $belumDiambilBefore - 1,
            ],
        ]);
    }

    // ==================== TEST SELESAI PERCAKAPAN ====================

    /**
     * @test
     */
    public function sales_dapat_menandai_percakapan_selesai(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/selesai");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'pesan' => 'Percakapan ditandai selesai',
            ]);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $percakapan->id,
            'status' => 'selesai',
        ]);
    }

    /**
     * @test
     */
    public function admin_dapat_menandai_percakapan_selesai_meskipun_bukan_penanggungjawab(): void
    {
        // Arrange
        Sanctum::actingAs($this->admin);

        $percakapan = PercakapanInbox::factory()
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->postJson("/api/inbox/{$percakapan->id}/selesai");

        // Assert
        $response->assertStatus(200);
    }

    // ==================== TEST LIST & FILTER ====================

    /**
     * @test
     */
    public function dapat_filter_percakapan_berdasarkan_status(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        PercakapanInbox::factory()->baru()->count(2)->create([
            'klien_id' => $this->klien->id,
        ]);

        PercakapanInbox::factory()->selesai()->count(3)->create([
            'klien_id' => $this->klien->id,
        ]);

        // Act
        $response = $this->getJson('/api/inbox?status=baru');

        // Assert
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));
    }

    /**
     * @test
     */
    public function dapat_filter_percakapan_milik_saya(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        // Percakapan milik sales1
        PercakapanInbox::factory()->count(2)
            ->ditanganiOleh($this->sales1->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Percakapan milik sales2
        PercakapanInbox::factory()->count(3)
            ->ditanganiOleh($this->sales2->id)
            ->create([
                'klien_id' => $this->klien->id,
            ]);

        // Act
        $response = $this->getJson('/api/inbox?ditangani_oleh=me');

        // Assert
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total'));
    }

    // ==================== TEST ACCESS CONTROL ====================

    /**
     * @test
     */
    public function tidak_dapat_akses_inbox_tanpa_autentikasi(): void
    {
        // Act (tanpa Sanctum::actingAs)
        $response = $this->getJson('/api/inbox');

        // Assert
        $response->assertStatus(401);
    }

    /**
     * @test
     */
    public function tidak_dapat_akses_percakapan_klien_lain(): void
    {
        // Arrange
        Sanctum::actingAs($this->sales1);

        $klienLain = Klien::factory()->create();
        $percakapan = PercakapanInbox::factory()->create([
            'klien_id' => $klienLain->id,
        ]);

        // Act
        $response = $this->getJson("/api/inbox/{$percakapan->id}");

        // Assert
        $response->assertStatus(404);
    }
}
