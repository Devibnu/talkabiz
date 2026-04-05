<?php

namespace Tests\Feature\Api;

use App\Models\Klien;
use App\Models\Kontak;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiSmokeTest extends TestCase
{
    use RefreshDatabase;

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
}