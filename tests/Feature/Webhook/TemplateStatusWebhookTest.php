<?php

namespace Tests\Feature\Webhook;

use Tests\TestCase;
use App\Models\TemplatePesan;
use App\Models\Klien;
use App\Models\Pengguna;
use App\Events\TemplateDisetujuiEvent;
use App\Events\TemplateDitolakEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * TemplateStatusWebhookTest
 * 
 * Feature test untuk webhook status template dari Gupshup/Meta.
 * 
 * Test Cases:
 * 1. Webhook APPROVED → status berubah ke disetujui
 * 2. Webhook REJECTED → alasan tersimpan
 * 3. Payload duplikat → tidak update ulang (idempotent)
 * 4. Template tidak ditemukan → aman (log only)
 * 5. Status sudah final → tidak diubah
 * 
 * @author TalkaBiz Team
 */
class TemplateStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Klien $klien;
    protected Pengguna $user;
    protected string $webhookUrl = '/api/webhook/whatsapp/template-status';

    protected function setUp(): void
    {
        parent::setUp();

        $this->klien = Klien::factory()->create();
        $this->user = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
        ]);
    }

    // ==================== WEBHOOK APPROVED ====================

    /** @test */
    public function webhook_approved_mengubah_status_ke_disetujui()
    {
        Event::fake([TemplateDisetujuiEvent::class]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'dibuat_oleh' => $this->user->id,
            'nama_template' => 'promo_januari',
            'bahasa' => 'id',
            'provider_template_id' => 'gup_template_123',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_template_123',
                'name' => 'promo_januari',
                'status' => 'APPROVED',
                'language' => 'id',
                'reason' => null,
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'handled' => true,
            ]);

        // Verify status berubah
        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $template->status);
        $this->assertNotNull($template->approved_at);

        // Verify event dispatched
        Event::assertDispatched(TemplateDisetujuiEvent::class, function ($event) use ($template) {
            return $event->template->id === $template->id;
        });
    }

    /** @test */
    public function webhook_approved_menyimpan_webhook_payload()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_abc',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_abc',
                'name' => 'test_template',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $this->postJson($this->webhookUrl, $payload);

        $template->refresh();
        $this->assertNotNull($template->provider_response);
        $this->assertArrayHasKey('webhook_received', $template->provider_response);
    }

    // ==================== WEBHOOK REJECTED ====================

    /** @test */
    public function webhook_rejected_menyimpan_alasan_penolakan()
    {
        Event::fake([TemplateDitolakEvent::class]);

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_rejected_tpl',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $alasan = 'Template content violates WhatsApp Business Policy';

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_rejected_tpl',
                'name' => 'promo_spam',
                'status' => 'REJECTED',
                'language' => 'id',
                'reason' => $alasan,
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'handled' => true,
            ]);

        // Verify status dan alasan tersimpan
        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $template->status);
        $this->assertEquals($alasan, $template->alasan_penolakan);
        $this->assertEquals($alasan, $template->catatan_reject);

        // Verify event dispatched
        Event::assertDispatched(TemplateDitolakEvent::class, function ($event) use ($template, $alasan) {
            return $event->template->id === $template->id
                && $event->alasan === $alasan;
        });
    }

    /** @test */
    public function webhook_rejected_tanpa_alasan_tetap_berhasil()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_rejected_no_reason',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_rejected_no_reason',
                'name' => 'template_test',
                'status' => 'REJECTED',
                'language' => 'id',
                'reason' => null, // tanpa alasan
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200);

        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DITOLAK, $template->status);
    }

    // ==================== IDEMPOTENCY ====================

    /** @test */
    public function webhook_duplikat_tidak_update_ulang_jika_sudah_disetujui()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_already_approved',
            'status' => TemplatePesan::STATUS_DISETUJUI, // sudah disetujui
            'approved_at' => now()->subDays(1),
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_already_approved',
                'name' => 'promo_lama',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'handled' => false,
                'message' => 'Template sudah dalam status final',
            ]);

        // Verify tidak ada event dispatched
        Event::assertNotDispatched(TemplateDisetujuiEvent::class);
    }

    /** @test */
    public function webhook_dengan_status_sama_tidak_trigger_event()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_pending_tpl',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_pending_tpl',
                'name' => 'test',
                'status' => 'PENDING', // PENDING = diajukan (sama)
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'handled' => false,
                'message' => 'Status tidak berubah',
            ]);

        Event::assertNotDispatched(TemplateDisetujuiEvent::class);
        Event::assertNotDispatched(TemplateDitolakEvent::class);
    }

    // ==================== TEMPLATE NOT FOUND ====================

    /** @test */
    public function webhook_template_tidak_ditemukan_tetap_return_200()
    {
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_tidak_ada',
                'name' => 'template_tidak_ada',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        // WAJIB return 200 agar Gupshup tidak retry
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'handled' => false,
                'message' => 'Template tidak ditemukan',
            ]);
    }

    // ==================== PAYLOAD VALIDATION ====================

    /** @test */
    public function webhook_dengan_event_type_salah_tidak_diproses()
    {
        $payload = [
            'event' => 'MESSAGE_RECEIVED', // bukan TEMPLATE_STATUS_UPDATE
            'template' => [
                'id' => 'gup_123',
                'status' => 'APPROVED',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'handled' => false,
            ]);
    }

    /** @test */
    public function webhook_tanpa_template_data_tidak_diproses()
    {
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            // tidak ada 'template' key
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'handled' => false,
            ]);
    }

    /** @test */
    public function webhook_tanpa_status_tidak_diproses()
    {
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_123',
                'name' => 'promo',
                // tidak ada 'status'
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson([
                'handled' => false,
            ]);
    }

    // ==================== LOOKUP BY NAME + LANGUAGE ====================

    /** @test */
    public function webhook_dapat_find_template_by_nama_dan_bahasa()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'nama_template' => 'notifikasi_order',
            'bahasa' => 'id',
            'provider_template_id' => null, // belum ada provider ID
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_new_id', // ID baru dari Gupshup
                'name' => 'notifikasi_order',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200)
            ->assertJson(['handled' => true]);

        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $template->status);
        $this->assertEquals('gup_new_id', $template->provider_template_id);
    }

    // ==================== STATUS PENDING ====================

    /** @test */
    public function webhook_pending_menjaga_status_diajukan()
    {
        Event::fake();

        $template = TemplatePesan::factory()->create([
            'klien_id' => $this->klien->id,
            'provider_template_id' => 'gup_pending',
            'status' => TemplatePesan::STATUS_DRAFT, // dari draft
        ]);

        // Ubah ke diajukan dulu (simulasi submit)
        $template->update(['status' => TemplatePesan::STATUS_DIAJUKAN]);

        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_pending',
                'name' => 'test',
                'status' => 'IN_REVIEW', // IN_REVIEW = diajukan
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        $response->assertStatus(200);

        $template->refresh();
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $template->status);
    }

    // ==================== MULTI-TENANT SAFETY ====================

    /** @test */
    public function webhook_hanya_update_template_yang_tepat_multi_tenant()
    {
        Event::fake();

        // Klien 1
        $klien1 = Klien::factory()->create();
        $template1 = TemplatePesan::factory()->create([
            'klien_id' => $klien1->id,
            'nama_template' => 'promo_sama',
            'bahasa' => 'id',
            'provider_template_id' => 'gup_klien1',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        // Klien 2 dengan nama template sama
        $klien2 = Klien::factory()->create();
        $template2 = TemplatePesan::factory()->create([
            'klien_id' => $klien2->id,
            'nama_template' => 'promo_sama',
            'bahasa' => 'id',
            'provider_template_id' => 'gup_klien2',
            'status' => TemplatePesan::STATUS_DIAJUKAN,
        ]);

        // Webhook untuk klien 1
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_klien1',
                'name' => 'promo_sama',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $this->postJson($this->webhookUrl, $payload);

        // Hanya template1 yang berubah
        $template1->refresh();
        $template2->refresh();

        $this->assertEquals(TemplatePesan::STATUS_DISETUJUI, $template1->status);
        $this->assertEquals(TemplatePesan::STATUS_DIAJUKAN, $template2->status); // tidak berubah
    }

    // ==================== ERROR HANDLING ====================

    /** @test */
    public function webhook_tetap_return_200_meski_terjadi_error()
    {
        // Payload yang valid tapi template tidak ada
        $payload = [
            'event' => 'TEMPLATE_STATUS_UPDATE',
            'template' => [
                'id' => 'gup_error_test',
                'name' => 'error_test',
                'status' => 'APPROVED',
                'language' => 'id',
            ],
        ];

        $response = $this->postJson($this->webhookUrl, $payload);

        // WAJIB return 200
        $response->assertStatus(200);
    }

    /** @test */
    public function webhook_kosong_tetap_return_200()
    {
        $response = $this->postJson($this->webhookUrl, []);

        // WAJIB return 200
        $response->assertStatus(200);
    }
}
