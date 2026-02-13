<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use App\Events\TemplateDiajukanEvent;
use App\Events\TemplateDisetujuiEvent;
use App\Events\TemplateDitolakEvent;
use App\Models\TemplatePesan;
use App\Models\Kampanye;
use App\Models\Klien;
use App\Models\Pengguna;
use App\Services\TemplateService;
use App\Services\WhatsAppTemplateProvider;

/**
 * TemplateServiceTest
 * 
 * Unit test untuk TemplateService.
 * Fokus pada business logic:
 * - Status transition (draft → diajukan → disetujui/ditolak)
 * - Role restriction
 * - Template locking (dipakai campaign)
 * 
 * @author TalkaBiz Team
 */
class TemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TemplateService $service;
    protected $mockProvider;
    protected Klien $klien;
    protected Pengguna $owner;
    protected Pengguna $admin;
    protected Pengguna $sales;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock WhatsAppTemplateProvider
        $this->mockProvider = Mockery::mock(WhatsAppTemplateProvider::class);
        $this->app->instance(WhatsAppTemplateProvider::class, $this->mockProvider);

        $this->service = new TemplateService($this->mockProvider);

        // Buat data test
        $this->klien = Klien::factory()->create();
        $this->owner = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
            'role' => 'owner',
        ]);
        $this->admin = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
            'role' => 'admin',
        ]);
        $this->sales = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
            'role' => 'sales',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ==================== BUAT TEMPLATE ====================

    /** @test */
    public function dapat_membuat_template_draft(): void
    {
        $data = [
            'nama_template' => 'promo_test',
            'nama_tampilan' => 'Promo Test',
            'kategori' => 'marketing',
            'bahasa' => 'id',
            'isi_template' => 'Halo {{1}}, promo {{2}}!',
            'variable' => ['1' => 'Budi', '2' => '50%'],
        ];

        $result = $this->service->buatTemplate($this->klien->id, $data, $this->owner->id);

        $this->assertTrue($result['sukses']);
        $this->assertEquals('draft', $result['template']->status);
        $this->assertDatabaseHas('template_pesan', [
            'klien_id' => $this->klien->id,
            'nama_template' => 'promo_test',
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function tidak_bisa_membuat_template_dengan_nama_duplikat(): void
    {
        TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'existing_template',
        ]);

        $data = [
            'nama_template' => 'existing_template',
            'kategori' => 'marketing',
            'isi_template' => 'Test body',
        ];

        $result = $this->service->buatTemplate($this->klien->id, $data, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('sudah digunakan', $result['pesan']);
    }

    /** @test */
    public function tidak_bisa_membuat_template_dengan_nama_invalid(): void
    {
        $data = [
            'nama_template' => 'INVALID-NAMA', // huruf besar & dash
            'kategori' => 'marketing',
            'isi_template' => 'Test body',
        ];

        $result = $this->service->buatTemplate($this->klien->id, $data, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertArrayHasKey('errors', $result);
    }

    /** @test */
    public function validasi_variable_harus_lengkap(): void
    {
        $data = [
            'nama_template' => 'incomplete_var',
            'kategori' => 'marketing',
            'isi_template' => 'Halo {{1}}, promo {{2}} untuk {{3}}!',
            'variable' => ['1' => 'Budi'], // Missing 2 dan 3
        ];

        $result = $this->service->buatTemplate($this->klien->id, $data, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertArrayHasKey('errors', $result);
    }

    // ==================== UPDATE TEMPLATE ====================

    /** @test */
    public function dapat_mengupdate_template_draft(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'body' => 'Body lama',
        ]);

        $result = $this->service->updateTemplate(
            $this->klien->id,
            $template->id,
            ['isi_template' => 'Body baru yang diupdate'],
            $this->owner->id
        );

        $this->assertTrue($result['sukses']);
        $this->assertEquals('Body baru yang diupdate', $result['template']->body);
    }

    /** @test */
    public function dapat_mengupdate_template_ditolak(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'ditolak',
            'alasan_penolakan' => 'Policy violation',
        ]);

        $result = $this->service->updateTemplate(
            $this->klien->id,
            $template->id,
            ['isi_template' => 'Body diperbaiki'],
            $this->owner->id
        );

        $this->assertTrue($result['sukses']);
        // Status harus kembali ke draft setelah edit
        $this->assertEquals('draft', $result['template']->status);
        $this->assertNull($result['template']->alasan_penolakan);
    }

    /** @test */
    public function tidak_bisa_mengupdate_template_disetujui(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $result = $this->service->updateTemplate(
            $this->klien->id,
            $template->id,
            ['isi_template' => 'Body baru'],
            $this->owner->id
        );

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('tidak dapat diedit', $result['pesan']);
    }

    /** @test */
    public function tidak_bisa_mengupdate_template_diajukan(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'diajukan',
        ]);

        $result = $this->service->updateTemplate(
            $this->klien->id,
            $template->id,
            ['isi_template' => 'Body baru'],
            $this->owner->id
        );

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('tidak dapat diedit', $result['pesan']);
    }

    // ==================== HAPUS TEMPLATE ====================

    /** @test */
    public function dapat_menghapus_template_draft(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'dipakai_count' => 0,
        ]);

        $result = $this->service->hapusTemplate($this->klien->id, $template->id, $this->owner->id);

        $this->assertTrue($result['sukses']);

        $template->refresh();
        $this->assertFalse($template->aktif);
        $this->assertEquals('arsip', $template->status);
    }

    /** @test */
    public function template_tidak_bisa_dihapus_jika_dipakai_campaign(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
            'dipakai_count' => 0,
        ]);

        // Buat campaign yang menggunakan template ini
        Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'template_id' => $template->id,
            'status' => 'berjalan',
        ]);

        $result = $this->service->hapusTemplate($this->klien->id, $template->id, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('campaign aktif', $result['pesan']);

        // Template tidak berubah
        $template->refresh();
        $this->assertTrue($template->aktif);
    }

    /** @test */
    public function template_tidak_bisa_dihapus_jika_pernah_dipakai(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
            'dipakai_count' => 5, // Pernah dipakai 5x
        ]);

        $result = $this->service->hapusTemplate($this->klien->id, $template->id, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('pernah digunakan', $result['pesan']);
    }

    // ==================== AJUKAN TEMPLATE ====================

    /** @test */
    public function dapat_mengajukan_template_ke_provider(): void
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'body' => 'Halo {{1}}!',
            'contoh_variabel' => ['1' => 'Budi'],
        ]);

        $this->mockProvider
            ->shouldReceive('submitTemplate')
            ->once()
            ->andReturn([
                'sukses' => true,
                'template_id' => 'provider_12345',
            ]);

        $result = $this->service->ajukanTemplateKeProvider($this->klien->id, $template->id, $this->owner->id);

        $this->assertTrue($result['sukses']);

        $template->refresh();
        $this->assertEquals('diajukan', $template->status);
        $this->assertEquals('provider_12345', $template->provider_template_id);
        $this->assertNotNull($template->submitted_at);

        Event::assertDispatched(TemplateDiajukanEvent::class);
    }

    /** @test */
    public function tidak_bisa_ajukan_template_yang_sudah_diajukan(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'diajukan',
        ]);

        $result = $this->service->ajukanTemplateKeProvider($this->klien->id, $template->id, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('tidak dapat diajukan', $result['pesan']);
    }

    /** @test */
    public function dapat_ajukan_ulang_template_ditolak(): void
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'ditolak',
            'body' => 'Body diperbaiki',
            'contoh_variabel' => [],
        ]);

        $this->mockProvider
            ->shouldReceive('submitTemplate')
            ->once()
            ->andReturn([
                'sukses' => true,
                'template_id' => 'provider_67890',
            ]);

        $result = $this->service->ajukanTemplateKeProvider($this->klien->id, $template->id, $this->owner->id);

        $this->assertTrue($result['sukses']);
        $this->assertEquals('diajukan', $result['template']->status);
    }

    /** @test */
    public function handle_provider_error_saat_ajukan(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'body' => 'Test',
            'contoh_variabel' => [],
        ]);

        $this->mockProvider
            ->shouldReceive('submitTemplate')
            ->once()
            ->andReturn([
                'sukses' => false,
                'error' => 'Provider error',
            ]);

        $result = $this->service->ajukanTemplateKeProvider($this->klien->id, $template->id, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('provider', strtolower($result['pesan']));

        // Template harus tetap draft
        $template->refresh();
        $this->assertEquals('draft', $template->status);
    }

    // ==================== ARSIPKAN TEMPLATE ====================

    /** @test */
    public function dapat_arsipkan_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
            'dipakai_count' => 10,
        ]);

        $result = $this->service->arsipkanTemplate($this->klien->id, $template->id, $this->owner->id);

        $this->assertTrue($result['sukses']);

        $template->refresh();
        $this->assertEquals('arsip', $template->status);
        $this->assertFalse($template->aktif);
    }

    /** @test */
    public function tidak_bisa_arsipkan_jika_campaign_aktif(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'template_id' => $template->id,
            'status' => 'berjalan',
        ]);

        $result = $this->service->arsipkanTemplate($this->klien->id, $template->id, $this->owner->id);

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('campaign aktif', $result['pesan']);
    }

    // ==================== SYNC STATUS ====================

    /** @test */
    public function dapat_sync_status_template_ke_disetujui(): void
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'diajukan',
            'provider_template_id' => 'provider_123',
        ]);

        $this->mockProvider
            ->shouldReceive('cekStatusTemplate')
            ->once()
            ->with('provider_123')
            ->andReturn([
                'sukses' => true,
                'status' => 'APPROVED',
            ]);

        $this->mockProvider
            ->shouldReceive('mapStatusDariProvider')
            ->with('APPROVED')
            ->andReturn('disetujui');

        $result = $this->service->syncStatusDariProvider($this->klien->id, $this->owner->id);

        $this->assertTrue($result['sukses']);
        $this->assertEquals(1, $result['synced']);

        $template->refresh();
        $this->assertEquals('disetujui', $template->status);
        $this->assertNotNull($template->approved_at);

        Event::assertDispatched(TemplateDisetujuiEvent::class);
    }

    /** @test */
    public function dapat_sync_status_template_ke_ditolak(): void
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'diajukan',
            'provider_template_id' => 'provider_456',
        ]);

        $this->mockProvider
            ->shouldReceive('cekStatusTemplate')
            ->once()
            ->with('provider_456')
            ->andReturn([
                'sukses' => true,
                'status' => 'REJECTED',
                'alasan' => 'Content violates policy',
            ]);

        $this->mockProvider
            ->shouldReceive('mapStatusDariProvider')
            ->with('REJECTED')
            ->andReturn('ditolak');

        $result = $this->service->syncStatusDariProvider($this->klien->id, $this->owner->id);

        $this->assertTrue($result['sukses']);

        $template->refresh();
        $this->assertEquals('ditolak', $template->status);
        $this->assertEquals('Content violates policy', $template->alasan_penolakan);

        Event::assertDispatched(TemplateDitolakEvent::class);
    }

    // ==================== MULTI-TENANT ====================

    /** @test */
    public function tidak_bisa_akses_template_klien_lain(): void
    {
        $klienLain = Klien::factory()->create();
        $templateKlienLain = TemplatePesan::factory()->create(['klien_id' => $klienLain->id]);

        // Coba akses detail
        $result = $this->service->ambilDetail($this->klien->id, $templateKlienLain->id);
        $this->assertFalse($result['sukses']);

        // Coba update
        $result = $this->service->updateTemplate(
            $this->klien->id,
            $templateKlienLain->id,
            ['isi_template' => 'Hack attempt'],
            $this->owner->id
        );
        $this->assertFalse($result['sukses']);

        // Coba hapus
        $result = $this->service->hapusTemplate($this->klien->id, $templateKlienLain->id, $this->owner->id);
        $this->assertFalse($result['sukses']);
    }

    /** @test */
    public function ambil_daftar_hanya_template_klien_sendiri(): void
    {
        // Template klien sendiri
        TemplatePesan::factory()->count(3)->create(['klien_id' => $this->klien->id, 'status' => 'draft']);

        // Template klien lain
        $klienLain = Klien::factory()->create();
        TemplatePesan::factory()->count(5)->create(['klien_id' => $klienLain->id]);

        $result = $this->service->ambilDaftar($this->klien->id, []);

        $this->assertTrue($result['sukses']);
        $this->assertEquals(3, $result['templates']->total());
    }

    // ==================== EXTRACT VARIABLE ====================

    /** @test */
    public function dapat_extract_variable_dari_template(): void
    {
        $result = $this->service->extractVariableDariTemplate('Halo {{1}}, promo {{2}} sampai {{3}}!');

        $this->assertEquals(['1', '2', '3'], $result['variabel']);
        $this->assertEquals(3, $result['jumlah']);
    }

    /** @test */
    public function extract_variable_tanpa_duplikat(): void
    {
        $result = $this->service->extractVariableDariTemplate('Halo {{1}}, {{1}} lagi, {{2}}!');

        $this->assertEquals(['1', '2'], $result['variabel']);
        $this->assertEquals(2, $result['jumlah']);
    }

    // ==================== VALIDASI TEMPLATE ====================

    /** @test */
    public function validasi_template_valid(): void
    {
        $result = $this->service->validasiTemplate([
            'nama_template' => 'promo_valid',
            'kategori' => 'marketing',
            'isi_template' => 'Halo {{1}}, promo {{2}}!',
            'variable' => ['1' => 'Budi', '2' => '50%'],
        ]);

        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function validasi_template_kategori_invalid(): void
    {
        $result = $this->service->validasiTemplate([
            'nama_template' => 'test',
            'kategori' => 'invalid_kategori',
            'isi_template' => 'Test',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('kategori', $result['errors']);
    }
}
