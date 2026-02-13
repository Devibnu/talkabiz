# Risk Approval Panel - Panduan Penggunaan

## 📍 Akses Panel

**URL:** `/owner/risk-approval`  
**Role Required:** Owner atau Super Admin

## 🎯 Fitur Utama

### 1. **Statistics Dashboard**
- **Pending**: Jumlah akun menunggu persetujuan
- **Approved**: Jumlah akun yang telah disetujui
- **Rejected**: Jumlah akun yang ditolak
- **Suspended**: Jumlah akun yang disuspend

### 2. **Filter Tabs**
Klik tab untuk melihat klien berdasarkan status:
- Pending (menunggu review)
- Approved (telah disetujui)
- Rejected (ditolak)
- Suspended (disuspend sementara)

### 3. **Tabel Klien**
Informasi yang ditampilkan:
- Nama perusahaan & user
- Email & phone
- Business Type
- Risk Level (LOW/MEDIUM/HIGH)
- Approval Status
- Tanggal daftar

### 4. **Action Buttons**

#### 👁️ View Detail
- Klik untuk melihat detail lengkap business profile
- Menampilkan history persetujuan
- Informasi lengkap klien

#### ✅ Approve (untuk status Pending)
- Menyetujui klien untuk dapat mengirim pesan
- Catatan opsional
- Klien langsung dapat menggunakan sistem

#### ❌ Reject (untuk status Pending)
- Menolak aplikasi klien
- **Catatan wajib diisi** (alasan penolakan)
- Status klien menjadi 'non-aktif'
- Aksi permanen

#### 🚫 Suspend (untuk status Approved)
- Menonaktifkan sementara akun klien
- **Catatan wajib diisi** (alasan suspend)
- Klien tidak dapat mengirim pesan
- Bisa diaktifkan kembali

#### 🔄 Reactivate (untuk status Suspended)
- Mengaktifkan kembali klien yang disuspend
- Catatan opsional
- Klien kembali dapat mengirim pesan

## 🔄 Workflow Approval

### Untuk High-Risk Business (Lainnya)

```
1. User registrasi → Business Type: Lainnya
2. Onboarding selesai → approval_status: 'pending'
3. Muncul di Risk Approval Panel (tab Pending)
4. Owner review detail klien
5. Owner pilih aksi:
   
   ✅ APPROVE
   - Input catatan (opsional)
   - Klik "Konfirmasi"
   - Status → 'approved'
   - Klien dapat kirim pesan
   - Log tercatat
   
   ❌ REJECT
   - Input alasan (WAJIB)
   - Klik "Konfirmasi"
   - Status → 'rejected'
   - Klien.status → 'non-aktif'
   - Tidak bisa login
   - Log tercatat
```

### Untuk Low/Medium Risk Business

```
1. User registrasi → Business Type: PT/CV/UD/Perorangan
2. Onboarding selesai → approval_status: 'approved' (otomatis)
3. Klien langsung dapat menggunakan sistem
4. Owner tetap bisa suspend jika diperlukan
```

## 📝 Modal Konfirmasi

Setiap aksi akan memunculkan modal konfirmasi:

1. **Warning Message**: Penjelasan aksi yang akan dilakukan
2. **Catatan Field**: 
   - Opsional untuk Approve & Reactivate
   - **WAJIB** untuk Reject & Suspend
3. **Tombol Konfirmasi**: Eksekusi aksi
4. **Tombol Batal**: Batalkan aksi

## 📊 Recent Actions Timeline

Menampilkan 10 aksi approval terakhir:
- Waktu aksi
- Jenis aksi (approve/reject/suspend/reactivate)
- Nama klien
- Nama admin yang melakukan aksi
- Catatan/alasan

## 🔍 Detail Business Profile Modal

Informasi yang ditampilkan:
- Nama perusahaan
- Email & Phone
- Business Type & Risk Level
- Approval Status (dengan badge warna)
- Tanggal daftar
- Tanggal approval (jika sudah approved)
- Notes approval
- **Approval History** (timeline lengkap)

## 🛡️ Security & Audit

