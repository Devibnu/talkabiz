<?php

namespace App\Services;

use App\Models\TemplatePesan;
use App\Events\TemplateDisetujuiEvent;
use App\Events\TemplateDitolakEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TemplateStatusWebhookService
 * 
 * Service untuk menangani webhook status template dari Gupshup/Meta.
 * 
 * ATURAN KERAS:
 * - Idempotent: tidak update jika status sudah final (disetujui)
 * - Tidak crash walau payload tidak lengkap
 * - Semua error hanya di-log, response tetap 200 OK
 * - Multi-tenant safe
 * 
 * @author TalkaBiz Team
 */
class TemplateStatusWebhookService
{
    // ==================== STATUS MAPPING ====================

    /**
     * Map status dari Gupshup ke status internal (Indonesia)
     */
    protected const STATUS_MAP = [
        'APPROVED' => TemplatePesan::STATUS_DISETUJUI,
        'ACTIVE' => TemplatePesan::STATUS_DISETUJUI,
        'REJECTED' => TemplatePesan::STATUS_DITOLAK,
        'DISABLED' => TemplatePesan::STATUS_DITOLAK,
        'PAUSED' => TemplatePesan::STATUS_DITOLAK,
        'PENDING' => TemplatePesan::STATUS_DIAJUKAN,
        'IN_REVIEW' => TemplatePesan::STATUS_DIAJUKAN,
        'SUBMITTED' => TemplatePesan::STATUS_DIAJUKAN,
    ];

    // ==================== MAIN HANDLER ====================

