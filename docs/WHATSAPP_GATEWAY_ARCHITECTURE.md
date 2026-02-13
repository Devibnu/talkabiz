# WhatsApp Gateway Architecture - Talkabiz

## 🔴 ROOT CAUSE ANALYSIS (Feb 2026)

### Masalah Ditemukan:
```
1. Gateway Node.js TIDAK BERJALAN
   └→ .env tidak ada
   └→ sessions folder tidak ada
   └→ npm install belum dijalankan

2. Polling Frontend TIDAK CEK SESSION STATUS
   └→ Hanya cek /whatsapp/status (database)
   └→ Database tidak update karena webhook tidak dipanggil
   └→ Webhook tidak dipanggil karena gateway tidak running

3. Mock QR Generated
   └→ Gateway tidak running → Laravel generate mock QR
   └→ Mock QR bukan QR WhatsApp asli
   └→ User scan mock QR = tidak terjadi apa-apa
```

### Fix yang Diterapkan:
```
✅ Buat .env di wa-gateway/
✅ Buat sessions/ dan logs/ folder
✅ Update polling ke /whatsapp/session-status (realtime)
✅ Update authenticated event untuk update cache
✅ Add gateway status check di checkSessionStatus()
✅ Update Laravel .env dengan gateway config
```

## 🏗️ ARSITEKTUR SISTEM

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              TALKABIZ SYSTEM                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌───────────────┐      HTTP/REST      ┌───────────────────────────────┐   │
│  │   FRONTEND    │◀───────────────────▶│        LARAVEL BACKEND        │   │
│  │   (Blade)     │                      │   WhatsAppController.php      │   │
│  │               │   POST /wa/connect   │   WhatsAppConnectionService   │   │
│  │   - QR View   │   GET  /wa/status    │                               │   │
│  │   - Polling   │   POST /wa/disconnect│                               │   │
│  └───────────────┘                      └──────────────┬────────────────┘   │
│                                                        │                     │
│                                    HTTP (port 3001)    │                     │
│                                                        ▼                     │
│                                         ┌───────────────────────────────┐   │
│                                         │    NODE.JS WA GATEWAY         │   │
│                                         │    (whatsapp-web.js/Baileys)  │   │
│                                         │                               │   │
│                                         │   - Session per klien_id      │   │
│                                         │   - Auth persistent (files)   │   │
│                                         │   - QR generation             │   │
│                                         │   - Connection management     │   │
│                                         │   - Webhook emitter           │   │
│                                         └──────────────┬────────────────┘   │
│                                                        │                     │
│                                         WebSocket      │                     │
│                                                        ▼                     │
│                                         ┌───────────────────────────────┐   │
│                                         │      WHATSAPP SERVERS         │   │
│                                         │      (Meta/Facebook)          │   │
│                                         └───────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## 📊 STATUS FLOW

```
┌──────────────┐
│ DISCONNECTED │ ◀─────────────────────────────────────────┐
└──────┬───────┘                                           │
       │ POST /wa/connect                                  │
       ▼                                                   │
┌──────────────┐                                           │
│ QR_REQUESTED │ ─────────(timeout 10s)────▶ ERROR         │
└──────┬───────┘                              │            │
       │ Gateway returns QR string            │            │
       ▼                                      │            │
┌──────────────┐                              │            │
│   QR_READY   │ ─────────(120s expire)─────▶│            │
└──────┬───────┘                              │            │
       │ User scans QR                        │            │
       ▼                                      │            │
┌──────────────┐                              │            │
│   SCANNING   │ ─────────(auth fail)───────▶│            │
└──────┬───────┘                              │            │
       │ Auth success                         │            │
       ▼                                      │            │
┌──────────────┐                              │            │
│  CONNECTED   │                              │            │
└──────┬───────┘                              │            │
       │ POST /wa/disconnect                  │            │
       └──────────────────────────────────────┘            │
                                                           │
┌──────────────┐                                           │
│   EXPIRED    │ ──────────(refresh)───────────────────────┘
└──────────────┘
```

## 🔄 FLOW: CONNECT → SCAN → CONNECTED

### Step 1: User Clicks "Connect WhatsApp"
```
Frontend                    Laravel                     Node.js Gateway
   │                           │                              │
   │ POST /whatsapp/connect    │                              │
   │─────────────────────────▶│                              │
   │                           │ POST /api/session/start      │
   │                           │  { klien_id, webhook_url }   │
   │                           │─────────────────────────────▶│
   │                           │                              │
   │                           │   { qr: "2@ABC...", ... }    │
   │                           │◀─────────────────────────────│
   │                           │                              │
   │  { qr_code: base64,      │                              │
   │    session_id, expires }  │                              │
   │◀─────────────────────────│                              │
   │                           │                              │
   ▼ Display QR                │                              │
```

### Step 2: User Scans QR with Phone
```
Frontend                    Laravel                     Node.js Gateway        WhatsApp
   │                           │                              │                    │
   │ [Polling /wa/status]      │                              │                    │
   │─────────────────────────▶│                              │                    │
   │                           │                              │◀──[WS: qr.scan]────│
   │                           │                              │                    │
   │                           │                              │◀──[WS: auth]───────│
   │                           │                              │                    │
   │                           │ POST /api/whatsapp/webhook   │                    │
   │                           │  { event: "connected",       │                    │
   │                           │    session_id, phone }       │                    │
   │                           │◀─────────────────────────────│                    │
   │                           │                              │                    │
   │                           │ UPDATE kliens SET            │                    │
   │                           │  wa_terhubung=1              │                    │
   │                           │                              │                    │
   │  { connected: true }      │                              │                    │
   │◀─────────────────────────│                              │                    │
   │                           │                              │                    │
   ▼ Redirect/Refresh          │                              │                    │
```

