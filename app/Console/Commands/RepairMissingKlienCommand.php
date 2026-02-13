<?php

namespace App\Console\Commands;

use App\Models\DompetSaldo;
use App\Models\Klien;
use App\Models\User;
use App\Services\UserOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Repair Missing Klien Command
 * 
 * Fixes legacy users who don't have klien and/or wallet records.
 * 
 * SAFE TO RUN MULTIPLE TIMES:
 * - Checks existing records before creating
 * - Uses transactions for atomicity
 * - Logs all actions
 */
class RepairMissingKlienCommand extends Command
{
    protected $signature = 'repair:missing-klien 
                            {--user= : Repair specific user by ID or email}
                            {--dry-run : Show what would be done without making changes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Repair users missing klien and/or wallet records';

    public function handle(): int
    {
        $this->info('🔧 Repair Missing Klien & Wallet');
        $this->line('');

        $isDryRun = $this->option('dry-run');
        $specificUser = $this->option('user');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->line('');
        }

        // Get users to repair
        $users = $this->getUsersToRepair($specificUser);

        if ($users->isEmpty()) {
            $this->info('✅ No users need repair. All domain entities are complete.');
            return Command::SUCCESS;
        }

        $this->line("Found {$users->count()} user(s) needing repair:");
        $this->line('');

        // Display users table
        $this->displayUsersTable($users);

        if (!$isDryRun && !$this->option('force')) {
            if (!$this->confirm('Proceed with repair?')) {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        // Repair each user
        $repaired = 0;
        $failed = 0;

        foreach ($users as $user) {
            if ($isDryRun) {
                $this->line("  Would repair: {$user->email}");
                $repaired++;
                continue;
            }

            try {
                $this->repairUser($user);
                $this->info("  ✓ Repaired: {$user->email}");
                $repaired++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$user->email} - {$e->getMessage()}");
                Log::error('repair:missing-klien failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->line('');
        $this->info("Summary: {$repaired} repaired, {$failed} failed");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Get users that need repair.
     */
    protected function getUsersToRepair(?string $specificUser)
    {
        $query = User::query()
            ->whereNotIn('role', ['super_admin', 'superadmin'])
            ->where(function ($q) {
                // No klien_id
                $q->whereNull('klien_id')
                  ->orWhere('klien_id', 0)
                  // Or has klien but no wallet
                  ->orWhereHas('klien', function ($kq) {
                      $kq->whereDoesntHave('dompet');
                  });
            });

        if ($specificUser) {
            $query->where(function ($q) use ($specificUser) {
                $q->where('id', $specificUser)
                  ->orWhere('email', $specificUser);
            });
        }

        return $query->get();
    }

    /**
     * Display users in table format.
     */
    protected function displayUsersTable($users): void
    {
        $rows = $users->map(function ($user) {
            $hasKlien = !empty($user->klien_id) && $user->klien;
            $hasWallet = $hasKlien && $user->klien->dompet;

            return [
                $user->id,
                $user->email,
                $user->role,
                $hasKlien ? "✓ #{$user->klien_id}" : '✗ Missing',
                $hasWallet ? '✓ Exists' : '✗ Missing',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Email', 'Role', 'Klien', 'Wallet'],
            $rows
        );
    }

    /**
     * Repair a single user.
     */
    protected function repairUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            $klien = null;

            // Step 1: Create or get klien
            if (empty($user->klien_id) || !Klien::find($user->klien_id)) {
                $klien = $this->createKlienForUser($user);
                $user->update(['klien_id' => $klien->id]);
                
                Log::info('repair:missing-klien - Created klien', [
                    'user_id' => $user->id,
                    'klien_id' => $klien->id,
                ]);
            } else {
                $klien = Klien::find($user->klien_id);
            }

            // Step 2: Create wallet if missing
            if (!$klien->dompet) {
                $this->createWalletForKlien($klien);
                
                Log::info('repair:missing-klien - Created wallet', [
                    'user_id' => $user->id,
                    'klien_id' => $klien->id,
                ]);
            }
        });
    }

    /**
     * Create klien for user.
     */
    protected function createKlienForUser(User $user): Klien
    {
        $slug = Str::slug($user->name) . '-' . Str::random(6);

        return Klien::create([
            'nama_perusahaan' => $user->name,
            'slug' => $slug,
            'email' => $user->email,
            'tipe_bisnis' => 'perorangan', // Valid: perorangan, cv, pt, ud, lainnya
            'status' => 'aktif',           // Valid: aktif, nonaktif, suspend, trial
            'tipe_paket' => 'umkm',         // Valid: umkm, enterprise
            'tanggal_bergabung' => $user->created_at ?? now(),
        ]);
    }

    /**
     * Create wallet for klien.
     */
    protected function createWalletForKlien(Klien $klien): DompetSaldo
    {
        return DompetSaldo::create([
            'klien_id' => $klien->id,
            'saldo_tersedia' => 0,
            'saldo_tertahan' => 0,
            'batas_warning' => 500000,
            'batas_minimum' => 50000,
            'total_topup' => 0,
            'total_terpakai' => 0,
            'status_saldo' => 'normal',
        ]);
    }
}