    /**
     * Handle webhook payload untuk template status update
     * 
     * @param array $payload Raw payload dari Gupshup
     * @return array{handled: bool, message: string, template_id?: int}
     */
    public function handleStatusUpdate(array $payload): array
    {
        // Log incoming webhook
        Log::channel('whatsapp')->info('TemplateStatusWebhook: Received', [
            'payload' => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Validasi payload dasar
        $validasi = $this->validatePayload($payload);
        if (!$validasi['valid']) {
            Log::channel('whatsapp')->warning('TemplateStatusWebhook: Invalid payload', [
                'reason' => $validasi['reason'],
                'payload' => $payload,
            ]);
            return [
                'handled' => false,
                'message' => $validasi['reason'],
            ];
        }

        // Extract template data dari payload
        $templateData = $this->extractTemplateData($payload);

        // Cari template di database
        $template = $this->findTemplate($templateData);

        if (!$template) {
            Log::channel('whatsapp')->info('TemplateStatusWebhook: Template not found', [
                'provider_id' => $templateData['provider_id'],
                'nama' => $templateData['nama'],
                'bahasa' => $templateData['bahasa'],
            ]);
            return [
                'handled' => false,
                'message' => 'Template tidak ditemukan',
            ];
        }

        // Cek idempotency - jika status sudah final (disetujui), skip
        if ($this->isStatusFinal($template)) {
            Log::channel('whatsapp')->info('TemplateStatusWebhook: Skipped - already final', [
                'template_id' => $template->id,
                'current_status' => $template->status,
            ]);
            return [
                'handled' => false,
                'message' => 'Template sudah dalam status final',
                'template_id' => $template->id,
            ];
        }

        // Map status provider ke status internal
        $newStatus = $this->mapStatus($templateData['status']);

        // Cek apakah status berubah
        if ($template->status === $newStatus) {
            Log::channel('whatsapp')->info('TemplateStatusWebhook: No status change', [
                'template_id' => $template->id,
                'status' => $newStatus,
            ]);
            return [
                'handled' => false,
                'message' => 'Status tidak berubah',
                'template_id' => $template->id,
            ];
        }

        // Update template dalam transaction
        try {
            $result = $this->updateTemplateStatus($template, $newStatus, $templateData, $payload);
            return $result;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('TemplateStatusWebhook: Update failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'handled' => false,
                'message' => 'Gagal update status: ' . $e->getMessage(),
                'template_id' => $template->id,
            ];
        }
    }

    // ==================== VALIDATION ====================

    /**
     * Validasi payload webhook
     */
    protected function validatePayload(array $payload): array
    {
        // Cek event type
        $event = $payload['event'] ?? null;
        if ($event !== 'TEMPLATE_STATUS_UPDATE') {
            return [
                'valid' => false,
                'reason' => 'Event type bukan TEMPLATE_STATUS_UPDATE',
            ];
        }

        // Cek template data exists
        $template = $payload['template'] ?? null;
        if (!$template || !is_array($template)) {
            return [
                'valid' => false,
                'reason' => 'Template data tidak ditemukan',
            ];
        }

        // Cek minimal satu identifier (id atau name)
        $hasId = !empty($template['id']);
        $hasName = !empty($template['name']);

        if (!$hasId && !$hasName) {
            return [
                'valid' => false,
                'reason' => 'Template ID atau nama harus ada',
            ];
        }

        // Cek status
        if (empty($template['status'])) {
            return [
                'valid' => false,
                'reason' => 'Status tidak ditemukan',
            ];
        }

        return ['valid' => true];
    }

    // ==================== DATA EXTRACTION ====================

    /**
     * Extract template data dari payload
     */
    protected function extractTemplateData(array $payload): array
    {
        $template = $payload['template'] ?? [];

        return [
            'provider_id' => $template['id'] ?? null,
            'nama' => $template['name'] ?? null,
            'status' => strtoupper($template['status'] ?? 'PENDING'),
            'bahasa' => $template['language'] ?? 'id',
            'reason' => $template['reason'] ?? null,
        ];
    }

    // ==================== TEMPLATE LOOKUP ====================

    /**
     * Cari template berdasarkan provider_id atau nama+bahasa
     */
    protected function findTemplate(array $data): ?TemplatePesan
    {
        // Prioritas 1: Cari by provider_template_id
        if (!empty($data['provider_id'])) {
            $template = TemplatePesan::where('provider_template_id', $data['provider_id'])->first();
            if ($template) {
                return $template;
            }
        }

        // Prioritas 2: Cari by nama_template + bahasa
        if (!empty($data['nama'])) {
            $query = TemplatePesan::where('nama_template', $data['nama']);
            
            if (!empty($data['bahasa'])) {
                $query->where('bahasa', $data['bahasa']);
            }

            // Hanya ambil yang status-nya sedang diajukan (menunggu approval)
            $query->where('status', TemplatePesan::STATUS_DIAJUKAN);

            $template = $query->first();
            if ($template) {
                return $template;
            }
        }

        return null;
    }

    // ==================== STATUS HELPERS ====================

    /**
     * Cek apakah status template sudah final
     */
    protected function isStatusFinal(TemplatePesan $template): bool
    {
        // Status disetujui adalah final, tidak boleh diubah oleh webhook
        return $template->status === TemplatePesan::STATUS_DISETUJUI;
    }

    /**
     * Map status dari provider ke status internal
     */
    public function mapStatus(string $providerStatus): string
    {
        $upperStatus = strtoupper($providerStatus);
        return self::STATUS_MAP[$upperStatus] ?? TemplatePesan::STATUS_DIAJUKAN;
    }

    // ==================== UPDATE TEMPLATE ====================

    /**
     * Update status template dan dispatch event
     */
    protected function updateTemplateStatus(
        TemplatePesan $template,
        string $newStatus,
        array $templateData,
        array $rawPayload
    ): array {
        $oldStatus = $template->status;

        DB::transaction(function () use ($template, $newStatus, $templateData, $rawPayload) {
            $updateData = [
                'status' => $newStatus,
            ];

            // Set approved_at jika disetujui
            if ($newStatus === TemplatePesan::STATUS_DISETUJUI) {
                $updateData['approved_at'] = now();
            }

            // Set alasan penolakan jika ditolak
            if ($newStatus === TemplatePesan::STATUS_DITOLAK && !empty($templateData['reason'])) {
                $updateData['alasan_penolakan'] = $templateData['reason'];
                $updateData['catatan_reject'] = $templateData['reason'];
            }

            // Update provider_template_id jika belum ada
            if (empty($template->provider_template_id) && !empty($templateData['provider_id'])) {
                $updateData['provider_template_id'] = $templateData['provider_id'];
            }

            // Simpan webhook payload untuk audit trail
            $webhookLog = $template->provider_response ?? [];
            $webhookLog['webhook_received'] = [
                'received_at' => now()->toIso8601String(),
                'payload' => $rawPayload,
            ];
            $updateData['provider_response'] = $webhookLog;

            $template->update($updateData);
        });

        // Dispatch event berdasarkan status baru
        $this->dispatchStatusEvent($template, $newStatus, $templateData['reason']);

        Log::channel('whatsapp')->info('TemplateStatusWebhook: Status updated', [
            'template_id' => $template->id,
            'nama' => $template->nama_template,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $templateData['reason'],
        ]);

        return [
            'handled' => true,
            'message' => "Status berubah dari {$oldStatus} ke {$newStatus}",
            'template_id' => $template->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];
    }

    /**
     * Dispatch event sesuai status
     */
    protected function dispatchStatusEvent(TemplatePesan $template, string $status, ?string $reason): void
    {
        if ($status === TemplatePesan::STATUS_DISETUJUI) {
            event(new TemplateDisetujuiEvent($template, $template->provider_template_id));
        } elseif ($status === TemplatePesan::STATUS_DITOLAK) {
            event(new TemplateDitolakEvent($template, $reason));
        }
    }

    // ==================== SIGNATURE VALIDATION ====================

    /**
     * Validasi signature webhook dari Gupshup (opsional)
     */
    public function validateSignature(string $payload, ?string $signature): bool
    {
        // Jika tidak ada signature header, skip validasi
        if (empty($signature)) {
            return true;
        }

        $secret = config('whatsapp.gupshup.webhook_secret');

        // Jika webhook secret tidak dikonfigurasi, skip validasi
        if (empty($secret)) {
            return true;
        }

        // Gupshup biasanya menggunakan HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
