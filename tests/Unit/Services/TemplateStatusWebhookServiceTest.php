<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\TemplatePesan;
use App\Models\Klien;
use App\Models\Pengguna;
use App\Services\TemplateStatusWebhookService;
use App\Events\TemplateDisetujuiEvent;
use App\Events\TemplateDitolakEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * TemplateStatusWebhookServiceTest
 * 
 * Unit test untuk TemplateStatusWebhookService
 * 
 * @author TalkaBiz Team
 */
class TemplateStatusWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TemplateStatusWebhookService $service;
    protected Klien $klien;
    protected Pengguna $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TemplateStatusWebhookService();
        $this->klien = Klien::factory()->create();
        $this->user = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
        ]);
    }

    // ==================== MAP STATUS ====================

    /** @test */
    public function map_status_approved_ke_disetujui()
    {
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $this->service->mapStatus('APPROVED'));
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $this->service->mapStatus('ACTIVE'));
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $this->service->mapStatus('approved')); // lowercase
    }

    /** @test */
    public function map_status_rejected_ke_ditolak()
    {
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $this->service->mapStatus('REJECTED'));
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $this->service->mapStatus('DISABLED'));
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $this->service->mapStatus('PAUSED'));
    }

    /** @test */
    public function map_status_pending_ke_diajukan()
    {
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $this->service->mapStatus('PENDING'));
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $this->service->mapStatus('IN_REVIEW'));
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $this->service->mapStatus('SUBMITTED'));
    }

    /** @test */
    public function map_status_unknown_ke_diajukan()
    {
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $this->service->mapStatus('UNKNOWN'));
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $this->service->mapStatus(''));
    }

    // ==================== HANDLE STATUS UPDATE ====================

    /** @test */
    public function handle_approved_update_status_dan_dispatch_event()
    {
        Event::fake([TemplateDisetujuiEvent::class]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_123',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_123',
                'name' => 'test',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertTrue($result['handled']);
        $this->assertStringContainsString('disetujui', $result['new_status']);

        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $template->status);

        Event::assertDispatched(TemplateDisetujuiEvent::class);
    }

    /** @test */
    public function handle_rejected_update_status_dan_simpan_alasan()
    {
        Event::fake([TemplateDitolakEvent::class]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_rejected',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $alasan = 'Policy violation';

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_rejected',
                'name' => 'test',
                'status' => 'REJECTED',
                'language' => 'id',
                'reason' => $alasan,
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertTrue($result['handled']);

        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $template->status);
        $this->assertEquals($alasan, $template->alasan_penolakan);

        Event::assertDispatched(TemplateDitolakEvent::class);
    }

    /** @test */
    public function handle_skip_jika_status_sudah_final()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_final',
            'status' => TemplatePesan::STATUS_DISETUJUI, // sudah final
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_final',
                'status' => 'APPROVED',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertFalse($result['handled']);
        $this->assertStringContainsString('final', $result['message']);

        Event::assertNotDispatched(TemplateDisetujuiEvent::class);
    }

    /** @test */
    public function handle_skip_jika_template_tidak_ditemukan()
    {
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_tidak_ada',
                'name' => 'tidak_ada',
                'status' => 'APPROVED',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertFalse($result['handled']);
        $this->assertStringContainsString('tidak ditemukan', $result['message']);
    }

    /** @test */
    public function handle_skip_jika_event_type_salah()
    {
        $payload = [
            'event' => 'MESSAGE_RECEIVED',
            'template' => [
                'id' => 'gup_123',
                'status' => 'APPROVED',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertFalse($result['handled']);
    }

    /** @test */
    public function handle_skip_jika_payload_tidak_lengkap()
    {
        $result1 = $this->service->handleStatusUpdate([]);
        $this->assertFalse($result1['handled']);

        $result2 = $this->service->handleStatusUpdate(['event' => 'TEMPLATE_STATUS_UPDATE']);
        $this->assertFalse($result2['handled']);
    }

    // ==================== FIND TEMPLATE ====================

    /** @test */
    public function find_template_by_provider_id()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'test_by_id',
            'provider_template_id' => 'gup_find_id',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_find_id',
                'name' => 'different_name', // nama berbeda, tapi ID sama
                'status' => 'APPROVED',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertTrue($result['handled']);
        $this->assertEquals($template->id, $result['template_id']);
    }

    /** @test */
    public function find_template_by_nama_bahasa_jika_provider_id_tidak_match()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'promo_cari',
            'bahasa' => 'en',
            'provider_template_id' => null, // tidak ada provider ID
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_new',
                'name' => 'promo_cari',
                'status' => 'APPROVED',
                'language' => 'en',
            ],
        ];

        $result = $this->service->handleStatusUpdate($payload);

        $this->assertTrue($result['handled']);
        $this->assertEquals($template->id, $result['template_id']);

        // Verify provider_template_id diupdate
        $template->refresh();
        $this->assertEquals('gup_new', $template->provider_template_id);
    }

    // ==================== SIGNATURE VALIDATION ====================

    /** @test */
    public function validate_signature_return_true_jika_kosong()
    {
        $this->assertTrue($this->service->validateSignature('payload', null));
        $this->assertTrue($this->service->validateSignature('payload', ''));
    }

    /** @test */
    public function validate_signature_return_true_jika_secret_tidak_dikonfigurasi()
    {
        config(['whatsapp.gupshup.webhook_secret' => null]);

        $this->assertTrue($this->service->validateSignature('payload', 'any_signature'));
    }
}
