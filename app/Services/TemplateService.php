<?php

namespace App\Services;

use App\Models\TemplatePesan;
use App\Models\Kampanye;
use App\Models\LogAktivitas;
use App\Events\TemplateDiajukanEvent;
use App\Events\TemplateDisetujuiEvent;
use App\Events\TemplateDitolakEvent;
use App\Exceptions\WhatsApp\WhatsAppException;
use App\Exceptions\WhatsApp\GupshupApiException;
use App\Exceptions\WhatsApp\TemplateSubmissionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * TemplateService
 * 
 * Service layer untuk mengelola template pesan WhatsApp.
 * Semua business logic template ada di sini.
 * 
 * ATURAN BISNIS:
 * - Status = draft → boleh edit & hapus
 * - Status = diajukan → READ ONLY
 * - Status = disetujui → READ ONLY
 * - Status = ditolak → boleh edit & ajukan ulang
 * - Template yang sudah dipakai campaign TIDAK BOLEH dihapus
 * - Sales hanya READ, Admin & Owner bisa CRUD
 * 
 * @author TalkaBiz Team
 */
class TemplateService
{
    protected WhatsAppTemplateProvider $provider;

    public function __construct(WhatsAppTemplateProvider $provider)
    {
        $this->provider = $provider;
    }

    // ==================== BUAT TEMPLATE ====================

