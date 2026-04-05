<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\InboxController;
use App\Models\Klien;
use App\Models\Kontak;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Models\Pengguna;
use App\Models\User;
use App\Models\Wallet;
use App\Services\RevenueGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MobileApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** @test */
    public function mobile_login_returns_token_and_user_profile(): void
    {
        $klien = Klien::factory()->create([
            'nama_perusahaan' => 'Talkabiz Test Store',
        ]);

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'PHPUnit Device',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.business_name', 'Talkabiz Test Store');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** @test */
    public function authenticated_user_can_access_core_mobile_endpoints(): void
    {
        $klien = Klien::factory()->create([
            'nama_perusahaan' => 'Mobile Smoke Biz',
        ]);

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
            'messages_sent_daily' => 7,
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'balance' => 125000,
            'total_topup' => 125000,
            'total_spent' => 0,
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        Kontak::create([
            'klien_id' => $klien->id,
            'nama' => 'Budi Mobile',
            'no_telepon' => '6281234567890',
            'email' => 'budi@example.com',
            'tags' => ['lead'],
            'source' => Kontak::SOURCE_API,
        ]);

        $conversation = PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Inbox Mobile',
            'no_whatsapp' => '628777000111',
            'ditangani_oleh' => null,
            'status' => 'aktif',
            'pesan_terakhir' => 'Halo dari mobile',
            'pesan_belum_dibaca' => 1,
        ]);

        PesanInbox::factory()->create([
            'percakapan_id' => $conversation->id,
            'klien_id' => $klien->id,
            'arah' => 'masuk',
            'tipe' => 'teks',
            'isi_pesan' => 'Halo dari mobile',
            'status' => 'delivered',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);

        $this->getJson('/api/mobile/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.wallet.balance', 125000)
            ->assertJsonPath('data.stats.messages_today', 7)
            ->assertJsonPath('data.stats.contacts_total', 1);

        $this->getJson('/api/mobile/contacts')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Budi Mobile')
            ->assertJsonPath('data.0.phone', '6281234567890');

        $this->getJson('/api/mobile/inbox')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.contact_name', 'Inbox Mobile');

        $this->getJson("/api/mobile/inbox/{$conversation->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.contact_name', 'Inbox Mobile')
            ->assertJsonPath('data.messages.0.content', 'Halo dari mobile');

        $this->postJson('/api/mobile/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function authenticated_user_can_create_update_and_delete_mobile_contacts(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/mobile/contacts', [
            'name' => 'Kontak Baru',
            'phone' => '628123450000',
            'email' => 'kontak@example.com',
            'tags' => ['vip', 'reseller'],
            'notes' => 'Catatan kontak mobile',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Kontak Baru')
            ->assertJsonPath('data.phone', '628123450000');

        $contactId = $createResponse->json('data.id');

        $this->assertDatabaseHas('kontak', [
            'id' => $contactId,
            'klien_id' => $klien->id,
            'nama' => 'Kontak Baru',
            'no_telepon' => '628123450000',
            'source' => Kontak::SOURCE_API,
        ]);

        $this->putJson("/api/mobile/contacts/{$contactId}", [
            'name' => 'Kontak Update',
            'notes' => 'Catatan diperbarui',
            'tags' => ['warm'],
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Kontak Update')
            ->assertJsonPath('data.notes', 'Catatan diperbarui')
            ->assertJsonPath('data.tags.0', 'warm');

        $this->getJson("/api/mobile/contacts/{$contactId}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Kontak Update');

        $this->deleteJson("/api/mobile/contacts/{$contactId}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('kontak', [
            'id' => $contactId,
        ]);
    }

    /** @test */
    public function authenticated_user_can_mark_mobile_inbox_conversation_as_read(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $conversation = PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'pesan_belum_dibaca' => 2,
            'status' => 'belum_dibaca',
        ]);

        PesanInbox::factory()->count(2)->create([
            'percakapan_id' => $conversation->id,
            'klien_id' => $klien->id,
            'arah' => 'masuk',
            'dibaca_sales' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/inbox/{$conversation->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('percakapan_inbox', [
            'id' => $conversation->id,
            'pesan_belum_dibaca' => 0,
        ]);

        $this->assertDatabaseMissing('pesan_inbox', [
            'percakapan_id' => $conversation->id,
            'arah' => 'masuk',
            'dibaca_sales' => false,
        ]);
    }

    /** @test */
    public function authenticated_user_can_search_mobile_inbox_conversations(): void
    {
        $klien = Klien::factory()->create();
        $otherKlien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $matchedConversation = PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Target Buyer',
            'no_whatsapp' => '628111111111',
            'pesan_terakhir' => 'Halo keyword mobile',
            'status' => 'aktif',
        ]);

        PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Random Contact',
            'no_whatsapp' => '628222222222',
            'pesan_terakhir' => 'Pesan lain',
            'status' => 'aktif',
        ]);

        PercakapanInbox::factory()->create([
            'klien_id' => $otherKlien->id,
            'nama_customer' => 'Target Buyer External',
            'no_whatsapp' => '628333333333',
            'pesan_terakhir' => 'Halo keyword mobile',
            'status' => 'aktif',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/inbox?search=keyword%20mobile')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $matchedConversation->id)
            ->assertJsonPath('data.0.contact_name', 'Target Buyer');
    }

    /** @test */
    public function authenticated_user_can_filter_mobile_inbox_by_status_and_limit_results(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Aktif Pertama',
            'status' => 'aktif',
            'waktu_pesan_terakhir' => now()->subMinute(),
        ]);

        PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Aktif Kedua',
            'status' => 'aktif',
            'waktu_pesan_terakhir' => now(),
        ]);

        PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'nama_customer' => 'Selesai Lama',
            'status' => 'selesai',
            'waktu_pesan_terakhir' => now()->subMinutes(2),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/inbox?status=aktif&per_page=1');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact_name', 'Aktif Kedua')
            ->assertJsonPath('data.0.status', 'aktif');
    }

    /** @test */
    public function mobile_inbox_detail_returns_not_found_for_missing_conversation(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/inbox/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Percakapan tidak ditemukan');
    }

    /** @test */
    public function mobile_inbox_list_caps_per_page_at_one_hundred(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        PercakapanInbox::factory()->count(105)->create([
            'klien_id' => $klien->id,
            'status' => 'aktif',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/inbox?per_page=999')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 105)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(100, 'data');
    }

    /** @test */
    public function mobile_inbox_send_requires_message_payload(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/inbox/123/send', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validasi gagal');
    }

    /** @test */
    public function mobile_inbox_send_delegates_to_legacy_controller_and_normalizes_response(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $legacyInboxController = Mockery::mock(InboxController::class);
        $legacyInboxController->shouldReceive('kirimPesan')
            ->once()
            ->withArgs(function (int $percakapanId, Request $request) {
                return $percakapanId === 77
                    && $request->input('message') === 'Halo dari mobile send'
                    && $request->input('tipe') === 'teks'
                    && $request->input('isi_pesan') === 'Halo dari mobile send';
            })
            ->andReturn(response()->json([
                'sukses' => true,
                'pesan' => 'Pesan berhasil diproses',
            ], 200));

        $this->app->instance(InboxController::class, $legacyInboxController);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/inbox/77/send', [
            'message' => 'Halo dari mobile send',
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesan berhasil diproses')
            ->assertJsonPath('data.status', 'queued');
    }

    /** @test */
    public function mobile_inbox_send_returns_not_found_for_conversation_outside_user_tenant(): void
    {
        $userKlien = Klien::factory()->create();
        $otherKlien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $userKlien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $otherConversation = PercakapanInbox::factory()->create([
            'klien_id' => $otherKlien->id,
            'ditangani_oleh' => null,
            'status' => 'aktif',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/inbox/{$otherConversation->id}/send", [
            'message' => 'Harus gagal beda tenant',
        ])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Percakapan tidak ditemukan')
            ->assertJsonPath('data.status', 'failed');
    }

    /** @test */
    public function mobile_inbox_send_returns_forbidden_when_conversation_is_not_assigned_to_user(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $conversation = PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'ditangani_oleh' => null,
            'status' => 'aktif',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/inbox/{$conversation->id}/send", [
            'message' => 'Harus gagal belum diambil',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Anda harus mengambil percakapan ini terlebih dahulu')
            ->assertJsonPath('data.status', 'failed');
    }

    /** @test */
    public function mobile_inbox_send_returns_topup_context_when_balance_is_insufficient(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Pengguna::factory()->owner()->create([
            'id' => $user->id,
            'klien_id' => $klien->id,
            'email' => 'legacy-handler-'.$user->id.'@example.test',
        ]);

        $conversation = PercakapanInbox::factory()->create([
            'klien_id' => $klien->id,
            'ditangani_oleh' => $user->id,
            'status' => 'aktif',
        ]);

        $revenueGuard = Mockery::mock(RevenueGuardService::class);
        $revenueGuard->shouldReceive('chargeAndExecute')
            ->once()
            ->withArgs(function (
                int $userId,
                int $messageCount,
                string $category,
                string $referenceType,
                int $referenceId,
                callable $dispatchCallable,
                array $costPreview
            ) use ($user) {
                return $userId === $user->id
                    && $messageCount === 1
                    && $category === 'utility'
                    && $referenceType === 'inbox_reply'
                    && $referenceId > 0
                    && is_callable($dispatchCallable)
                    && $costPreview === [];
            })
            ->andThrow(new \RuntimeException('Saldo tidak cukup untuk mengirim balasan.'));

        $this->app->instance(RevenueGuardService::class, $revenueGuard);

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/inbox/{$conversation->id}/send", [
            'message' => 'Butuh saldo',
        ])
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Saldo tidak cukup untuk mengirim balasan.')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'INSUFFICIENT_BALANCE')
            ->assertJsonPath('data.topup_url', route('billing'));
    }
}