### Step 3: Session Persistence
```
Node.js Gateway
      │
      │ On connection.update (connected)
      ▼
┌─────────────────────────────────────────────────────────┐
│  storage/wa-sessions/{klien_id}/                        │
│   ├── creds.json          (auth credentials)            │
│   ├── app-state-sync/     (app state)                   │
│   └── pre-keys/           (pre-shared keys)             │
└─────────────────────────────────────────────────────────┘
      │
      │ On restart, auto-restore session
      ▼
  Status: CONNECTED (tanpa scan ulang)
```

## 🐛 DEBUGGING CHECKLIST

### Kenapa QR Muter & Expired?

| Gejala | Penyebab | Solusi |
|--------|----------|--------|
| QR muncul tapi status tetap "CONNECTING" | Gateway tidak berjalan | `cd wa-gateway && npm run dev` |
| QR expired setelah 120 detik | User tidak scan tepat waktu | Auto-refresh QR baru |
| "QR Code library gagal dimuat" | Frontend issue (sudah fix) | Server-side QR generation ✅ |
| Scan berhasil tapi tidak CONNECTED | Webhook tidak dipanggil | Cek gateway → Laravel HTTP |
| CONNECTED lalu balik DISCONNECTED | Session tidak disimpan | Cek `./sessions/` permission |
| Gateway crash saat start | Chromium tidak ada | Install: `brew install chromium` |

### Step-by-Step Debugging

#### 1. Cek Gateway Running
```bash
cd wa-gateway
npm run dev

# Di terminal lain:
curl http://localhost:3001/health
# Expected: {"status":"ok",...}
```

#### 2. Cek Laravel Log
```bash
tail -f storage/logs/laravel.log | grep -i whatsapp
```

#### 3. Test Generate QR dari Gateway
```bash
curl -X POST http://localhost:3001/api/session/start \
  -H "Content-Type: application/json" \
  -d '{"klien_id":1,"session_id":"test_123"}'
```

Expected response:
```json
{
  "success": true,
  "status": "qr_ready",
  "qr": "data:image/png;base64,..."
}
```

#### 4. Test Webhook ke Laravel
```bash
curl -X POST http://localhost:8000/api/whatsapp/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "event": "connection.update",
    "klien_id": 1,
    "session_id": "test_123",
    "status": "connected",
    "phone_number": "628123456789"
  }'
```

Expected: `{"success":true,"message":"Connection confirmed",...}`

#### 5. Verifikasi Database Updated
```bash
php artisan tinker --execute="echo App\Models\Klien::find(1)->wa_terhubung ? 'CONNECTED' : 'NOT CONNECTED';"
```

### Common Fixes

#### Gateway tidak bisa start (Chromium error)
```bash
# macOS
brew install chromium

# Linux (Ubuntu)
sudo apt-get install chromium-browser

# Set path di .env
CHROME_PATH=/usr/bin/chromium-browser
```

#### Session tidak persist
```bash
# Pastikan folder sessions ada dan writable
mkdir -p wa-gateway/sessions
chmod 755 wa-gateway/sessions
```

#### Webhook tidak diterima Laravel
```bash
# Cek CSRF exception di VerifyCsrfToken middleware
# Pastikan api/* sudah exclude dari CSRF
```

## 📁 FILE STRUCTURE

```
talkabiz/
├── app/
│   ├── Http/Controllers/
│   │   ├── WhatsAppController.php        # Main WA routes
│   │   └── Api/
│   │       └── WhatsAppWebhookController.php  # Webhook receiver
│   └── Services/
│       └── WhatsAppConnectionService.php # Business logic
│
├── config/
│   └── services.php                      # Gateway config
│
├── routes/
│   ├── web.php                           # /whatsapp/* routes
│   └── api.php                           # /api/whatsapp/webhook
│
├── wa-gateway/                           # NODE.JS GATEWAY
│   ├── package.json
│   ├── server.js                         # Express server
│   ├── src/
│   │   ├── session-manager.js            # Multi-session handler
│   │   ├── webhook-emitter.js            # Call Laravel webhook
│   │   └── routes/
│   │       ├── session.js                # /api/session/*
│   │       └── message.js                # /api/message/*
│   └── sessions/                         # Auth storage per klien
│       ├── 1/creds.json
│       ├── 2/creds.json
│       └── ...
│
└── .env
    WHATSAPP_GATEWAY_URL=http://localhost:3001
    WHATSAPP_GATEWAY_API_KEY=your_secret_key
```

## ⚙️ PRODUCTION NOTES

### Anti-Crash & Auto-Restart

```bash
# PM2 ecosystem file for gateway
# wa-gateway/ecosystem.config.js
module.exports = {
  apps: [{
    name: 'wa-gateway',
    script: 'server.js',
    instances: 1,              # Single instance (WA limitation)
    autorestart: true,
    watch: false,
    max_memory_restart: '500M',
    env: {
      NODE_ENV: 'production',
      PORT: 3001
    }
  }]
};

# Start with PM2
pm2 start ecosystem.config.js
pm2 save
pm2 startup
```

### Multi-User (Multi-Session) Safety

```javascript
// Each klien gets isolated session
const sessions = new Map(); // klien_id → WhatsAppClient

// Never share sessions between users
// Sessions stored in: ./sessions/{klien_id}/
```

### Rate Limiting

```javascript
// Gateway-side rate limits
const rateLimit = require('express-rate-limit');

app.use('/api/session/start', rateLimit({
  windowMs: 60 * 60 * 1000, // 1 hour
  max: 10,                   // 10 requests per hour per IP
  message: 'Terlalu banyak request'
}));
```

### Health Check

```javascript
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    uptime: process.uptime(),
    sessions: sessions.size,
    memory: process.memoryUsage()
  });
});
```
