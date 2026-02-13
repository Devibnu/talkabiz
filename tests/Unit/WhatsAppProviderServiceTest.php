<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\WhatsAppProviderService;
use App\Models\Klien;
use App\Models\Pengguna;
use App\Models\DompetSaldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class WhatsAppProviderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WhatsAppProviderService $waService;
    protected Klien $klien;
    protected Pengguna $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->waService = new WhatsAppProviderService();

        // Setup test data
        $this->klien = Klien::factory()->create([
            'no_whatsapp' => '6281234567890',
            'status' => 'aktif',
        ]);

        $this->admin = Pengguna::factory()->create([
            'klien_id' => $this->klien->id,
            'role' => 'admin',
        ]);

        DompetSaldo::factory()->create([
            'klien_id' => $this->klien->id,
            'saldo_tersedia' => 100000,
        ]);
    }

    // ==================== TEST NORMALIZE PHONE ====================

    /**
     * @test
     */
    public function normalize_phone_dari_awalan_0(): void
    {
        $result = $this->waService->normalizePhone('081234567890');
        $this->assertEquals('6281234567890', $result);
    }

    /**
     * @test
     */
    public function normalize_phone_sudah_format_62(): void
    {
        $result = $this->waService->normalizePhone('6281234567890');
        $this->assertEquals('6281234567890', $result);
    }

    /**
     * @test
     */
    public function normalize_phone_dengan_karakter_khusus(): void
    {
        $result = $this->waService->normalizePhone('+62 812-3456-7890');
        $this->assertEquals('6281234567890', $result);
    }

    /**
     * @test
     */
    public function normalize_phone_tanpa_awalan(): void
    {
        $result = $this->waService->normalizePhone('81234567890');
        $this->assertEquals('6281234567890', $result);
    }

    // ==================== TEST VALIDATE PHONE ====================

    /**
     * @test
     */
    public function validate_phone_valid(): void
    {
        $this->assertTrue($this->waService->isValidPhone('081234567890'));
        $this->assertTrue($this->waService->isValidPhone('6281234567890'));
        $this->assertTrue($this->waService->isValidPhone('+6281234567890'));
    }

    /**
     * @test
     */
    public function validate_phone_invalid_terlalu_pendek(): void
    {
        $this->assertFalse($this->waService->isValidPhone('08123456'));
    }

    /**
     * @test
     */
    public function validate_phone_invalid_terlalu_panjang(): void
    {
        $this->assertFalse($this->waService->isValidPhone('081234567890123456'));
    }

    // ==================== TEST SEND TEXT (MOCKED) ====================

    /**
     * @test
     */
    public function send_text_sukses(): void
    {
        // Mock HTTP response dari Gupshup
        Http::fake([
            'api.gupshup.io/*' => Http::response([
                'status' => 'submitted',
                'messageId' => 'gBGGFlA5FpafAgkOuJbRq123',
            ], 200),
        ]);

        $result = $this->waService->sendText(
            '081234567890',
            'Hello, ini pesan test!',
            $this->klien->id,
            $this->admin->id
        );

        $this->assertTrue($result['sukses']);
        $this->assertEquals('gBGGFlA5FpafAgkOuJbRq123', $result['message_id']);
        $this->assertEquals(WhatsAppProviderService::STATUS_SENT, $result['status']);
    }

    /**
     * @test
     */
    public function send_text_gagal_invalid_number(): void
    {
        Http::fake([
            'api.gupshup.io/*' => Http::response([
                'status' => 'error',
                'code' => '1001',
                'message' => 'Invalid destination number',
            ], 400),
        ]);

        $result = $this->waService->sendText(
            '081234567890',
            'Hello!',
            $this->klien->id
        );

        $this->assertFalse($result['sukses']);
        $this->assertEquals(WhatsAppProviderService::ERROR_INVALID_NUMBER, $result['error']);
    }

    /**
     * @test
     */
    public function send_text_gagal_rate_limit(): void
    {
        Http::fake([
            'api.gupshup.io/*' => Http::response([
                'status' => 'error',
                'code' => '429',
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);

        $result = $this->waService->sendText(
            '081234567890',
            'Hello!',
            $this->klien->id
        );

        $this->assertFalse($result['sukses']);
        $this->assertEquals(WhatsAppProviderService::ERROR_RATE_LIMIT, $result['error']);
    }

    // ==================== TEST SEND TEMPLATE ====================

    /**
     * @test
     */
    public function send_template_sukses(): void
    {
        Http::fake([
            'api.gupshup.io/*' => Http::response([
                'status' => 'submitted',
                'messageId' => 'template-msg-123',
            ], 200),
        ]);

        $result = $this->waService->sendTemplate(
            '081234567890',
            'promo_template_001',
            ['Budi', '50%', 'Januari'],
            $this->klien->id
        );

        $this->assertTrue($result['sukses']);
        $this->assertEquals('template-msg-123', $result['message_id']);
    }

    // ==================== TEST SEND MEDIA ====================

    /**
     * @test
     */
    public function send_media_image_sukses(): void
    {
        Http::fake([
            'api.gupshup.io/*' => Http::response([
                'status' => 'submitted',
                'messageId' => 'media-msg-123',
            ], 200),
        ]);

        $result = $this->waService->sendMedia(
            '081234567890',
            'image',
            'https://example.com/promo.jpg',
            'Promo spesial!',
            null,
            $this->klien->id
        );

        $this->assertTrue($result['sukses']);
        $this->assertEquals('media-msg-123', $result['message_id']);
    }

    // ==================== TEST WEBHOOK SIGNATURE ====================

    /**
     * @test
     */
    public function validate_webhook_signature_tanpa_secret(): void
    {
        // Tanpa secret, harusnya return true (dev mode)
        config(['whatsapp.gupshup.webhook_secret' => '']);
        
        $waService = new WhatsAppProviderService();
        $result = $waService->validateWebhookSignature('{"test": true}', 'any-signature');
        
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function validate_webhook_signature_valid(): void
    {
        $secret = 'test-secret-123';
        config(['whatsapp.gupshup.webhook_secret' => $secret]);
        
        $payload = '{"type": "message", "payload": {}}';
        $validSignature = hash_hmac('sha256', $payload, $secret);
        
        $waService = new WhatsAppProviderService();
        $result = $waService->validateWebhookSignature($payload, $validSignature);
        
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function validate_webhook_signature_invalid(): void
    {
        $secret = 'test-secret-123';
        config(['whatsapp.gupshup.webhook_secret' => $secret]);
        
        $waService = new WhatsAppProviderService();
        $result = $waService->validateWebhookSignature('{"test": true}', 'invalid-signature');
        
        $this->assertFalse($result);
    }

    // ==================== TEST CONFIG ====================

    /**
     * @test
     */
    public function is_configured_false_tanpa_api_key(): void
    {
        config(['whatsapp.gupshup.api_key' => '']);
        
        $waService = new WhatsAppProviderService();
        $this->assertFalse($waService->isConfigured());
    }

    /**
     * @test
     */
    public function get_provider_default_gupshup(): void
    {
        $this->assertEquals('gupshup', $this->waService->getProvider());
    }
}
