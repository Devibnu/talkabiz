<?php

namespace Tests\Feature\Api;

use App\Models\Klien;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class MobileApiAuthTest extends MobileApiTestCase
{
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
    public function mobile_login_validates_required_fields(): void
    {
        $this->postJson('/api/mobile/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validasi gagal')
            ->assertJsonStructure([
                'errors' => ['email', 'password', 'device_name'],
            ]);
    }

    /** @test */
    public function mobile_login_rejects_invalid_credentials_and_tracks_attempts(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
            'password' => Hash::make('secret123'),
            'failed_login_attempts' => 0,
        ]);

        $this->postJson('/api/mobile/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'PHPUnit Device',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Email atau password salah.')
            ->assertJsonPath('data.failed_attempts', 1)
            ->assertJsonPath('data.show_captcha', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'failed_login_attempts' => 1,
        ]);
    }

    /** @test */
    public function mobile_login_returns_locked_response_for_temporarily_locked_account(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
            'password' => Hash::make('secret123'),
            'locked_until' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'PHPUnit Device',
        ]);

        $response->assertStatus(423)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Akun sedang terkunci sementara.');

        $this->assertNotNull($response->json('data.locked_until'));
        $this->assertGreaterThan(0, (int) $response->json('data.seconds_remaining'));
    }

    /** @test */
    public function mobile_login_rate_limits_repeated_requests_from_same_ip(): void
    {
        config([
            'auth_security.rate_limit_per_minute' => 1,
            'auth_security.rate_limit_decay_seconds' => 60,
        ]);

        $ipAddress = '10.10.10.10';
        $rateLimitKey = 'mobile_login_ip:' . $ipAddress;
        RateLimiter::clear($rateLimitKey);

        $requestPayload = [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
            'device_name' => 'PHPUnit Device',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/mobile/auth/login', $requestPayload)
            ->assertStatus(401)
            ->assertJsonPath('success', false);

        $rateLimitedResponse = $this->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->postJson('/api/mobile/auth/login', $requestPayload);

        $rateLimitedResponse->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'Terlalu banyak request dari IP Anda.',
            (string) $rateLimitedResponse->json('message')
        );

        RateLimiter::clear($rateLimitKey);
    }

    /** @test */
    public function mobile_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/mobile/auth/me')->assertStatus(401);
        $this->getJson('/api/mobile/dashboard')->assertStatus(401);
        $this->getJson('/api/mobile/inbox')->assertStatus(401);
    }

    /** @test */
    public function mobile_write_endpoints_require_authentication(): void
    {
        $this->postJson('/api/mobile/contacts', [
            'name' => 'Unauthorized Contact',
            'phone' => '628123450001',
        ])->assertStatus(401);

        $this->postJson('/api/mobile/inbox/1/read')->assertStatus(401);

        $this->postJson('/api/mobile/inbox/1/send', [
            'message' => 'Unauthorized message',
        ])->assertStatus(401);

        $this->postJson('/api/mobile/auth/logout')->assertStatus(401);
    }
}