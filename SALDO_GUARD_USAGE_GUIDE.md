# SaldoGuard & Topup UX System - Usage Guide

## 🎯 Overview

SaldoGuard adalah sistem proteksi saldo yang mencegah user melakukan aksi yang membutuhkan saldo WhatsApp tanpa cek balance terlebih dahulu. Sistem ini mendukung business model baru Talkabiz: **Paket = Fitur & Akses, Pesan = Saldo Topup**.

## 🧩 Component: SaldoGuard

### Basic Usage

```blade
@include('components.saldo-guard', [
    'requiredMessages' => 10,               // Minimal pesan yang dibutuhkan
    'actionText' => 'mengirim campaign',    // Deskripsi aksi yang akan dilakukan
    'ctaText' => 'Kirim Campaign',         // Teks tombol
    'ctaIcon' => 'ni ni-send',             // Icon untuk tombol
    'ctaClass' => 'btn-primary',           // CSS class untuk tombol
    'ctaAttributes' => 'onclick="sendCampaign()"' // HTML attributes tambahan
])
```

### Parameters

| Parameter | Type | Description | Required |
|-----------|------|-------------|----------|
| `requiredMessages` | `int` | Jumlah pesan minimal yang dibutuhkan | ✅ |
| `actionText` | `string` | Deskripsi untuk aksi (misal: "mengirim broadcast") | ✅ |
| `ctaText` | `string` | Text untuk tombol CTA | ✅ |
| `ctaIcon` | `string` | Icon class (misal: "ni ni-send") | ❌ |
| `ctaClass` | `string` | CSS class tambahan untuk button | ❌ |
| `ctaAttributes` | `string` | HTML attributes (onclick, data-*, dll) | ❌ |

### Logic Flow

1. **Sufficient Balance**: Tombol CTA ditampilkan normal
2. **Insufficient Balance**: Tombol disabled + pesan "Topup Saldo" dengan estimasi

## 🚀 Implementation Examples

### 1. Campaign Creation Protection

```blade
{{-- File: resources/views/campaign.blade.php --}}
@include('components.saldo-guard', [
    'requiredMessages' => 10,
    'actionText' => 'membuat campaign',
    'ctaText' => 'Buat Campaign',
    'ctaIcon' => 'ni ni-fat-add',
    'ctaClass' => 'btn-soft-primary',
    'ctaAttributes' => 'data-bs-toggle="modal" data-bs-target="#createCampaignModal"'
])
```

### 2. Broadcast Send Protection

```blade
{{-- File: resources/views/broadcast/send.blade.php --}}
@include('components.saldo-guard', [
    'requiredMessages' => $contactCount ?? 1,
    'actionText' => 'mengirim broadcast',
    'ctaText' => 'Kirim Sekarang',
    'ctaIcon' => 'ni ni-send',
    'ctaClass' => 'btn-success',
    'ctaAttributes' => 'onclick="processBroadcast()"'
])
```

### 3. Template Test Protection

```blade
{{-- File: resources/views/template/test-modal.blade.php --}}
@include('components.saldo-guard', [
    'requiredMessages' => 1,
    'actionText' => 'mengirim test template',
    'ctaText' => 'Kirim Test',
    'ctaIcon' => 'ni ni-mobile-phone',
    'ctaClass' => 'btn-info btn-sm',
    'ctaAttributes' => 'data-template-id="{{ $template->id }}"'
])
```

## 💳 Topup UX System

### Controller Features

**File**: `app/Http/Controllers/TopupController.php`

- **SSOT Pricing**: Ambil harga dari database dengan fallback
- **Preset Nominals**: Generate otomatis berdasarkan target pesan
- **Payment Gateway Ready**: Struktur untuk integrasi Midtrans

### Frontend Components

1. **Navbar Saldo Display** - `layouts/navbars/auth/nav.blade.php`
2. **Topup Modal** - `topup/modal.blade.php`
3. **Topup Page** - `topup/index.blade.php`
4. **Global JS Integration** - `layouts/user_type/auth.blade.php`

### Routes

```php
Route::prefix('topup')->name('topup.')->group(function () {
    Route::get('/', [TopupController::class, 'index'])->name('index');
    Route::get('/modal', [TopupController::class, 'modal'])->name('modal');
    Route::post('/process', [TopupController::class, 'process'])->name('process');
});
```

## 🎨 Styling & UX

### SaldoGuard States

1. **Normal State**: Tombol CTA ditampilkan sesuai parameter
2. **Insufficient State**: 
   - Tombol disabled dengan style opacity 0.6
   - Background berubah menjadi warning gradient
   - Text berubah jadi "Topup Saldo"
   - Icon berubah jadi wallet icon
   - Muncul subtitle dengan estimasi biaya

### Navbar Saldo Widget

```php
{{-- Auto-display di navbar dengan color coding --}}
Status: Aman (hijau) | Menipis (kuning) | Kritis (orange) | Habis (merah)
```

## 🔧 Integration Checklist

### ✅ Completed

- [x] TopupController dengan SSOT pricing
- [x] SaldoGuard component dengan proteksi otomatis  
- [x] Topup modal dengan preset amounts
- [x] Navbar saldo display dengan status indicator
- [x] Dashboard topup button integration
- [x] Routes dan frontend integration
- [x] Campaign page implementation example

### 🔄 Next Steps

1. **Payment Integration**: Implementasi actual Midtrans di `TopupController::initiatePayment()`
2. **Real-time Balance Updates**: WebSocket/Pusher untuk update saldo setelah payment
3. **More Protection Points**: Apply SaldoGuard ke semua fitur yang consume saldo
4. **Transaction History**: Halaman history topup dan penggunaan saldo
5. **Auto-refill Settings**: Fitur auto-topup ketika saldo menipis

## 📝 Business Rules

1. **No Bypass Rule**: Semua fitur kirim pesan WAJIB cek saldo via SaldoGuard
2. **Honest Pricing**: Harga per pesan dari database (SSOT), bisa berubah sesuai kebijakan
3. **Entry Barrier**: Starter plan berbayar, tidak ada paket gratis
4. **SSOT Everything**: Semua data plan, pricing, features dari database

## 🚨 Error Handling

SaldoGuard akan gracefully handle:
- User tanpa wallet (Super Admin)
- Database error saat cek saldo  
- Missing pricing data (fallback ke default)
- Network issues (disabled state dengan retry)

## 📞 Support

Jika ada pertanyaan atau butuh bantuan implementasi, silakan refer ke:
- `SaldoService` - Core balance management
- `WalletService` - Wallet operations & validation
- `AutoPricingService` - Dynamic pricing dari SSOT database