<?php

namespace Tests\Feature\Api;

use App\Models\Klien;
use App\Models\Kontak;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class MobileApiContactsTest extends MobileApiTestCase
{
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
    public function mobile_contacts_validate_required_fields_on_create(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/contacts', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validasi gagal')
            ->assertJsonStructure([
                'errors' => ['name', 'phone'],
            ]);
    }

    /** @test */
    public function mobile_contacts_return_not_found_for_resources_outside_user_tenant(): void
    {
        $userKlien = Klien::factory()->create();
        $otherKlien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $userKlien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        $otherContact = Kontak::create([
            'klien_id' => $otherKlien->id,
            'nama' => 'Kontak Tenant Lain',
            'no_telepon' => '628555000111',
            'email' => 'other@example.com',
            'tags' => ['external'],
            'source' => Kontak::SOURCE_API,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/mobile/contacts/{$otherContact->id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Kontak tidak ditemukan');

        $this->putJson("/api/mobile/contacts/{$otherContact->id}", [
            'name' => 'Should Not Update',
        ])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Kontak tidak ditemukan');

        $this->deleteJson("/api/mobile/contacts/{$otherContact->id}")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Kontak tidak ditemukan');
    }

    /** @test */
    public function authenticated_user_can_search_and_filter_mobile_contacts(): void
    {
        $klien = Klien::factory()->create();
        $otherKlien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        Kontak::create([
            'klien_id' => $klien->id,
            'nama' => 'Target Contact',
            'no_telepon' => '628111222333',
            'email' => 'target@example.com',
            'tags' => ['vip', 'reseller'],
            'source' => Kontak::SOURCE_API,
        ]);

        Kontak::create([
            'klien_id' => $klien->id,
            'nama' => 'Other Contact',
            'no_telepon' => '628444555666',
            'email' => 'other@example.com',
            'tags' => ['cold'],
            'source' => Kontak::SOURCE_API,
        ]);

        Kontak::create([
            'klien_id' => $otherKlien->id,
            'nama' => 'External Target Contact',
            'no_telepon' => '628777888999',
            'email' => 'external@example.com',
            'tags' => ['vip'],
            'source' => Kontak::SOURCE_API,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/contacts?search=target@example.com')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Target Contact');

        $this->getJson('/api/mobile/contacts?tag=vip')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Target Contact');
    }

    /** @test */
    public function mobile_contacts_list_caps_per_page_at_one_hundred(): void
    {
        $klien = Klien::factory()->create();

        $user = User::factory()->create([
            'klien_id' => $klien->id,
            'role' => 'owner',
            'onboarding_complete' => true,
        ]);

        foreach (range(1, 105) as $index) {
            Kontak::create([
                'klien_id' => $klien->id,
                'nama' => 'Kontak ' . $index,
                'no_telepon' => '62812' . str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                'source' => Kontak::SOURCE_API,
            ]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/contacts?per_page=999')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 105)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(100, 'data');
    }
}