<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\TemplateDiajukanEvent;
use App\Models\TemplatePesan;
use App\Models\Kampanye;
use App\Models\Klien;
use App\Models\Pengguna;
use Mockery;
use App\Services\WhatsAppTemplateProvider;

/**
 * TemplateApiTest
 * 
 * Feature test untuk Template Management API endpoints.
 * 
 * ATURAN BISNIS YANG DITEST:
 * - Status transition (draft → diajukan → disetujui/ditolak)
 * - Role restriction (sales READ only, admin/owner CRUD)
 * - Template tidak bisa diedit setelah diajukan/disetujui
 * - Template tidak bisa dihapus jika dipakai campaign
 * 
 * @author TalkaBiz Team
 */
class TemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected Klien $klien;
    protected Pengguna $owner;
    protected Pengguna $admin;
    protected Pengguna $sales;
    protected $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock WhatsAppTemplateProvider
        $this->mockProvider = Mockery::mock(WhatsAppTemplateProvider::class);
        $this->app->instance(WhatsAppTemplateProvider::class, $this->mockProvider);

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

    // ==================== GET /api/templates ====================

    /** @test */
    public function semua_role_dapat_melihat_daftar_template(): void
    {
        TemplatePesan::factory()->count(5)->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        // Owner
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/templates');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'sukses',
                'data' => [
                    'data',
                    'current_page',
                    'total',
                ],
            ]);

        // Admin
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/templates');
        $response->assertStatus(200);

        // Sales
        $response = $this->actingAs($this->sales, 'sanctum')
            ->getJson('/api/templates');
        $response->assertStatus(200);
    }

    /** @test */
    public function dapat_filter_template_by_status(): void
    {
        TemplatePesan::factory()->count(3)->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);
        TemplatePesan::factory()->count(2)->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/templates?status=draft');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.total'));
    }

    /** @test */
    public function dapat_filter_template_by_kategori(): void
    {
        TemplatePesan::factory()->count(4)->create([
            'klien_id' => $this->klien->id,
            'kategori' => 'marketing',
            'status' => 'draft',
        ]);
        TemplatePesan::factory()->count(2)->create([
            'klien_id' => $this->klien->id,
            'kategori' => 'utility',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/templates?kategori=marketing');

        $response->assertStatus(200);
        $this->assertEquals(4, $response->json('data.total'));
    }

    /** @test */
    public function dapat_search_template(): void
    {
        TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'promo_ramadhan',
            'nama_tampilan' => 'Promo Ramadhan',
            'status' => 'draft',
        ]);
        TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'notif_pesanan',
            'nama_tampilan' => 'Notifikasi Pesanan',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/templates?search=ramadhan');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total'));
    }

    // ==================== GET /api/templates/{id} ====================

    /** @test */
    public function dapat_melihat_detail_template(): void
    {
        $template = TemplatePesan::factory()->create(['klien_id' => $this->klien->id]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson([
                'sukses' => true,
                'data' => [
                    'id' => $template->id,
                ],
            ]);
    }

    /** @test */
    public function tidak_bisa_melihat_template_klien_lain(): void
    {
        $klienLain = Klien::factory()->create();
        $templateLain = TemplatePesan::factory()->create(['klien_id' => $klienLain->id]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/templates/{$templateLain->id}");

        $response->assertStatus(404);
    }

    // ==================== POST /api/templates ====================

    /** @test */
    public function owner_dapat_membuat_template(): void
    {
        $data = [
            'nama_template' => 'promo_lebaran',
            'nama_tampilan' => 'Promo Lebaran',
            'kategori' => 'marketing',
            'bahasa' => 'id',
            'isi_template' => 'Halo {{1}}, promo {{2}}!',
            'variable' => ['1' => 'Nama', '2' => 'Diskon'],
        ];

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/templates', $data);

        $response->assertStatus(201)
            ->assertJson([
                'sukses' => true,
            ]);

        $this->assertDatabaseHas('template_pesan', [
            'klien_id' => $this->klien->id,
            'nama_template' => 'promo_lebaran',
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function admin_dapat_membuat_template(): void
    {
        $data = [
            'nama_template' => 'notif_order',
            'kategori' => 'utility',
            'bahasa' => 'id',
            'isi_template' => 'Pesanan {{1}} telah dikirim',
            'variable' => ['1' => 'ORDER-001'],
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/templates', $data);

        $response->assertStatus(201);
    }

    /** @test */
    public function sales_tidak_dapat_membuat_template(): void
    {
        $data = [
            'nama_template' => 'test_sales',
            'kategori' => 'marketing',
            'isi_template' => 'Test',
        ];

        $response = $this->actingAs($this->sales, 'sanctum')
            ->postJson('/api/templates', $data);

        $response->assertStatus(403);
    }

    /** @test */
    public function validasi_nama_template_required(): void
    {
        $data = [
            'kategori' => 'marketing',
            'isi_template' => 'Test body',
        ];

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/templates', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nama_template']);
    }

    // ==================== PUT /api/templates/{id} ====================

    /** @test */
    public function dapat_mengupdate_template_draft(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'isi_template' => 'Body baru diupdate',
            ]);

        $response->assertStatus(200)
            ->assertJson(['sukses' => true]);
    }

    /** @test */
    public function tidak_bisa_mengupdate_template_disetujui(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'isi_template' => 'Coba edit disetujui',
            ]);

        $response->assertStatus(422)
            ->assertJson(['sukses' => false]);
    }

    /** @test */
    public function tidak_bisa_mengupdate_template_diajukan(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'diajukan',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'isi_template' => 'Coba edit diajukan',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function dapat_mengupdate_template_ditolak(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'ditolak',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'isi_template' => 'Body diperbaiki',
            ]);

        $response->assertStatus(200)
            ->assertJson(['sukses' => true]);

        // Status harus kembali ke draft
        $template->refresh();
        $this->assertEquals('draft', $template->status);
    }

    /** @test */
    public function sales_tidak_dapat_update_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->sales, 'sanctum')
            ->putJson("/api/templates/{$template->id}", [
                'isi_template' => 'Test sales update',
            ]);

        $response->assertStatus(403);
    }

    // ==================== DELETE /api/templates/{id} ====================

    /** @test */
    public function owner_dapat_menghapus_template_draft(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'dipakai_count' => 0,
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJson(['sukses' => true]);

        // Template soft deleted
        $template->refresh();
        $this->assertFalse($template->aktif);
        $this->assertEquals('arsip', $template->status);
    }

    /** @test */
    public function sales_tidak_dapat_menghapus_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->sales, 'sanctum')
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function template_dipakai_campaign_tidak_bisa_dihapus(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
            'dipakai_count' => 0,
        ]);

        // Buat campaign aktif yang menggunakan template
        Kampanye::factory()->create([
            'klien_id' => $this->klien->id,
            'template_id' => $template->id,
            'status' => 'berjalan',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertStatus(409)
            ->assertJson(['sukses' => false]);

        // Template tidak berubah
        $template->refresh();
        $this->assertTrue($template->aktif);
    }

    /** @test */
    public function template_pernah_dipakai_tidak_bisa_dihapus(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
            'dipakai_count' => 5, // Pernah dipakai
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/templates/{$template->id}");

        $response->assertStatus(409);
    }

    // ==================== POST /api/templates/{id}/ajukan ====================

    /** @test */
    public function owner_dapat_ajukan_template(): void
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
                'template_id' => 'gupshup_12345',
            ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$template->id}/ajukan");

        $response->assertStatus(200)
            ->assertJson(['sukses' => true]);

        $template->refresh();
        $this->assertEquals('diajukan', $template->status);

        Event::assertDispatched(TemplateDiajukanEvent::class);
    }

    /** @test */
    public function sales_tidak_dapat_ajukan_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->sales, 'sanctum')
            ->postJson("/api/templates/{$template->id}/ajukan");

        $response->assertStatus(403);
    }

    /** @test */
    public function tidak_bisa_ajukan_template_yang_sudah_disetujui(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$template->id}/ajukan");

        $response->assertStatus(422);
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
                'template_id' => 'gupshup_67890',
            ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$template->id}/ajukan");

        $response->assertStatus(200);

        $template->refresh();
        $this->assertEquals('diajukan', $template->status);
    }

    // ==================== POST /api/templates/{id}/arsip ====================

    /** @test */
    public function owner_dapat_arsipkan_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$template->id}/arsip");

        $response->assertStatus(200)
            ->assertJson(['sukses' => true]);

        $template->refresh();
        $this->assertEquals('arsip', $template->status);
        $this->assertFalse($template->aktif);
    }

    /** @test */
    public function sales_tidak_dapat_arsipkan_template(): void
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => 'disetujui',
        ]);

        $response = $this->actingAs($this->sales, 'sanctum')
            ->postJson("/api/templates/{$template->id}/arsip");

        $response->assertStatus(403);
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

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$template->id}/arsip");

        $response->assertStatus(409);
    }

    // ==================== MULTI-TENANT ====================

    /** @test */
    public function tidak_bisa_akses_template_klien_lain(): void
    {
        $klienLain = Klien::factory()->create();
        $templateLain = TemplatePesan::factory()->create(['klien_id' => $klienLain->id]);

        // Coba lihat
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/templates/{$templateLain->id}");
        $response->assertStatus(404);

        // Coba update
        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/templates/{$templateLain->id}", ['isi_template' => 'hack']);
        $response->assertStatus(404);

        // Coba hapus
        $response = $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/templates/{$templateLain->id}");
        $response->assertStatus(404);

        // Coba ajukan - akan return 400 atau 404 karena not found
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/templates/{$templateLain->id}/ajukan");
        $this->assertTrue(in_array($response->status(), [400, 404]));
    }

    /** @test */
    public function daftar_template_hanya_milik_klien_sendiri(): void
    {
        // Template klien sendiri
        TemplatePesan::factory()->count(3)->create([
            'klien_id' => $this->klien->id,
            'status' => 'draft',
        ]);

        // Template klien lain
        $klienLain = Klien::factory()->create();
        TemplatePesan::factory()->count(5)->create(['klien_id' => $klienLain->id]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/templates');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.total'));
    }

    // ==================== UNAUTHENTICATED ====================

    /** @test */
    public function tidak_bisa_akses_tanpa_login(): void
    {
        $response = $this->getJson('/api/templates');
        $response->assertStatus(401);

        $response = $this->postJson('/api/templates', []);
        $response->assertStatus(401);

        $response = $this->postJson('/api/templates/sync-status');
        $response->assertStatus(401);
    }
}
