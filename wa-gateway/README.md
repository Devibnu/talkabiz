# Talkabiz WhatsApp Gateway

Node.js service untuk koneksi WhatsApp menggunakan whatsapp-web.js.

## Prerequisites

- Node.js 18+
- Chromium/Chrome (untuk puppeteer)
- Laravel backend running

## Installation

```bash
cd wa-gateway
npm install
cp .env.example .env
# Edit .env dengan konfigurasi yang sesuai
```

## Configuration (.env)

```env
PORT=3001
NODE_ENV=development
API_KEY=your_api_key_here
WEBHOOK_SECRET=your_webhook_secret_here
LARAVEL_WEBHOOK_URL=http://localhost:8000/api/whatsapp/webhook
SESSION_PATH=./sessions
```

## Running

### Development
```bash
npm run dev
```

### Production (dengan PM2)
```bash
npm run pm2:start
npm run pm2:logs  # Lihat logs
```

## API Endpoints

### Health Check
```
GET /health
```

### Start Session (Generate QR)
```
POST /api/session/start
Headers: X-API-Key: your_api_key
Body: {
  "klien_id": 1,
  "session_id": "wa_1_abc123",
  "webhook_url": "http://laravel/api/whatsapp/webhook"
}
```

### Check Status
```
GET /api/session/status?klien_id=1
Headers: X-API-Key: your_api_key
```

### Logout
```
POST /api/session/logout
Headers: X-API-Key: your_api_key
Body: { "klien_id": 1 }
```

## Flow

1. Laravel calls `POST /api/session/start`
2. Gateway creates WhatsApp client, generates QR
3. Returns QR as base64 data URI
4. User scans QR with WhatsApp
5. Gateway receives `authenticated` event
6. Gateway calls Laravel webhook with `connection.update` event
7. Laravel updates `kliens.wa_terhubung = true`
8. Frontend polling sees connected status
9. Session saved to `./sessions/{klien_id}/` for persistence

## Troubleshooting

### QR tidak muncul
- Pastikan Chromium terinstall
- Cek log: `npm run pm2:logs` atau terminal output

### Scan berhasil tapi tidak CONNECTED
- Cek apakah webhook bisa dipanggil:
  ```bash
  curl -X POST http://localhost:8000/api/whatsapp/webhook \
    -H "Content-Type: application/json" \
    -d '{"event":"connection.update","klien_id":1,"status":"connected"}'
  ```
- Cek Laravel log: `tail -f storage/logs/laravel.log`

### Session hilang setelah restart
- Pastikan `SESSION_PATH` writeable
- Cek folder `./sessions/` ada dan tidak dihapus

## Laravel Configuration

Tambahkan ke `.env` Laravel:

```env
WHATSAPP_GATEWAY_URL=http://localhost:3001
WHATSAPP_GATEWAY_API_KEY=your_api_key_here
WHATSAPP_WEBHOOK_SECRET=your_webhook_secret_here
```
