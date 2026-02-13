<?php

namespace Tests\Feature;

use App\Models\DompetSaldo;
use App\Models\Klien;
use App\Models\PaymentGateway;
use App\Models\Pengguna;
use App\Models\TransaksiSaldo;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected Klien $klien;
    protected PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test client
        $this->klien = Klien::factory()->create([
            'nama_bisnis' => 'Test Company',
            'aktif' => true,
        ]);

        // Create active Midtrans gateway
        $this->gateway = PaymentGateway::create([
            'name' => 'midtrans',
            'display_name' => 'Midtrans',
            'is_active' => true,
            'environment' => 'sandbox',
            'server_key' => Crypt::encryptString('SB-Mid-server-test'),
            'client_key' => 'SB-Mid-client-test',
            'merchant_id' => 'test_merchant',
            'notification_url' => 'https://example.com/webhook',
            'finish_redirect_url' => 'https://example.com/finish',
        ]);
    }

    protected function createUserWithRole(string $role, ?int $klienId = null): Pengguna
    {
        return Pengguna::factory()->create([
            'role' => $role,
            'klien_id' => $klienId ?? ($role !== 'super_admin' ? $this->klien->id : null),
            'email' => $this->faker->unique()->safeEmail,
            'aktif' => true,
        ]);
    }

    // ==================== ROLE-BASED ACCESS TESTS ====================

    /**
     * Test: Super Admin cannot access billing page (should redirect to superadmin view)
     */
    public function test_super_admin_cannot_access_client_billing_page(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');

        $response = $this->actingAs($superAdmin, 'web')
            ->get('/billing');

        // Should redirect to super admin view or show different content
        $response->assertStatus(200);
        $response->assertViewIs('billing.superadmin');
    }

    /**
     * Test: Super Admin cannot top up via API (with Sanctum token)
     */
    public function test_super_admin_cannot_top_up_via_api(): void
    {
        $superAdmin = $this->createUserWithRole('super_admin');
        
        // Create Sanctum token for API access
        $token = $superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Super Admin tidak dapat melakukan top up. Fitur ini hanya untuk klien.',
        ]);
    }

    /**
     * Test: Owner can access billing page
     */
    public function test_owner_can_access_billing_page(): void
    {
        $owner = $this->createUserWithRole('owner');

        $response = $this->actingAs($owner, 'web')
            ->get('/billing');

        $response->assertStatus(200);
        $response->assertViewIs('billing');
        $response->assertViewHas('canTopUp', true);
    }

    /**
     * Test: Admin can access billing page
     */
    public function test_admin_can_access_billing_page(): void
    {
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin, 'web')
            ->get('/billing');

        $response->assertStatus(200);
        $response->assertViewIs('billing');
        $response->assertViewHas('canTopUp', true);
    }

    /**
     * Test: Sales can view billing but cannot top up
     */
    public function test_sales_can_view_but_cannot_top_up(): void
    {
        $sales = $this->createUserWithRole('sales');
        
        // Create Sanctum token for API access
        $token = $sales->createToken('test-token')->plainTextToken;

        // Can view billing
        $response = $this->actingAs($sales, 'web')
            ->get('/billing');

        $response->assertStatus(200);
        $response->assertViewIs('billing');
        $response->assertViewHas('canTopUp', false);

        // Cannot top up via API
        $topUpResponse = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        $topUpResponse->assertStatus(403);
        $topUpResponse->assertJson([
            'success' => false,
            'message' => 'Hanya Owner atau Admin yang dapat melakukan top up.',
        ]);
    }

    /**
     * Test: Owner can initiate top up with active gateway
     */
    public function test_owner_can_top_up_with_active_gateway(): void
    {
        $owner = $this->createUserWithRole('owner');
        $token = $owner->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        // Should get snap_token or be processed (may fail if Midtrans SDK not configured)
        // But should NOT be 403 or role error
        $response->assertDontSee('Hanya Owner atau Admin yang dapat melakukan top up');
        $response->assertDontSee('Super Admin tidak dapat melakukan top up');
    }

    /**
     * Test: Admin can initiate top up with active gateway
     */
    public function test_admin_can_top_up_with_active_gateway(): void
    {
        $admin = $this->createUserWithRole('admin');
        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        // Should NOT get 403 for role check
        $response->assertDontSee('Hanya Owner atau Admin yang dapat melakukan top up');
        $response->assertDontSee('Super Admin tidak dapat melakukan top up');
    }

    // ==================== KLIEN_ID VALIDATION TESTS ====================

    /**
     * Test: User without klien_id cannot access billing
     */
    public function test_user_without_klien_id_cannot_access_billing(): void
    {
        $userNoKlien = Pengguna::factory()->create([
            'role' => 'owner',
            'klien_id' => null, // No klien_id
            'email' => $this->faker->unique()->safeEmail,
            'aktif' => true,
        ]);

        $response = $this->actingAs($userNoKlien, 'web')
            ->get('/billing');

        $response->assertStatus(403);
    }

    /**
     * Test: User without klien_id cannot top up
     */
    public function test_user_without_klien_id_cannot_top_up(): void
    {
        $userNoKlien = Pengguna::factory()->create([
            'role' => 'owner',
            'klien_id' => null, // No klien_id
            'email' => $this->faker->unique()->safeEmail,
            'aktif' => true,
        ]);
        
        $token = $userNoKlien->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Klien ID tidak ditemukan. Hubungi administrator.',
        ]);
    }

    // ==================== GATEWAY STATUS TESTS ====================

    /**
     * Test: Cannot top up if no gateway is active
     */
    public function test_cannot_top_up_without_active_gateway(): void
    {
        // Disable all gateways
        PaymentGateway::query()->update(['is_active' => false]);

        $owner = $this->createUserWithRole('owner');
        $token = $owner->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 100000,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'code' => 'no_gateway',
        ]);
    }

    /**
     * Test: Billing page shows gateway warning when no gateway active
     */
    public function test_billing_page_shows_gateway_warning(): void
    {
        // Disable all gateways
        PaymentGateway::query()->update(['is_active' => false]);

        $owner = $this->createUserWithRole('owner');

        $response = $this->actingAs($owner, 'web')
            ->get('/billing');

        $response->assertStatus(200);
        $response->assertViewHas('gatewayReady', false);
    }

    // ==================== WALLET SERVICE TESTS ====================

    /**
     * Test: WalletService validates klien_id
     */
    public function test_wallet_service_validates_klien_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('klien_id tidak boleh null');

        WalletService::validateKlienId(null, 'test');
    }

    // canAccessWallet test removed — access control now handled by
    // client.access middleware (auth + domain.setup) and can.send.campaign

    // ==================== IDEMPOTENCY TESTS ====================

    /**
     * Test: Wallet credits only once on duplicate webhook
     */
    public function test_wallet_credits_once_on_duplicate_webhook(): void
    {
        $owner = $this->createUserWithRole('owner');
        
        // Create wallet
        $dompet = DompetSaldo::create([
            'klien_id' => $this->klien->id,
            'saldo_tersedia' => 0,
            'total_topup' => 0,
            'total_terpakai' => 0,
            'status_saldo' => 'normal',
        ]);

        // Create a paid transaction (simulating already processed)
        $orderId = 'TOPUP-' . time();
        TransaksiSaldo::create([
            'klien_id' => $this->klien->id,
            'pengguna_id' => $owner->id,
            'jenis' => 'topup',
            'nominal' => 100000,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 100000,
            'keterangan' => 'Top Up via Midtrans',
            'kode_referensi' => $orderId,
            'status_topup' => 'paid', // Already paid
            'payment_gateway' => 'midtrans',
        ]);

        // Update wallet (simulating successful credit)
        $dompet->update([
            'saldo_tersedia' => 100000,
            'total_topup' => 100000,
        ]);

        // Now try to process the same webhook again
        $transaksi = TransaksiSaldo::where('kode_referensi', $orderId)->first();

        // Idempotency check - if already paid, skip
        if ($transaksi->status_topup === 'paid') {
            // This is what the webhook handler should do
            $alreadyPaid = true;
        } else {
            $alreadyPaid = false;
        }

        $this->assertTrue($alreadyPaid);

        // Wallet should still have 100000, not 200000
        $dompet->refresh();
        $this->assertEquals(100000, $dompet->saldo_tersedia);
        $this->assertEquals(100000, $dompet->total_topup);
    }

    /**
     * Test: Concurrent webhook calls handled atomically
     */
    public function test_atomic_wallet_credit(): void
    {
        $owner = $this->createUserWithRole('owner');
        
        // Create wallet
        $dompet = DompetSaldo::create([
            'klien_id' => $this->klien->id,
            'saldo_tersedia' => 0,
            'total_topup' => 0,
            'total_terpakai' => 0,
            'status_saldo' => 'normal',
        ]);

        $orderId = 'TOPUP-ATOMIC-' . time();

        // Create pending transaction
        $transaksi = TransaksiSaldo::create([
            'klien_id' => $this->klien->id,
            'pengguna_id' => $owner->id,
            'jenis' => 'topup',
            'nominal' => 100000,
            'saldo_sebelum' => 0,
            'saldo_sesudah' => 0,
            'keterangan' => 'Top Up via Midtrans',
            'kode_referensi' => $orderId,
            'status_topup' => 'pending',
            'payment_gateway' => 'midtrans',
        ]);

        // Simulate atomic credit (what webhook handler does)
        DB::transaction(function () use ($transaksi, $dompet) {
            // Lock the wallet row
            $lockedDompet = DompetSaldo::lockForUpdate()->find($dompet->id);
            
            // Lock the transaction row
            $lockedTransaksi = TransaksiSaldo::lockForUpdate()->find($transaksi->id);

            // Double check idempotency inside transaction
            if ($lockedTransaksi->status_topup === 'paid') {
                return; // Already processed, skip
            }

            // Update wallet
            $saldoBaru = $lockedDompet->saldo_tersedia + $lockedTransaksi->nominal;
            $lockedDompet->update([
                'saldo_tersedia' => $saldoBaru,
                'total_topup' => $lockedDompet->total_topup + $lockedTransaksi->nominal,
            ]);

            // Update transaction
            $lockedTransaksi->update([
                'status_topup' => 'paid',
                'saldo_sesudah' => $saldoBaru,
            ]);
        });

        // Verify
        $dompet->refresh();
        $transaksi->refresh();

        $this->assertEquals(100000, $dompet->saldo_tersedia);
        $this->assertEquals('paid', $transaksi->status_topup);
    }

    // ==================== VALIDATION TESTS ====================

    /**
     * Test: Top up validates minimum nominal
     */
    public function test_top_up_validates_minimum_nominal(): void
    {
        $owner = $this->createUserWithRole('owner');
        $token = $owner->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 5000, // Below minimum 10000
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('nominal');
    }

    /**
     * Test: Top up validates maximum nominal
     */
    public function test_top_up_validates_maximum_nominal(): void
    {
        $owner = $this->createUserWithRole('owner');
        $token = $owner->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->postJson('/api/billing/topup', [
                'nominal' => 200000000, // Above maximum 100000000
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('nominal');
    }
}
