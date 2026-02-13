<?php

namespace App\Console\Commands;

use App\Models\PlanTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: Bersihkan duplicate pending transactions
 * 
 * Skenario: User klik "Bayar Sekarang" berkali-kali sehingga
 * tercipta >1 transaksi pending untuk klien + plan yang sama.
 * 
 * ATURAN:
 * - Untuk setiap kombinasi klien_id + plan_id,
 *   HANYA SIMPAN 1 transaksi pending terbaru.
 * - Sisanya di-cancel dengan alasan 'Duplicate cleanup'.
 * - Default: hanya bersihkan yang dibuat dalam 5 menit terakhir.
 *   Gunakan --all untuk bersihkan semua.
 * 
 * AMAN:
 * - Tidak menghapus data (soft cancel)
 * - Tidak menyentuh transaksi success/failed/expired
 * - Idempotent: bisa dijalankan berkali-kali
 * 
 * USAGE:
 *   php artisan plan:clean-duplicates              # 5 menit terakhir
 *   php artisan plan:clean-duplicates --all        # semua pending
 *   php artisan plan:clean-duplicates --minutes=30 # 30 menit terakhir
 *   php artisan plan:clean-duplicates --dry-run    # preview tanpa eksekusi
 */
class CleanDuplicatePendingTransactions extends Command
{
    protected $signature = 'plan:clean-duplicates
                            {--all : Bersihkan semua duplicate, bukan hanya 5 menit terakhir}
                            {--minutes=5 : Batas waktu dalam menit (default: 5)}
                            {--dry-run : Preview saja, tidak mengubah data}';

    protected $description = 'Bersihkan duplicate pending transactions untuk klien + plan yang sama';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isAll = $this->option('all');
        $minutes = (int) $this->option('minutes');

        $this->info('=======================================');
        $this->info(' Clean Duplicate Pending Transactions');
        $this->info('=======================================');

        if ($isDryRun) {
            $this->warn('[DRY RUN] Tidak ada data yang akan diubah.');
        }

        // 1. Cari semua kombinasi klien_id + plan_id yang punya >1 pending
        $query = PlanTransaction::whereIn('status', [
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])
            ->select('klien_id', 'plan_id', DB::raw('COUNT(*) as total'))
            ->groupBy('klien_id', 'plan_id')
            ->having(DB::raw('COUNT(*)'), '>', 1);

        if (!$isAll) {
            $query->where('created_at', '>=', now()->subMinutes($minutes));
        }

        $duplicateGroups = $query->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('Tidak ada duplicate pending transaction ditemukan.');
            return Command::SUCCESS;
        }

        $this->info("Ditemukan {$duplicateGroups->count()} grup dengan duplicate pending.");
        $this->newLine();

        $totalCancelled = 0;

        foreach ($duplicateGroups as $group) {
            // 2. Untuk setiap grup, ambil semua pending dan keep yang terbaru
            $pendingQuery = PlanTransaction::where('klien_id', $group->klien_id)
                ->where('plan_id', $group->plan_id)
                ->whereIn('status', [
                    PlanTransaction::STATUS_PENDING,
                    PlanTransaction::STATUS_WAITING_PAYMENT,
                ]);

            if (!$isAll) {
                $pendingQuery->where('created_at', '>=', now()->subMinutes($minutes));
            }

            $allPending = $pendingQuery->orderBy('created_at', 'desc')->get();

            if ($allPending->count() <= 1) {
                continue;
            }

            // Keep the newest one, cancel the rest
            $keep = $allPending->first();
            $toCancel = $allPending->slice(1);

            $this->line("  Klien #{$group->klien_id} | Plan #{$group->plan_id} | Total: {$allPending->count()} | Keep: {$keep->transaction_code}");

            foreach ($toCancel as $tx) {
                $this->line("    <fg=red>CANCEL</> {$tx->transaction_code} (status: {$tx->status}, created: {$tx->created_at})");

                if (!$isDryRun) {
                    $tx->markAsCancelled('Duplicate cleanup — newer transaction exists: ' . $keep->transaction_code);
                }

                $totalCancelled++;
            }
        }

        $this->newLine();

        if ($isDryRun) {
            $this->warn("[DRY RUN] {$totalCancelled} transaksi akan di-cancel. Jalankan tanpa --dry-run untuk eksekusi.");
        } else {
            $this->info("{$totalCancelled} duplicate pending transaction berhasil di-cancel.");
            Log::info("plan:clean-duplicates cancelled {$totalCancelled} duplicate pending transactions");
        }

        return Command::SUCCESS;
    }
}
