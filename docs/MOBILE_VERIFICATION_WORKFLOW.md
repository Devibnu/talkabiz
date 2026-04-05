# Mobile Verification Workflow

Dokumen ini merangkum workflow verifikasi mobile API yang sekarang tersedia di repository.

## Local Test Suite

Jalankan seluruh suite mobile yang sudah dipisah per area:

```bash
php artisan test tests/Feature/Api/MobileApi*Test.php
```

File test utama:

- `tests/Feature/Api/MobileApiAuthTest.php`
- `tests/Feature/Api/MobileApiCoreTest.php`
- `tests/Feature/Api/MobileApiContactsTest.php`
- `tests/Feature/Api/MobileApiInboxTest.php`

Shared base class:

- `tests/Feature/Api/MobileApiTestCase.php`

## Production-Safe Probes

Probe berikut aman untuk verifikasi live karena hanya memakai login/logout, read-only calls, dan validation probes yang tidak mengirim pesan WhatsApp sungguhan.

### Auth Probe

```bash
scripts/probe-mobile-auth.sh <base_url> <email> <password> [device_name]
```

Mencakup:

- login validation `422`
- invalid credential `401`
- unauthenticated profile `401`
- authenticated profile `200`
- logout `200`

### Contacts Probe

```bash
scripts/probe-mobile-contacts.sh <base_url> <email> <password> [device_name]
```

Mencakup:

- contacts list
- create validation `422`
- search
- tag filter
- `per_page=999` cap menjadi `100`

### Inbox Probe

```bash
scripts/probe-mobile-inbox.sh <base_url> <email> <password> [device_name]
```

Mencakup:

- inbox list
- missing detail `404`
- search
- status filter
- detail view
- send validation `422`
- `per_page=999` cap menjadi `100`

## Wrapper Probe

Untuk menjalankan semua probe live sekaligus:

```bash
scripts/probe-mobile-all.sh <base_url> <email> <password> [device_name] [summary_file]
```

Contoh:

```bash
scripts/probe-mobile-all.sh \
  https://talkabiz.ibnuapps.cloud/api \
  basic1@gmail.com \
  'ReviewMeta2026!' \
  'Mobile All Probe' \
  ./mobile-probe-summary.json
```

Output wrapper:

- section detail per probe
- summary akhir `PASS | ...` atau `FAIL | ...`
- exit code nonzero jika ada probe gagal

Jika argumen `summary_file` diberikan, wrapper akan menulis JSON machine-readable dengan struktur seperti ini:

```json
{
  "generated_at": "2026-04-05T08:22:26+00:00",
  "base_url": "https://talkabiz.ibnuapps.cloud/api",
  "device_name": "Mobile All Probe",
  "failed_count": 0,
  "passed": true,
  "results": [
    {"status": "PASS", "label": "AUTH PROBE"},
    {"status": "PASS", "label": "CONTACTS PROBE"},
    {"status": "PASS", "label": "INBOX PROBE"}
  ]
}
```

## Recommended Usage

- Local development: jalankan `php artisan test tests/Feature/Api/MobileApi*Test.php`
- Pre-deploy manual check: jalankan `scripts/probe-mobile-all.sh ...`
- Automation/deploy gate: jalankan wrapper dengan `summary_file` agar hasil bisa diparsing tool lain

## GitHub Actions

Workflow GitHub Actions yang ditambahkan sengaja bersifat manual (`workflow_dispatch`) agar tidak mengganggu flow aktif sambil menunggu review Meta.

### Manual Local-Like Suite

File:

- `.github/workflows/mobile-api-test-manual.yml`

Fungsi:

- setup PHP 8.2
- install Composer dependencies
- jalankan `php artisan test tests/Feature/Api/MobileApi*Test.php`

### Manual Live Probe

File:

- `.github/workflows/mobile-api-live-probe-manual.yml`

Fungsi:

- menjalankan `scripts/probe-mobile-all.sh`
- menulis summary JSON ke artifact GitHub Actions

Secrets yang dibutuhkan:

- `MOBILE_PROBE_EMAIL`
- `MOBILE_PROBE_PASSWORD`

Karena workflow ini manual, ia tidak akan memblokir push, PR, atau deployment yang sedang berjalan sekarang.