    /**
     * Buat template baru (status = draft)
     * 
     * @param int $klienId
     * @param array $data
     * @param int $userId
     * @return array{sukses: bool, template?: TemplatePesan, pesan?: string, errors?: array}
     */
    public function buatTemplate(int $klienId, array $data, int $userId): array
    {
        // Validasi template
        $validasi = $this->validasiTemplate($data);
        if (!$validasi['valid']) {
            return [
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validasi['errors'],
            ];
        }

        // Cek duplikat nama
        $exists = TemplatePesan::where('klien_id', $klienId)
            ->where('nama_template', $data['nama_template'])
            ->exists();

        if ($exists) {
            return [
                'sukses' => false,
                'pesan' => 'Nama template sudah digunakan',
            ];
        }

        try {
            $template = DB::transaction(function () use ($klienId, $data, $userId) {
                $template = TemplatePesan::create([
                    'klien_id' => $klienId,
                    'dibuat_oleh' => $userId,
                    'nama_template' => $data['nama_template'],
                    'nama_tampilan' => $data['nama_tampilan'] ?? ucwords(str_replace('_', ' ', $data['nama_template'])),
                    'kategori' => $data['kategori'],
                    'bahasa' => $data['bahasa'] ?? 'id',
                    'header' => $data['header'] ?? null,
                    'header_type' => $data['header_type'] ?? 'none',
                    'header_media_url' => $data['header_media_url'] ?? null,
                    'body' => $data['isi_template'] ?? $data['body'],
                    'footer' => $data['footer'] ?? null,
                    'buttons' => $data['buttons'] ?? null,
                    'contoh_variabel' => $data['variable'] ?? $data['contoh_variabel'] ?? [],
                    'status' => TemplatePesan::STATUS_DRAFT,
                    'aktif' => true,
                ]);

                // Log aktivitas
                $this->logAktivitas($klienId, $userId, 'template_dibuat', [
                    'template_id' => $template->id,
                    'nama' => $template->nama_template,
                ]);

                return $template;
            });

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil dibuat',
                'template' => $template,
            ];
        } catch (\Exception $e) {
            Log::error('TemplateService::buatTemplate error', [
                'error' => $e->getMessage(),
                'klien_id' => $klienId,
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal membuat template: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== UPDATE TEMPLATE ====================

    /**
     * Update template (hanya jika status = draft atau ditolak)
     * 
     * @param int $klienId
     * @param int $templateId
     * @param array $data
     * @param int $userId
     * @return array{sukses: bool, template?: TemplatePesan, pesan?: string}
     */
    public function updateTemplate(int $klienId, int $templateId, array $data, int $userId): array
    {
        $template = TemplatePesan::where('klien_id', $klienId)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan',
            ];
        }

        // Cek apakah bisa diedit
        if (!$template->bisaDiedit()) {
            return [
                'sukses' => false,
                'pesan' => 'Template dengan status ' . $template->status . ' tidak dapat diedit',
            ];
        }

        // Validasi jika ada perubahan body/variable
        if (isset($data['isi_template']) || isset($data['body']) || isset($data['variable'])) {
            $validateData = array_merge([
                'nama_template' => $template->nama_template,
                'kategori' => $template->kategori,
                'isi_template' => $template->body,
            ], $data);

            $validasi = $this->validasiTemplate($validateData);
            if (!$validasi['valid']) {
                return [
                    'sukses' => false,
                    'pesan' => 'Validasi gagal',
                    'errors' => $validasi['errors'],
                ];
            }
        }

        try {
            $template = DB::transaction(function () use ($template, $data, $userId, $klienId) {
                $updateData = [];

                if (isset($data['nama_tampilan'])) {
                    $updateData['nama_tampilan'] = $data['nama_tampilan'];
                }
                if (isset($data['isi_template'])) {
                    $updateData['body'] = $data['isi_template'];
                }
                if (isset($data['body'])) {
                    $updateData['body'] = $data['body'];
                }
                if (isset($data['header'])) {
                    $updateData['header'] = $data['header'];
                }
                if (isset($data['header_type'])) {
                    $updateData['header_type'] = $data['header_type'];
                }
                if (isset($data['footer'])) {
                    $updateData['footer'] = $data['footer'];
                }
                if (isset($data['buttons'])) {
                    $updateData['buttons'] = $data['buttons'];
                }
                if (isset($data['variable'])) {
                    $updateData['contoh_variabel'] = $data['variable'];
                }
                if (isset($data['contoh_variabel'])) {
                    $updateData['contoh_variabel'] = $data['contoh_variabel'];
                }

                // Jika ditolak, kembalikan ke draft setelah edit
                if ($template->status === TemplatePesan::STATUS_DITOLAK) {
                    $updateData['status'] = TemplatePesan::STATUS_DRAFT;
                    $updateData['alasan_penolakan'] = null;
                    $updateData['catatan_reject'] = null;
                }

                $template->update($updateData);

                $this->logAktivitas($klienId, $userId, 'template_diupdate', [
                    'template_id' => $template->id,
                    'nama' => $template->nama_template,
                ]);

                return $template->fresh();
            });

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil diupdate',
                'template' => $template,
            ];
        } catch (\Exception $e) {
            Log::error('TemplateService::updateTemplate error', [
                'error' => $e->getMessage(),
                'template_id' => $templateId,
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal update template: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== HAPUS TEMPLATE ====================

    /**
     * Hapus template (soft delete - status = arsip)
     * Tidak bisa hapus jika sudah dipakai campaign
     * 
     * @param int $klienId
     * @param int $templateId
     * @param int $userId
     * @return array{sukses: bool, pesan?: string}
     */
    public function hapusTemplate(int $klienId, int $templateId, int $userId): array
    {
        $template = TemplatePesan::where('klien_id', $klienId)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan',
            ];
        }

        // Cek apakah sedang dipakai campaign aktif
        $campaignAktif = $this->cekTemplateDipakaiCampaign($template);
        if ($campaignAktif['dipakai']) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak dapat dihapus karena sedang digunakan oleh ' . $campaignAktif['jumlah'] . ' campaign aktif',
            ];
        }

        // Cek apakah pernah dipakai (dipakai_count > 0)
        if ($template->dipakai_count > 0) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak dapat dihapus karena sudah pernah digunakan. Gunakan fitur arsip.',
            ];
        }

        try {
            DB::transaction(function () use ($template, $userId, $klienId) {
                $template->update([
                    'status' => TemplatePesan::STATUS_ARSIP,
                    'aktif' => false,
                ]);

                $this->logAktivitas($klienId, $userId, 'template_dihapus', [
                    'template_id' => $template->id,
                    'nama' => $template->nama_template,
                ]);
            });

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil dihapus',
            ];
        } catch (\Exception $e) {
            Log::error('TemplateService::hapusTemplate error', [
                'error' => $e->getMessage(),
                'template_id' => $templateId,
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal menghapus template: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== AJUKAN TEMPLATE KE PROVIDER ====================

    /**
     * Ajukan template ke provider (Gupshup/Meta) untuk approval
     * 
     * @param int $klienId
     * @param int $templateId
     * @param int $userId
     * @return array{sukses: bool, template?: TemplatePesan, pesan?: string}
     */
    public function ajukanTemplateKeProvider(int $klienId, int $templateId, int $userId): array
    {
        $template = TemplatePesan::where('klien_id', $klienId)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan',
            ];
        }

        // Cek apakah bisa diajukan
        if (!$template->bisaSubmit()) {
            return [
                'sukses' => false,
                'pesan' => 'Template dengan status ' . $template->status . ' tidak dapat diajukan',
            ];
        }

        // Validasi sebelum submit
        $validasi = $this->validasiTemplate([
            'nama_template' => $template->nama_template,
            'kategori' => $template->kategori,
            'isi_template' => $template->body,
            'variable' => $template->contoh_variabel,
        ]);

        if (!$validasi['valid']) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak valid untuk diajukan',
                'errors' => $validasi['errors'],
            ];
        }

        try {
            // Submit ke provider
            $providerResult = $this->provider->submitTemplate($template);

            if (!$providerResult['sukses']) {
                return [
                    'sukses' => false,
                    'pesan' => 'Gagal mengirim ke provider: ' . ($providerResult['error'] ?? 'Unknown error'),
                ];
            }

            $template = DB::transaction(function () use ($template, $providerResult, $userId, $klienId) {
                $updateData = [
                    'status' => TemplatePesan::STATUS_DIAJUKAN,
                    'provider_template_id' => $providerResult['template_id'],
                    'submitted_at' => now(),
                ];

                // Simpan payload dan response dari provider (Anti-Boncos Logging)
                if (isset($providerResult['payload'])) {
                    $updateData['provider_payload'] = $providerResult['payload'];
                }
                if (isset($providerResult['response'])) {
                    $updateData['provider_response'] = $providerResult['response'];
                }

                $template->update($updateData);

                $this->logAktivitas($klienId, $userId, 'template_diajukan', [
                    'template_id' => $template->id,
                    'nama' => $template->nama_template,
                    'provider_template_id' => $providerResult['template_id'],
                    'payload' => $providerResult['payload'] ?? null,
                ]);

                return $template->fresh();
            });

            // Fire event
            event(new TemplateDiajukanEvent($template, $userId));

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil diajukan untuk review',
                'template' => $template,
            ];

        } catch (TemplateSubmissionException $e) {
            Log::error('TemplateService::ajukanTemplateKeProvider - TemplateSubmissionException', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'template_id' => $templateId,
                'context' => $e->getContext(),
            ]);

            return [
                'sukses' => false,
                'pesan' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ];

        } catch (GupshupApiException $e) {
            Log::error('TemplateService::ajukanTemplateKeProvider - GupshupApiException', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'gupshup_error_code' => $e->getGupshupErrorCode(),
                'template_id' => $templateId,
                'raw_response' => $e->getRawResponse(),
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Error dari Gupshup: ' . $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'gupshup_error' => $e->getGupshupErrorCode(),
            ];

        } catch (WhatsAppException $e) {
            Log::error('TemplateService::ajukanTemplateKeProvider - WhatsAppException', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'template_id' => $templateId,
            ]);

            return [
                'sukses' => false,
                'pesan' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ];

        } catch (\Exception $e) {
            Log::error('TemplateService::ajukanTemplateKeProvider error', [
                'error' => $e->getMessage(),
                'template_id' => $templateId,
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mengajukan template: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== ARSIPKAN TEMPLATE ====================

    /**
     * Arsipkan template (soft delete untuk template yang sudah dipakai)
     * 
     * @param int $klienId
     * @param int $templateId
     * @param int $userId
     * @return array{sukses: bool, template?: TemplatePesan, pesan?: string}
     */
    public function arsipkanTemplate(int $klienId, int $templateId, int $userId): array
    {
        $template = TemplatePesan::where('klien_id', $klienId)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan',
            ];
        }

        // Cek campaign aktif
        $campaignAktif = $this->cekTemplateDipakaiCampaign($template);
        if ($campaignAktif['dipakai']) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak dapat diarsipkan karena sedang digunakan oleh campaign aktif',
            ];
        }

        try {
            DB::transaction(function () use ($template, $userId, $klienId) {
                $template->update([
                    'status' => TemplatePesan::STATUS_ARSIP,
                    'aktif' => false,
                ]);

                $this->logAktivitas($klienId, $userId, 'template_diarsipkan', [
                    'template_id' => $template->id,
                    'nama' => $template->nama_template,
                ]);
            });

            return [
                'sukses' => true,
                'pesan' => 'Template berhasil diarsipkan',
                'template' => $template->fresh(),
            ];
        } catch (\Exception $e) {
            Log::error('TemplateService::arsipkanTemplate error', [
                'error' => $e->getMessage(),
                'template_id' => $templateId,
            ]);

            return [
                'sukses' => false,
                'pesan' => 'Gagal mengarsipkan template: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== VALIDASI TEMPLATE ====================

    /**
     * Validasi data template sesuai aturan WhatsApp
     * 
     * @param array $data
     * @return array{valid: bool, errors?: array}
     */
    public function validasiTemplate(array $data): array
    {
        $errors = [];

        // Validasi nama template
        if (isset($data['nama_template'])) {
            $namaErrors = TemplatePesan::validasiNamaTemplate($data['nama_template']);
            if (!empty($namaErrors)) {
                $errors['nama_template'] = $namaErrors;
            }
        } else {
            $errors['nama_template'] = ['Nama template wajib diisi'];
        }

        // Validasi kategori
        $validKategori = ['marketing', 'utility', 'authentication'];
        if (isset($data['kategori']) && !in_array($data['kategori'], $validKategori)) {
            $errors['kategori'] = ['Kategori harus salah satu dari: ' . implode(', ', $validKategori)];
        }

        // Validasi isi template
        $body = $data['isi_template'] ?? $data['body'] ?? null;
        if (empty($body)) {
            $errors['isi_template'] = ['Isi template wajib diisi'];
        } elseif (strlen($body) > 1024) {
            $errors['isi_template'] = ['Isi template maksimal 1024 karakter'];
        }

        // Validasi variabel
        if ($body) {
            $variabelCheck = $this->extractVariableDariTemplate($body);
            $contohVariabel = $data['variable'] ?? $data['contoh_variabel'] ?? [];

            if (!empty($variabelCheck['variabel'])) {
                $missing = array_diff($variabelCheck['variabel'], array_keys($contohVariabel));
                if (!empty($missing)) {
                    $errors['variable'] = ['Contoh variabel belum lengkap. Missing: ' . implode(', ', $missing)];
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    // ==================== EXTRACT VARIABLE ====================

    /**
     * Extract variabel dari template body
     * Format: {{1}}, {{2}}, dst
     * 
     * @param string $body
     * @return array{variabel: array, jumlah: int}
     */
    public function extractVariableDariTemplate(string $body): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        
        $variabel = array_unique($matches[1] ?? []);
        sort($variabel);

        return [
            'variabel' => $variabel,
            'jumlah' => count($variabel),
        ];
    }

    // ==================== SYNC STATUS DARI PROVIDER ====================

    /**
     * Sync status template dari provider
     * 
     * @param int $klienId
     * @param int $userId
     * @return array{sukses: bool, synced: int, pesan?: string}
     */
    public function syncStatusDariProvider(int $klienId, int $userId): array
    {
        $templates = TemplatePesan::where('klien_id', $klienId)
            ->where('status', TemplatePesan::STATUS_DIAJUKAN)
            ->whereNotNull('provider_template_id')
            ->get();

        if ($templates->isEmpty()) {
            return [
                'sukses' => true,
                'synced' => 0,
                'pesan' => 'Tidak ada template yang perlu di-sync',
            ];
        }

        $synced = 0;

        foreach ($templates as $template) {
            try {
                $result = $this->provider->cekStatusTemplate($template->provider_template_id);

                if ($result['sukses']) {
                    $newStatus = $this->provider->mapStatusDariProvider($result['status']);

                    if ($newStatus !== $template->status) {
                        DB::transaction(function () use ($template, $newStatus, $result, $userId, $klienId) {
                            $updateData = ['status' => $newStatus];

                            if ($newStatus === TemplatePesan::STATUS_DISETUJUI) {
                                $updateData['approved_at'] = now();
                                event(new TemplateDisetujuiEvent($template, $template->provider_template_id));
                            } elseif ($newStatus === TemplatePesan::STATUS_DITOLAK) {
                                $updateData['alasan_penolakan'] = $result['alasan'] ?? null;
                                $updateData['catatan_reject'] = $result['alasan'] ?? null;
                                event(new TemplateDitolakEvent($template, $result['alasan'] ?? ''));
                            }

                            $template->update($updateData);

                            $this->logAktivitas($klienId, $userId, 'template_status_sync', [
                                'template_id' => $template->id,
                                'status_lama' => TemplatePesan::STATUS_DIAJUKAN,
                                'status_baru' => $newStatus,
                            ]);
                        });

                        $synced++;
                    }
                }
            } catch (\Exception $e) {
                Log::error('TemplateService::syncStatusDariProvider error', [
                    'template_id' => $template->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sukses' => true,
            'synced' => $synced,
            'pesan' => "Berhasil sync {$synced} template",
        ];
    }

    // ==================== CEK TEMPLATE DIPAKAI CAMPAIGN ====================

    /**
     * Cek apakah template sedang dipakai oleh campaign aktif
     * 
     * @param TemplatePesan $template
     * @return array{dipakai: bool, jumlah: int, campaigns?: array}
     */
    public function cekTemplateDipakaiCampaign(TemplatePesan $template): array
    {
        $statusAktif = ['draft', 'siap', 'berjalan', 'dijadwalkan'];

        $campaigns = Kampanye::where('template_id', $template->id)
            ->whereIn('status', $statusAktif)
            ->get(['id', 'nama', 'status']);

        return [
            'dipakai' => $campaigns->isNotEmpty(),
            'jumlah' => $campaigns->count(),
            'campaigns' => $campaigns->toArray(),
        ];
    }

    // ==================== AMBIL DAFTAR TEMPLATE ====================

    /**
     * Ambil daftar template dengan filter
     * 
     * @param int $klienId
     * @param array $filters
     * @return array{sukses: bool, templates: LengthAwarePaginator}
     */
    public function ambilDaftar(int $klienId, array $filters = []): array
    {
        $query = TemplatePesan::where('klien_id', $klienId);

        // Filter status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter kategori
        if (!empty($filters['kategori'])) {
            $query->where('kategori', $filters['kategori']);
        }

        // Filter aktif
        if (isset($filters['aktif'])) {
            $query->where('aktif', $filters['aktif']);
        }

        // Exclude arsip by default
        if (!isset($filters['include_arsip']) || !$filters['include_arsip']) {
            $query->where('status', '!=', TemplatePesan::STATUS_ARSIP);
        }

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('nama_template', 'like', "%{$search}%")
                    ->orWhere('nama_tampilan', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = $filters['per_page'] ?? 15;
        $templates = $query->paginate($perPage);

        return [
            'sukses' => true,
            'templates' => $templates,
        ];
    }

    // ==================== AMBIL DETAIL TEMPLATE ====================

    /**
     * Ambil detail template
     * 
     * @param int $klienId
     * @param int $templateId
     * @return array{sukses: bool, template?: TemplatePesan, pesan?: string}
     */
    public function ambilDetail(int $klienId, int $templateId): array
    {
        $template = TemplatePesan::where('klien_id', $klienId)
            ->where('id', $templateId)
            ->with(['pembuat:id,nama,email'])
            ->first();

        if (!$template) {
            return [
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan',
            ];
        }

        return [
            'sukses' => true,
            'template' => $template,
        ];
    }

    // ==================== AMBIL TEMPLATE DISETUJUI ====================

    /**
     * Ambil semua template yang sudah disetujui (untuk dropdown)
     * 
     * @param int $klienId
     * @param string|null $kategori
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function ambilTemplateDisetujui(int $klienId, ?string $kategori = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = TemplatePesan::where('klien_id', $klienId)
            ->where('status', TemplatePesan::STATUS_DISETUJUI)
            ->where('aktif', true);

        if ($kategori) {
            $query->where('kategori', $kategori);
        }

        return $query->orderBy('nama_tampilan')->get(['id', 'nama_template', 'nama_tampilan', 'kategori']);
    }

    // ==================== HELPER: LOG AKTIVITAS ====================

    /**
     * Log aktivitas ke tabel log_aktivitas
     */
    protected function logAktivitas(int $klienId, int $userId, string $aksi, array $data = []): void
    {
        try {
            if (class_exists(LogAktivitas::class)) {
                LogAktivitas::create([
                    'klien_id' => $klienId,
                    'pengguna_id' => $userId,
                    'modul' => 'template',
                    'aksi' => $aksi,
                    'data' => $data,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to log aktivitas', [
                'error' => $e->getMessage(),
                'aksi' => $aksi,
            ]);
        }
    }
}
