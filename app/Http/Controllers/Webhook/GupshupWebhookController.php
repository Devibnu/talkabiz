<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\InboxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * GupshupWebhookController - Menerima Webhook dari Gupshup WhatsApp API
 * 
 * Endpoint ini menerima pesan masuk dari WhatsApp via Gupshup.
 * 
 * DOKUMENTASI GUPSHUP:
 * ====================
 * - Webhook URL harus HTTPS
 * - Response harus 200 dalam waktu 5 detik
 * - Gupshup akan retry jika gagal
 * 
 * ATURAN KEAMANAN:
 * ================
 * 1. Validasi signature (opsional, via header)
 * 2. Validasi format payload
 * 3. Log semua request untuk debugging
 * 
 * FORMAT PAYLOAD GUPSHUP (Inbound Message):
 * =========================================
 * {
 *   "app": "MyApp",
 *   "timestamp": 1234567890,
 *   "version": 2,
 *   "type": "message",
 *   "payload": {
 *     "id": "ABEGkZlgQwRwAgo...",
 *     "source": "628123456789",      // nomor customer
 *     "destination": "6281234567890", // nomor bisnis
 *     "type": "text|image|document|...",
 *     "payload": {
 *       "text": "Halo...",           // untuk text
 *       "url": "https://...",        // untuk media
 *       "caption": "...",            // untuk media
 *       "filename": "...",           // untuk document
 *       "latitude": 0.0,             // untuk location
 *       "longitude": 0.0,
 *     },
 *     "sender": {
 *       "phone": "628123456789",
 *       "name": "Customer Name",
 *       "country_code": "62",
 *       "dial_code": "62"
 *     },
 *     "context": {                   // jika reply
 *       "id": "...",
 *       "gsId": "..."
 *     }
 *   }
 * }
 * 
 * @package App\Http\Controllers\Webhook
 */
class GupshupWebhookController extends Controller
{
    /**
     * InboxService instance
     */
    protected InboxService $inboxService;

    /**
     * Constructor
     *
     * @param InboxService $inboxService
     */
    public function __construct(InboxService $inboxService)
    {
        $this->inboxService = $inboxService;
    }

    /**
     * Handle incoming webhook dari Gupshup
     * 
     * Endpoint: POST /webhook/gupshup
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        // Log raw payload untuk debugging
        Log::info('GupshupWebhook: Request diterima', [
            'headers' => $request->headers->all(),
            'body' => $request->all()
        ]);

        try {
            // 1. Validasi signature (opsional)
            if (!$this->validasiSignature($request)) {
                Log::warning('GupshupWebhook: Signature tidak valid');
                // Return 200 supaya Gupshup tidak retry
                return response('Invalid signature', 200);
            }

            // 2. Parse payload
            $payload = $request->all();

            // 3. Cek tipe webhook
            $type = $payload['type'] ?? null;

            if ($type === 'message') {
                // Ini pesan masuk
                return $this->handlePesanMasuk($payload);
            } elseif ($type === 'message-event') {
                // Ini status update (delivered, read, dll)
                return $this->handleStatusUpdate($payload);
            } else {
                // Tipe lain (user-event, etc) - abaikan
                Log::info('GupshupWebhook: Tipe webhook diabaikan', ['type' => $type]);
                return response('OK', 200);
            }

        } catch (\Exception $e) {
            Log::error('GupshupWebhook: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return 200 supaya Gupshup tidak terus retry
            return response('Error processed', 200);
        }
    }

    /**
     * Handle pesan masuk dari customer
     *
     * @param array $payload
     * @return Response
     */
    protected function handlePesanMasuk(array $payload): Response
    {
        $messagePayload = $payload['payload'] ?? null;

        if (!$messagePayload) {
            Log::warning('GupshupWebhook: Payload pesan kosong');
            return response('No payload', 200);
        }

        // Parse data untuk InboxService
        $data = $this->parsePesanPayload($messagePayload, $payload);

        // Proses via InboxService
        $hasil = $this->inboxService->prosesPesanMasuk($data);

        Log::info('GupshupWebhook: Hasil proses pesan', [
            'sukses' => $hasil['sukses'],
            'kode' => $hasil['kode'] ?? null,
            'wa_message_id' => $data['wa_message_id'] ?? null
        ]);

        return response('OK', 200);
    }

    /**
     * Handle status update (delivered, read, dll)
     *
     * @param array $payload
     * @return Response
     */
    protected function handleStatusUpdate(array $payload): Response
    {
        $eventPayload = $payload['payload'] ?? null;

        if (!$eventPayload) {
            return response('OK', 200);
        }

        $status = $eventPayload['type'] ?? null;
        $messageId = $eventPayload['id'] ?? null;
        $destination = $eventPayload['destination'] ?? null;

        Log::info('GupshupWebhook: Status update', [
            'status' => $status,
            'message_id' => $messageId,
            'destination' => $destination
        ]);

        // TODO: Implementasi update status pesan jika diperlukan
        // Untuk sekarang, hanya log saja

        return response('OK', 200);
    }

