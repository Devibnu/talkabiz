<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\TemplatePesan;
use App\Models\Klien;
use App\Models\Pengguna;
use App\Services\WhatsAppTemplateProvider;
use App\Exceptions\WhatsApp\GupshupApiException;
use App\Exceptions\WhatsApp\TemplateSubmissionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * WhatsAppTemplateProviderTest
 * 
 * Unit test untuk WhatsAppTemplateProvider dengan Http::fake()
 * 
 * @author TalkaBiz Team
 */
class WhatsAppTemplateProviderTest extends TestCase
{
    use RefreshDatabase;

    protected WhatsAppTemplateProvider $provider;
    protected Klien $klien;
    protected Pengguna $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup config untuk testing
        Config::set('whatsapp.provider', 'gupshup');
        Config::set('whatsapp.api_key', 'test-api-key-12345');
        Config::set('whatsapp.app_name', 'test-app');
        Config::set('whatsapp.base_url', 'https://api.gupshup.io/wa/api/v1');
        Config::set('whatsapp.timeout', 30);

        // Create provider dengan mock mode disabled untuk test real HTTP
        $this->provider = new WhatsAppTemplateProvider();
        $this->provider->setMockMode(false);

        // Create test data
        $this->klien = Klien::factory()->create();
        $this->user = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
        ]);
    }

    // ==================== TEST SUBMIT TEMPLATE SUKSES ====================

    /** @test */
    public function submit_template_sukses_ke_gupshup()
    {
        // Arrange - Fake HTTP response
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'status' => 'success',
                'templateId' => 'gup_template_123',
                'message' => 'Template submitted successfully',
            ], 200),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'dibuat_oleh' => $this->user->id,
            'nama_template' => 'promo_test',
            'kategori' => 'marketing',
            'bahasa' => 'id',
            'body' => 'Halo {{1}}, ada promo spesial untuk Anda!',
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        // Act
        $result = $this->provider->submitTemplate($template);

        // Assert
        $this->assertTrue($result['sukses']);
        $this->assertEquals('gup_template_123', $result['template_id']);
        $this->assertArrayHasKey('payload', $result);
        $this->assertArrayHasKey('response', $result);

        // Verify HTTP request was made
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.gupshup.io/wa/api/v1/template/create'
                && $request->hasHeader('apikey', 'test-api-key-12345');
        });
    }

    /** @test */
    public function submit_template_dengan_header_dan_buttons()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'status' => 'success',
                'templateId' => 'gup_template_with_media',
                'message' => 'Template submitted successfully',
            ], 200),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'dibuat_oleh' => $this->user->id,
            'nama_template' => 'promo_image',
            'kategori' => 'marketing',
            'bahasa' => 'id',
            'header' => 'Header text',
            'header_type' => TemplatePesan::HEADER_IMAGE,
            'header_media_url' => 'https://example.com/image.jpg',
            'body' => 'Halo {{1}}, lihat promo ini!',
            'footer' => 'Powered by TalkaBiz',
            'buttons' => [
                ['type' => 'quick_reply', 'text' => 'Ya, tertarik'],
                ['type' => 'url', 'text' => 'Lihat Detail', 'url' => 'https://example.com'],
            ],
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        $result = $this->provider->submitTemplate($template);

        $this->assertTrue($result['sukses']);
        $this->assertEquals('gup_template_with_media', $result['template_id']);
        
        // Verify payload contains header and buttons
        $payload = $result['payload'];
        $this->assertEquals('Header text', $payload['header']);
        $this->assertEquals('IMAGE', $payload['headerType']);
        $this->assertNotEmpty($payload['buttons']);
    }

    // ==================== TEST SUBMIT TEMPLATE GAGAL ====================

    /** @test */
    public function submit_template_gagal_401_unauthorized()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'message' => 'Invalid API key',
            ], 401),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        $this->expectException(GupshupApiException::class);
        $this->expectExceptionMessage('API Key tidak valid atau expired');

        $this->provider->submitTemplate($template);
    }

    /** @test */
    public function submit_template_gagal_400_validation_error()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'message' => 'Template name already exists',
                'error' => 'DUPLICATE_TEMPLATE',
            ], 400),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        $this->expectException(GupshupApiException::class);
        $this->expectExceptionMessage('Template name already exists');

        $this->provider->submitTemplate($template);
    }

    /** @test */
    public function submit_template_gagal_429_rate_limited()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        $this->expectException(GupshupApiException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->provider->submitTemplate($template);
    }

    // ==================== TEST VALIDASI STATUS ====================

    /** @test */
    public function submit_template_gagal_jika_status_bukan_draft()
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DIAJUKAN, // bukan draft
        ]);

        $this->expectException(TemplateSubmissionException::class);
        $this->expectExceptionMessage("Template dengan status 'diajukan' tidak dapat diajukan");

        $this->provider->submitTemplate($template);
    }

    /** @test */
    public function submit_template_gagal_jika_status_disetujui()
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DISETUJUI,
        ]);

        $this->expectException(TemplateSubmissionException::class);
        $this->expectExceptionMessage("Template dengan status 'disetujui' tidak dapat diajukan");

        $this->provider->submitTemplate($template);
    }

    /** @test */
    public function submit_template_gagal_jika_body_kosong()
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DRAFT,
            'body' => '', // kosong
        ]);

        $this->expectException(TemplateSubmissionException::class);
        $this->expectExceptionMessage('Validasi template gagal');

        $this->provider->submitTemplate($template);
    }

    // ==================== TEST MAPPING KATEGORI ====================

    /** @test */
    public function map_kategori_ke_gupshup_benar()
    {
        $this->assertEquals('MARKETING', $this->provider->mapKategoriKeGupshup('marketing'));
        $this->assertEquals('UTILITY', $this->provider->mapKategoriKeGupshup('utility'));
        $this->assertEquals('AUTHENTICATION', $this->provider->mapKategoriKeGupshup('authentication'));
        $this->assertEquals('MARKETING', $this->provider->mapKategoriKeGupshup('unknown')); // default
    }

    // ==================== TEST MAP STATUS DARI PROVIDER ====================

    /** @test */
    public function map_status_dari_provider_benar()
    {
        // APPROVED -> disetujui
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, 
            $this->provider->mapStatusDariProvider('APPROVED'));
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, 
            $this->provider->mapStatusDariProvider('ACTIVE'));

        // REJECTED -> ditolak
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, 
            $this->provider->mapStatusDariProvider('REJECTED'));
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, 
            $this->provider->mapStatusDariProvider('DISABLED'));

        // PENDING -> diajukan
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, 
            $this->provider->mapStatusDariProvider('PENDING'));
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, 
            $this->provider->mapStatusDariProvider('IN_REVIEW'));
    }

    // ==================== TEST BUILD PAYLOAD ====================

    /** @test */
    public function build_gupshup_payload_dengan_semua_field()
    {
        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'notifikasi_order',
            'kategori' => 'utility',
            'bahasa' => 'id',
            'body' => 'Pesanan {{1}} sudah dikirim ke {{2}}',
            'header' => 'Update Pesanan',
            'header_type' => TemplatePesan::HEADER_TEXT,
            'footer' => 'TalkaBiz',
            'buttons' => [['type' => 'quick_reply', 'text' => 'Lacak']],
            'contoh_variabel' => ['1' => 'ORD-123', '2' => 'Jl. Sudirman'],
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        $payload = $this->provider->buildGupshupPayload($template);

        $this->assertEquals('test-app', $payload['appId']);
        $this->assertEquals('notifikasi_order', $payload['elementName']);
        $this->assertEquals('id', $payload['languageCode']);
        $this->assertEquals('UTILITY', $payload['category']);
        $this->assertEquals('TEXT', $payload['templateType']);
        $this->assertStringContainsString('Pesanan {{1}}', $payload['content']);
        $this->assertEquals('Update Pesanan', $payload['header']);
        $this->assertEquals('TEXT', $payload['headerType']);
        $this->assertEquals('TalkaBiz', $payload['footer']);
        $this->assertArrayHasKey('buttons', $payload);
        $this->assertArrayHasKey('example', $payload);
    }

    // ==================== TEST CEK STATUS TEMPLATE ====================

    /** @test */
    public function cek_status_template_sukses()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/list/test-app' => Http::response([
                'templates' => [
                    [
                        'id' => 'gup_template_123',
                        'elementName' => 'promo_test',
                        'status' => 'APPROVED',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->provider->cekStatusTemplate('gup_template_123');

        $this->assertTrue($result['sukses']);
        $this->assertEquals('APPROVED', $result['status']);
    }

    /** @test */
    public function cek_status_template_tidak_ditemukan()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/list/test-app' => Http::response([
                'templates' => [],
            ], 200),
        ]);

        $result = $this->provider->cekStatusTemplate('template_tidak_ada');

        $this->assertFalse($result['sukses']);
        $this->assertStringContainsString('tidak ditemukan', $result['error']);
    }

    // ==================== TEST HAPUS TEMPLATE ====================

    /** @test */
    public function hapus_template_sukses()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/test-app/*' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $result = $this->provider->hapusTemplate('gup_template_123');

        $this->assertTrue($result['sukses']);
    }

    /** @test */
    public function hapus_template_gagal()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/test-app/*' => Http::response([
                'message' => 'Template not found',
            ], 404),
        ]);

        $result = $this->provider->hapusTemplate('template_tidak_ada');

        $this->assertFalse($result['sukses']);
    }

    // ==================== TEST IS CONFIGURED ====================

    /** @test */
    public function is_configured_true_jika_api_key_dan_app_name_ada()
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    /** @test */
    public function is_configured_false_jika_api_key_kosong()
    {
        Config::set('whatsapp.api_key', '');
        
        $provider = new WhatsAppTemplateProvider();
        
        $this->assertFalse($provider->isConfigured());
    }

    // ==================== TEST LOGGING (Anti-Boncos) ====================

    /** @test */
    public function submit_template_log_payload_dan_response()
    {
        Http::fake([
            'https://api.gupshup.io/wa/api/v1/template/create' => Http::response([
                'status' => 'success',
                'templateId' => 'gup_123',
            ], 200),
        ]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'status' => TemplatePesan::STATUS_DRAFT,
        ]);

        // Spy on Log facade
        Log::shouldReceive('info')
            ->atLeast()
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'WhatsAppTemplateProvider');
            });

        $this->provider->submitTemplate($template);
    }
}
