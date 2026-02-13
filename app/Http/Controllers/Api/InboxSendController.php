<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PesanInbox;
use App\Models\PercakapanInbox;
use App\Models\TemplatePesan;
use App\Services\TemplateRenderService;
use App\Services\WalletService;
use App\Services\MessageRateService;
use App\Services\RevenueGuardService;
use App\Services\Message\MessageDispatchService;
use App\Services\Message\MessageDispatchRequest;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InboxSendController extends Controller
{
    protected TemplateRenderService $renderService;
    protected WalletService $walletService;
    protected MessageRateService $messageRateService;
    protected MessageDispatchService $messageDispatch;

    public function __construct(
        TemplateRenderService $renderService, 
        WalletService $walletService,
        MessageRateService $messageRateService,
        MessageDispatchService $messageDispatch
    ) {
        $this->renderService = $renderService;
        $this->walletService = $walletService;
        $this->messageRateService = $messageRateService;
        $this->messageDispatch = $messageDispatch;
    }

    /**
     * Send template via MessageDispatchService (STRICT saldo protection)
     */
    public function sendTemplate(Request $request, $conversationId)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|integer|exists:template_pesan,id',
            'rendered_message' => 'required|string|max:4096',
            'raw_template' => 'nullable|string',
            'contact_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $percakapan = PercakapanInbox::where('id', $conversationId)
            ->where('ditangani_oleh', Auth::id())
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan atau Anda tidak memiliki akses'
            ], 404);
        }

        $template = TemplatePesan::find($request->template_id);
        if (!$template) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan'
            ], 404);
        }

        $user = Auth::user();
        $klienId = $percakapan->klien_id;

        try {
            // Build recipient from conversation
            $recipients = [[
                'phone' => $percakapan->no_pengirim,
                'name' => $percakapan->nama_kontak ?? 'Customer',
                'conversation_id' => $percakapan->id
            ]];

            // ============ REVENUE GUARD LAYER 4: Atomic Deduction ============
            $revenueGuard = app(RevenueGuardService::class);
            $sendRef = abs(crc32("inbox_template_{$conversationId}_{$user->id}_" . floor(time() / 5)));
            $recipientCount = count($recipients);

            $guardResult = $revenueGuard->executeDeduction(
                userId: $user->id,
                messageCount: $recipientCount,
                category: 'utility',
                referenceType: 'inbox_template',
                referenceId: $sendRef,
                costPreview: $request->attributes->get('revenue_guard', []),
            );

            if (!$guardResult['success'] && !($guardResult['duplicate'] ?? false)) {
                return response()->json([
                    'sukses' => false,
                    'pesan' => $guardResult['message'] ?? 'Gagal memproses pembayaran',
                    'error_code' => 'REVENUE_GUARD_FAILED',
                ], 402);
            }

            $revenueGuardTxId = $guardResult['transaction']?->id;

            // Create dispatch request — preAuthorized karena RGS sudah deduct
            $dispatchRequest = MessageDispatchRequest::fromApi(
                userId: $user->id,
                recipients: $recipients,
                messageContent: $request->rendered_message,
                metadata: [
                    'conversation_id' => $conversationId,
                    'template_id' => $template->id,
                    'template_name' => $template->nama,
                    'source' => 'inbox_chat'
                ],
                preAuthorized: true,
                revenueGuardTransactionId: $revenueGuardTxId,
            );

            // Execute via MessageDispatchService (saldo sudah dipotong oleh RGS)
            $result = $this->messageDispatch->dispatch($dispatchRequest);

            // Use DB transaction to update conversation
            $pesan = DB::transaction(function () use ($percakapan, $request, $template, $user, $result) {
                // Create message record
                $pesan = PesanInbox::create([
                    'percakapan_id' => $percakapan->id,
                    'klien_id' => $percakapan->klien_id,
                    'pengguna_id' => $user->id,
                    'arah' => 'keluar',
                    'no_pengirim' => $user->no_telepon ?? config('services.whatsapp.sender_number', '628000000000'),
                    'tipe' => 'teks',
                    'isi_pesan' => $request->rendered_message,
                    'status' => $result->success ? 'terkirim' : 'gagal',
                    'waktu_pesan' => now(),
                    'transaction_code' => $result->transactionCode, // Link to saldo transaction
                ]);

                // Update conversation
                $percakapan->update([
                    'pesan_terakhir' => $request->rendered_message,
                    'pengirim_terakhir' => 'sales',
                    'waktu_pesan_terakhir' => now(),
                    'total_pesan' => $percakapan->total_pesan + 1,
                ]);

                return $pesan;
            });

            return response()->json([
                'sukses' => true,
                'pesan' => 'Pesan berhasil dikirim via MessageDispatch',
                'data' => [
                    'pesan' => [
                        'id' => $pesan->id,
                        'isi_pesan' => $pesan->isi_pesan,
                        'arah' => 'keluar',
                        'waktu_pesan' => $pesan->waktu_pesan->toIso8601String(),
                        'status_pengiriman' => $pesan->status,
                        'template_id' => $template->id,
                        'template_nama' => $template->nama,
                        'transaction_code' => $result->transactionCode,
                    ],
                    'billing' => [
                        'cost' => $result->totalCost,
                        'balance_after' => $result->balanceAfter,
                        'formatted_balance' => 'Rp ' . number_format($result->balanceAfter, 0, ',', '.'),
                    ],
                    'dispatch' => [
                        'sent' => $result->totalSent,
                        'failed' => $result->totalFailed,
                        'success_rate' => $result->getSuccessRate(),
                    ]
                ]
            ]);

        } catch (InsufficientBalanceException $e) {
            // HARD STOP: Saldo tidak cukup
            return response()->json([
                'sukses' => false,
                'pesan' => $e->getMessage(),
                'error_code' => 'INSUFFICIENT_BALANCE',
                'data' => $e->toApiResponse()
            ], 402); // Payment Required

        } catch (\RuntimeException $e) {
            // RevenueGuardService fail-closed: saldo insufficient or wallet inactive
            return response()->json([
                'sukses' => false,
                'pesan' => $e->getMessage(),
                'error_code' => 'REVENUE_GUARD_FAILED',
            ], 402);

        } catch (\Exception $e) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Gagal mengirim pesan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * LEGACY: Send template with old wallet method (DEPRECATED)
     * Use only for backward compatibility testing
     */
    public function sendTemplateLegacy(Request $request, $conversationId)
    {
        // Original implementation preserved for backward compatibility
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|integer|exists:template_pesan,id',
            'rendered_message' => 'required|string|max:4096',
            'raw_template' => 'nullable|string',
            'contact_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $percakapan = PercakapanInbox::where('id', $conversationId)
            ->where('ditangani_oleh', Auth::id())
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan atau Anda tidak memiliki akses'
            ], 404);
        }

        $template = TemplatePesan::find($request->template_id);
        if (!$template) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Template tidak ditemukan'
            ], 404);
        }

        $klienId = $percakapan->klien_id;
        $penggunaId = Auth::id();

        // Check wallet balance BEFORE sending (OLD METHOD)
        if (!$this->walletService->hasSufficientBalance($klienId)) {
            $currentBalance = $this->walletService->getBalance($klienId);
            return response()->json([
                'sukses' => false,
                'pesan' => 'Saldo tidak mencukupi. Saldo Anda: Rp ' . number_format($currentBalance, 0, ',', '.') . '. Silakan top up terlebih dahulu.',
                'error_code' => 'INSUFFICIENT_BALANCE',
                'data' => [
                    'saldo_saat_ini' => $currentBalance,
                    'harga_pesan' => $this->messageRateService->getRate('utility'),
                ]
            ], 402); // Payment Required
        }

        try {
            // WARNING: This bypasses MessageDispatchService saldo protection
            $result = DB::transaction(function () use ($percakapan, $request, $template, $klienId, $penggunaId) {
                // 1. Debit wallet first (this will throw if insufficient)
                $transaksiSaldo = $this->walletService->debitForMessage(
                    $klienId,
                    $penggunaId,
                    'Kirim pesan: ' . substr($request->rendered_message, 0, 50) . '...'
                );

                // 2. Create message
                $pesan = PesanInbox::create([
                    'percakapan_id' => $percakapan->id,
                    'klien_id' => $klienId,
                    'pengguna_id' => $penggunaId,
                    'arah' => 'keluar',
                    'no_pengirim' => Auth::user()->no_telepon ?? config('services.whatsapp.sender_number', '628000000000'),
                    'tipe' => 'teks',
                    'isi_pesan' => $request->rendered_message,
                    'status' => 'terkirim',
                    'waktu_pesan' => now(),
                ]);

                // 3. Update conversation
                $percakapan->update([
                    'pesan_terakhir' => $request->rendered_message,
                    'pengirim_terakhir' => 'sales',
                    'waktu_pesan_terakhir' => now(),
                    'total_pesan' => $percakapan->total_pesan + 1,
                ]);

                return [
                    'pesan' => $pesan,
                    'transaksi' => $transaksiSaldo,
                ];
            });

            $pesan = $result['pesan'];
            $transaksi = $result['transaksi'];

            return response()->json([
                'sukses' => true,
                'pesan' => 'Pesan berhasil dikirim (LEGACY METHOD)',
                'data' => [
                    'pesan' => [
                        'id' => $pesan->id,
                        'isi_pesan' => $pesan->isi_pesan,
                        'arah' => 'keluar',
                        'waktu_pesan' => $pesan->waktu_pesan->toIso8601String(),
                        'status_pengiriman' => 'terkirim',
                        'template_id' => $template->id,
                        'template_nama' => $template->nama,
                    ],
                    'billing' => [
                        'harga' => $this->messageRateService->getRate('utility'),
                        'saldo_sebelum' => $transaksi->saldo_sebelum,
                        'saldo_sesudah' => $transaksi->saldo_sesudah,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'sukses' => false,
                'pesan' => $e->getMessage()
            ], 500);
        }
    }

    public function getActiveTemplates(Request $request)
    {
        $templates = TemplatePesan::where(function ($q) {
                $q->where('dibuat_oleh', Auth::id())
                  ->orWhere('klien_id', Auth::user()->klien_id ?? null);
            })
            ->whereIn('status', [TemplatePesan::STATUS_DISETUJUI, TemplatePesan::STATUS_DRAFT])
            ->orderBy('nama')
            ->get(['id', 'nama', 'kategori', 'isi_body', 'status']);

        return response()->json([
            'sukses' => true,
            'data' => $templates->map(function ($t) {
                return [
                    'id' => $t->id,
                    'nama' => $t->nama,
                    'kategori' => $t->kategori,
                    'isi_body' => $t->isi_body,
                    'status' => $t->status,
                ];
            })
        ]);
    }

    public function renderPreview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_content' => 'required|string',
            'contact_data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $rendered = $this->renderService->render(
            $request->template_content,
            $request->contact_data
        );

        return response()->json([
            'sukses' => true,
            'data' => [
                'rendered' => $rendered,
                'variables_used' => $this->renderService->extractVariablesFromTemplate($request->template_content)
            ]
        ]);
    }
}