### Logging Otomatis
Setiap aksi dicatat dalam `approval_logs`:
- Action type
- Status transition (from → to)
- Actor (admin yang melakukan)
- Timestamp
- Reason/Notes
- Metadata (IP, user agent, klien info)

### Role-Based Access
- Hanya Owner & Super Admin yang bisa akses
- Middleware: `role:owner,super_admin`
- Client biasa tidak bisa akses panel ini

## 💡 Tips Penggunaan

### 1. Review Pending Secara Berkala
- Check tab Pending setiap hari
- Jangan biarkan klien menunggu terlalu lama
- Review risk level dan business profile

### 2. Catatan yang Jelas
- Untuk Reject: Jelaskan alasan penolakan
- Untuk Suspend: Jelaskan pelanggaran/masalah
- Catatan akan tercatat dalam audit log

### 3. Monitor Suspended Klien
- Check tab Suspended berkala
- Reactivate jika masalah sudah resolved
- Komunikasikan dengan klien via email

### 4. Review Approval History
- Klik "View Detail" untuk lihat history lengkap
- Cek pattern suspicious behavior
- Gunakan data untuk improve review process

## 🎨 UI Elements

### Badge Colors
- **Green** (Approved): Klien aktif dan dapat kirim pesan
- **Yellow** (Pending): Menunggu review owner
- **Red** (Rejected): Aplikasi ditolak
- **Dark** (Suspended): Sementara dinonaktifkan

### Risk Level Badges
- **Green** (LOW): Business type low risk (PT, CV)
- **Yellow** (MEDIUM): Medium risk (Perorangan, UD)
- **Red** (HIGH): High risk (Lainnya)

## 📱 Responsive Design
Panel fully responsive untuk:
- Desktop (optimal experience)
- Tablet (adapted layout)
- Mobile (touch-friendly buttons)

## 🔗 Integration

Panel terintegrasi dengan:
- **ApprovalService**: Business logic approval workflow
- **RiskEngine**: Risk assessment system
- **ApprovalLog**: Complete audit trail
- **Klien Model**: Business profile data
- **User Model**: Authentication & authorization

## ⚙️ Technical Details

### Routes
```php
GET  /owner/risk-approval              → Panel index
GET  /owner/risk-approval/{id}         → Get klien detail
POST /owner/risk-approval/{id}/approve → Approve klien
POST /owner/risk-approval/{id}/reject  → Reject klien
POST /owner/risk-approval/{id}/suspend → Suspend klien
POST /owner/risk-approval/{id}/reactivate → Reactivate klien
```

### AJAX Endpoints
- All actions via AJAX for smooth UX
- SweetAlert2 for success/error notifications
- Auto reload after action success
- Error handling with user-friendly messages

### Middleware Stack
- `auth`: Must be authenticated
- `role:owner,super_admin`: Owner/Admin only
- `client.access`: Domain setup check

## 🚀 Quick Start

1. **Login sebagai owner:**
   ```
   Email: owner@talkabiz.com
   ```

2. **Navigasi ke panel:**
   ```
   Sidebar → Owner → Risk Approval
   atau langsung ke: /owner/risk-approval
   ```

3. **Review pending klien:**
   - Klik tab "Pending"
   - Klik "View Detail" untuk info lengkap
   - Pilih Approve atau Reject

4. **Monitor statistics:**
   - Lihat cards di atas untuk overview
   - Check recent actions di bawah

## ✅ Success Criteria

Panel berhasil jika:
- ✅ Pending klien terlihat di tab Pending
- ✅ Approve mengubah status ke 'approved'
- ✅ Reject mengubah status ke 'rejected' + non-aktif
- ✅ Suspend mengubah status ke 'suspended'
- ✅ Reactivate mengembalikan ke 'approved'
- ✅ Semua aksi tercatat di approval_logs
- ✅ Klien pending tidak bisa kirim pesan
- ✅ Klien approved bisa kirim pesan

---

**Created:** February 11, 2026  
**Version:** 1.0.0  
**Component:** Risk Approval Panel