    /**
     * Parse payload pesan dari format Gupshup ke format internal
     *
     * @param array $messagePayload
     * @param array $fullPayload
     * @return array
     */
    protected function parsePesanPayload(array $messagePayload, array $fullPayload): array
    {
        $sender = $messagePayload['sender'] ?? [];
        $content = $messagePayload['payload'] ?? [];
        $type = strtolower($messagePayload['type'] ?? 'text');

        // Base data
        $data = [
            'wa_message_id' => $messagePayload['id'] ?? null,
            'no_customer' => $messagePayload['source'] ?? $sender['phone'] ?? null,
            'no_bisnis' => $messagePayload['destination'] ?? null,
            'nama_customer' => $sender['name'] ?? null,
            'tipe' => $type,
            'timestamp' => $fullPayload['timestamp'] ?? time(),
        ];

        // Parse konten berdasarkan tipe
        switch ($type) {
            case 'text':
                $data['isi_pesan'] = $content['text'] ?? '';
                break;

            case 'image':
                $data['isi_pesan'] = $content['caption'] ?? null;
                $data['caption'] = $content['caption'] ?? null;
                $data['media_url'] = $content['url'] ?? null;
                $data['media_mime_type'] = $content['contentType'] ?? 'image/jpeg';
                break;

            case 'video':
                $data['isi_pesan'] = $content['caption'] ?? null;
                $data['caption'] = $content['caption'] ?? null;
                $data['media_url'] = $content['url'] ?? null;
                $data['media_mime_type'] = $content['contentType'] ?? 'video/mp4';
                break;

            case 'audio':
            case 'voice':
                $data['media_url'] = $content['url'] ?? null;
                $data['media_mime_type'] = $content['contentType'] ?? 'audio/ogg';
                break;

            case 'document':
            case 'file':
                $data['isi_pesan'] = $content['caption'] ?? null;
                $data['caption'] = $content['caption'] ?? null;
                $data['media_url'] = $content['url'] ?? null;
                $data['nama_file'] = $content['filename'] ?? 'document';
                $data['media_mime_type'] = $content['contentType'] ?? 'application/octet-stream';
                break;

            case 'location':
                $lat = $content['latitude'] ?? 0;
                $lng = $content['longitude'] ?? 0;
                $name = $content['name'] ?? '';
                $address = $content['address'] ?? '';
                $data['isi_pesan'] = "📍 Lokasi: {$name}\n{$address}\nKoordinat: {$lat}, {$lng}";
                break;

            case 'contact':
            case 'contacts':
                $contacts = $content['contacts'] ?? [$content];
                $contactInfo = [];
                foreach ($contacts as $contact) {
                    $name = $contact['name']['formattedName'] ?? $contact['name'] ?? 'Unknown';
                    $phones = $contact['phones'] ?? [];
                    $phone = !empty($phones) ? ($phones[0]['phone'] ?? '') : '';
                    $contactInfo[] = "{$name}: {$phone}";
                }
                $data['isi_pesan'] = "👤 Kontak:\n" . implode("\n", $contactInfo);
                break;

            case 'sticker':
                $data['media_url'] = $content['url'] ?? null;
                $data['media_mime_type'] = 'image/webp';
                break;

            default:
                // Fallback - coba ambil text
                $data['isi_pesan'] = $content['text'] ?? json_encode($content);
        }

        // Context (reply to)
        if (isset($messagePayload['context'])) {
            $data['reply_to_message_id'] = $messagePayload['context']['id'] ?? null;
        }

        return $data;
    }

    /**
     * Validasi signature dari Gupshup
     * 
     * Gupshup tidak selalu mengirim signature.
     * Jika dikonfigurasi, bisa divalidasi via header.
     *
     * @param Request $request
     * @return bool
     */
    protected function validasiSignature(Request $request): bool
    {
        // Ambil secret dari config
        $secret = config('services.gupshup.webhook_secret');

        // Jika tidak ada secret, skip validasi
        if (empty($secret)) {
            return true;
        }

        // Ambil signature dari header (sesuaikan dengan header yang digunakan Gupshup)
        $signature = $request->header('X-Gupshup-Signature') 
            ?? $request->header('X-Hub-Signature-256');

        if (empty($signature)) {
            // Jika ada secret tapi tidak ada signature, tolak
            return false;
        }

        // Validasi signature
        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Endpoint untuk verifikasi webhook (GET request)
     * Beberapa provider memerlukan ini untuk setup awal
     *
     * @param Request $request
     * @return Response
     */
    public function verify(Request $request): Response
    {
        // Gupshup biasanya tidak butuh verifikasi khusus
        // Tapi kita sediakan endpoint untuk jaga-jaga

        $challenge = $request->get('hub_challenge') 
            ?? $request->get('challenge')
            ?? $request->get('hub.challenge');

        if ($challenge) {
            return response($challenge, 200);
        }

        return response('Webhook endpoint active', 200);
    }
}
