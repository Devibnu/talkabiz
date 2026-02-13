<?php

namespace App\Jobs;

use App\Models\Kampanye;
use App\Models\TargetKampanye;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FinalizeCampaignJob - Finalize Campaign setelah semua pesan terproses
 * 
 * Job ini bertugas:
 * 1. Menghitung statistik final campaign
 * 2. Update status campaign ke 'selesai'
 * 3. Cleanup orphan targets
 * 
 * @package App\Jobs
 */
class FinalizeCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $kampanyeId;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(int $kampanyeId)
    {
        $this->kampanyeId = $kampanyeId;
        $this->onQueue('campaigns');
    }

    public function handle(): void
    {
        $kampanye = Kampanye::find($this->kampanyeId);
        
        if (!$kampanye) {
            return;
        }

        // Skip jika sudah selesai
        if ($kampanye->status === 'selesai') {
            return;
        }

        // Get stats
        $stats = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'terkirim' THEN 1 ELSE 0 END) as terkirim,
                SUM(CASE WHEN status IN ('gagal_permanen', 'gagal_retry') THEN 1 ELSE 0 END) as gagal,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        // Jika masih ada pending, reschedule
        if ($stats->pending > 0) {
            // Reset stuck 'processing' targets
            $this->resetStuckProcessingTargets();

            // Masih ada yang pending, campaign belum selesai
            Log::info('FinalizeCampaignJob: Still has pending targets', [
                'kampanye_id' => $this->kampanyeId,
                'pending' => $stats->pending,
            ]);
            
            // Reschedule finalization check
            static::dispatch($this->kampanyeId)
                ->delay(now()->addMinutes(1))
                ->onQueue('campaigns');
            
            return;
        }

        // Semua terproses, update campaign
        $kampanye->update([
            'status' => 'selesai',
            'selesai_pada' => now(),
            'total_terkirim' => $stats->terkirim,
            'total_gagal' => $stats->gagal,
        ]);

        Log::info('FinalizeCampaignJob: Campaign finalized', [
            'kampanye_id' => $this->kampanyeId,
            'total' => $stats->total,
            'terkirim' => $stats->terkirim,
            'gagal' => $stats->gagal,
        ]);
    }

    /**
     * Reset targets yang stuck di 'processing' lebih dari 5 menit
     */
    protected function resetStuckProcessingTargets(): void
    {
        $affected = TargetKampanye::where('kampanye_id', $this->kampanyeId)
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'pending',
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            Log::warning('FinalizeCampaignJob: Reset stuck processing targets', [
                'kampanye_id' => $this->kampanyeId,
                'count' => $affected,
            ]);
        }
    }
}
