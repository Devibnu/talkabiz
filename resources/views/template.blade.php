@extends('layouts.user_type.auth')

@push('styles')
<style>
/* ============================================
   TALKABIZ TEMPLATE PESAN - Soft UI Style
   ============================================ */

/* Page Header */
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}

.page-subtitle {
    font-size: 0.875rem;
    color: #67748e;
    margin: 0.25rem 0 0 0;
}

/* Primary Button - Soft UI Style */
.btn-soft-primary {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border: none;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

.btn-soft-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5), 0 3px 6px -2px rgba(94, 114, 228, 0.3);
    color: #fff;
}

/* Template Card Container */
.template-card {
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 8px 26px -4px hsla(0,0%,8%,.15), 0 8px 9px -5px hsla(0,0%,8%,.06);
    overflow: hidden;
}

.template-card-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.template-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}

.template-card-body {
    padding: 1.5rem;
}

/* Empty State */
.empty-state-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    text-align: center;
}

.empty-state-icon {
    width: 6rem;
    height: 6rem;
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    border-radius: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 16px -4px rgba(94, 114, 228, 0.4);
}

.empty-state-icon i {
    font-size: 2.5rem;
    color: #fff;
}

.empty-state-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 0.5rem;
}

.empty-state-subtitle {
    font-size: 0.9375rem;
    color: #67748e;
    margin-bottom: 1.5rem;
    max-width: 320px;
}

.empty-state-btn {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border: none;
    padding: 0.75rem 1.5rem;
    font-size: 0.875rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.empty-state-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px -4px rgba(94, 114, 228, 0.5);
    color: #fff;
}

/* Template Grid */
.template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
}

.template-item {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 0.75rem;
    overflow: hidden;
    transition: all 0.2s ease-in-out;
    display: flex;
    flex-direction: column;
}

.template-item:hover {
    border-color: #5e72e4;
    box-shadow: 0 8px 20px -4px rgba(94, 114, 228, 0.2);
    transform: translateY(-2px);
}

.template-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    background: linear-gradient(310deg, #f8f9fa 0%, #fff 100%);
    border-bottom: 1px solid #e9ecef;
}

.template-item-name {
    font-size: 0.9375rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.template-item-category {
    display: inline-flex;
    padding: 0.25rem 0.625rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border-radius: 0.375rem;
    flex-shrink: 0;
}

.template-item-body {
    padding: 1rem 1.25rem;
    flex: 1;
}

.template-item-preview {
    font-size: 0.8125rem;
    color: #67748e;
    line-height: 1.6;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.6rem;
}

.template-item-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.875rem 1.25rem;
    background: #fafbfc;
    border-top: 1px solid #e9ecef;
}

.template-item-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-template-action {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.375rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.btn-template-action.btn-edit {
    background: linear-gradient(310deg, #627594 0%, #8392ab 100%);
    color: #fff;
}

.btn-template-action.btn-edit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 7px -1px rgba(98, 117, 148, 0.4);
}

.btn-template-action.btn-delete {
    background: linear-gradient(310deg, #ea0606 0%, #ff667c 100%);
    color: #fff;
}

.btn-template-action.btn-delete:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 7px -1px rgba(234, 6, 6, 0.4);
}

.btn-template-action.btn-submit-meta {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}

.btn-template-action.btn-submit-meta:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 7px -1px rgba(23, 173, 55, 0.4);
}

.btn-template-action.btn-submit-meta:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.5rem;
}

.status-badge i {
    font-size: 0.625rem;
}

.status-badge.status-approved {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}

.status-badge.status-pending {
    background: linear-gradient(310deg, #f5365c 0%, #f56036 100%);
    color: #fff;
}

.status-badge.status-draft {
    background: linear-gradient(310deg, #627594 0%, #8392ab 100%);
    color: #fff;
}

.status-badge.status-rejected {
    background: linear-gradient(310deg, #ea0606 0%, #ff667c 100%);
    color: #fff;
}

.info-banner {
    background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%);
    border-radius: 0.75rem;
    padding: 1.25rem 1.5rem;
    color: #fff;
    margin-bottom: 1.5rem;
}

.info-banner h6 {
    color: #fff;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.info-banner p {
    color: rgba(255,255,255,0.9);
    font-size: 0.8125rem;
    margin-bottom: 0;
    line-height: 1.6;
}

.info-banner .steps {
    margin-top: 0.75rem;
    padding-left: 1.25rem;
}

.info-banner .steps li {
    color: rgba(255,255,255,0.9);
    font-size: 0.8125rem;
    margin-bottom: 0.25rem;
}

/* Variable Helper */
.variable-helper {
    background: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-top: 1rem;
}

.variable-helper-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 0.5rem;
}

.variable-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.variable-tag {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-family: 'Courier New', monospace;
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 0.375rem;
    color: #5e72e4;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.variable-tag:hover {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border-color: transparent;
}

.variable-tag-auto {
    border-color: #2dce89;
    color: #2dce89;
}
.variable-tag-auto:hover {
    background: linear-gradient(310deg, #2dce89 0%, #26a96d 100%);
    color: #fff;
    border-color: transparent;
}

.variable-section-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.35rem;
    margin-top: 0.5rem;
}

.variable-info-box {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 0.5rem;
    padding: 0.625rem 0.75rem;
    font-size: 0.75rem;
    color: #166534;
    margin-bottom: 0.5rem;
}

/* Modal Styles */
.modal-soft .modal-content {
    border-radius: 1rem;
    border: none;
    box-shadow: 0 23px 45px -11px hsla(0,0%,8%,.25);
}

.modal-soft .modal-header {
    border-bottom: 1px solid #e9ecef;
    padding: 1.25rem 1.5rem;
}

.modal-soft .modal-title {
    font-weight: 700;
    color: #344767;
}

.modal-soft .modal-body {
    padding: 1.5rem;
}

.modal-soft .modal-footer {
    border-top: 1px solid #e9ecef;
    padding: 1rem 1.5rem;
}

.form-label-soft {
    font-size: 0.875rem;
    font-weight: 600;
    color: #344767;
    margin-bottom: 0.5rem;
}

.form-control-soft {
    border-radius: 0.5rem;
    border: 1px solid #e9ecef;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    transition: all 0.15s ease-in-out;
}

.form-control-soft:focus {
    border-color: #5e72e4;
    box-shadow: 0 0 0 2px rgba(94, 114, 228, 0.25);
}

.form-select-soft {
    border-radius: 0.5rem;
    border: 1px solid #e9ecef;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
}

.textarea-soft {
    min-height: 150px;
    resize: vertical;
}

.btn-cancel {
    background: #f8f9fa;
    color: #67748e;
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    padding: 0.625rem 1.25rem;
    font-weight: 600;
}

.btn-cancel:hover {
    background: #e9ecef;
    color: #344767;
}
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="page-header-row">
        <div>
            <h4 class="page-title">Template Pesan</h4>
            <p class="page-subtitle">Kelola template pesan WhatsApp Anda</p>
        </div>
        <button class="btn-soft-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
            <i class="ni ni-fat-add"></i>
            <span>Tambah Template</span>
        </button>
    </div>

    {{-- Info Banner --}}
    <div class="info-banner">
        <h6><i class="fab fa-whatsapp me-2"></i>Cara Menggunakan Template untuk WA Blast</h6>
        <p>Template yang dibuat di sini perlu di-submit ke Meta (WhatsApp) untuk review sebelum bisa digunakan di WA Blast.</p>
        <ol class="steps">
            <li>Buat template baru dengan tombol "Tambah Template"</li>
            <li>Klik tombol <strong>"Submit ke WhatsApp"</strong> pada template yang sudah dibuat</li>
            <li>Tunggu approval dari Meta (biasanya beberapa menit - beberapa jam)</li>
            <li>Klik <strong>"Sync Templates"</strong> di halaman <a href="{{ route('whatsapp.index') }}" style="color: #fff; text-decoration: underline;">Nomor WhatsApp</a> untuk memperbarui status</li>
            <li>Template yang sudah <span class="badge bg-success">Approved</span> akan otomatis muncul di halaman <a href="{{ route('whatsapp.campaigns.create') }}" style="color: #fff; text-decoration: underline;">Buat Kampanye WA Blast</a></li>
        </ol>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    {{-- Template Card --}}
    <div class="template-card">
        <div class="template-card-header">
            <h6 class="template-card-title">Daftar Template</h6>
            <span class="badge bg-gradient-primary">{{ isset($templates) ? $templates->count() : 0 }} template</span>
        </div>
        <div class="template-card-body">
            @if(isset($templates) && $templates->count() > 0)
            {{-- Template Grid --}}
            <div class="template-grid">
                @foreach($templates as $template)
                <div class="template-item" data-template-id="{{ $template->id }}" data-kategori="{{ $template->kategori ?? 'other' }}">
                    {{-- Hidden full content for edit --}}
                    <script type="text/template" class="template-full-content">{{ $template->body ?? '' }}</script>
                    {{-- Card Header --}}
                    <div class="template-item-header">
                        <h6 class="template-item-name" title="{{ $template->nama_tampilan ?? $template->nama_template }}">{{ $template->nama_tampilan ?? $template->nama_template }}</h6>
                        <span class="template-item-category">{{ $template->kategori ?? 'Umum' }}</span>
                    </div>
                    {{-- Card Body --}}
                    <div class="template-item-body">
                        <p class="template-item-preview">{{ $template->body ?? 'Tidak ada isi pesan' }}</p>
                    </div>
                    {{-- Card Footer --}}
                    <div class="template-item-footer">
                        @if($template->status == 'disetujui')
                            <span class="status-badge status-approved"><i class="ni ni-check-bold"></i> Approved Meta</span>
                        @elseif($template->status == 'diajukan')
                            <span class="status-badge status-pending"><i class="ni ni-time-alarm"></i> Menunggu Review Meta</span>
                        @elseif($template->status == 'ditolak')
                            <span class="status-badge status-rejected"><i class="ni ni-fat-remove"></i> Ditolak Meta</span>
                        @else
                            <span class="status-badge status-draft"><i class="ni ni-single-copy-04"></i> Draft</span>
                        @endif
                        <div class="template-item-actions">
                            @if($template->status == 'draft' || $template->status == 'ditolak')
                                <form id="submitMetaForm-{{ $template->id }}" action="{{ route('template.submit-meta', $template->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="button" class="btn-template-action btn-submit-meta" title="Submit ke WhatsApp untuk review" onclick="konfirmasiSubmitMeta({{ $template->id }})">
                                        <i class="fab fa-whatsapp"></i> Submit ke WA
                                    </button>
                                </form>
                            @endif
                            <button class="btn-template-action btn-edit" onclick="editTemplate({{ $template->id }})">
                                <i class="ni ni-ruler-pencil"></i> Edit
                            </button>
                            <button class="btn-template-action btn-delete" onclick="hapusTemplate({{ $template->id }})">
                                <i class="ni ni-fat-remove"></i>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            {{-- Empty State --}}
            <div class="empty-state-container" id="templateEmptyState">
                <div class="empty-state-icon">
                    <i class="ni ni-single-copy-04"></i>
                </div>
                <h5 class="empty-state-title">Belum ada template pesan</h5>
                <p class="empty-state-subtitle">Template memudahkan pengiriman pesan massal ke pelanggan Anda</p>
                <button class="empty-state-btn" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                    <i class="ni ni-fat-add"></i>
                    <span>Tambah Template</span>
                </button>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Create Template Modal --}}
<div class="modal fade modal-soft" id="createTemplateModal" tabindex="-1" aria-labelledby="createTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createTemplateModalLabel">Tambah Template Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createTemplateForm" action="{{ route('template.store') }}" method="POST">
                    @csrf

                    {{-- Pilih Template Siap Pakai --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Mulai dari Template Siap Pakai <small class="text-muted">(opsional)</small></label>
                        <select class="form-select form-select-soft" id="quickTemplate" onchange="applyQuickTemplate()">
                            <option value="">-- Tulis sendiri --</option>
                            <optgroup label="📌 Umum (Semua Usaha)">
                                <option value="welcome">👋 Selamat Datang</option>
                                <option value="thank_you">🙏 Ucapan Terima Kasih</option>
                                <option value="promo">🔥 Promo / Diskon</option>
                                <option value="payment_remind">💰 Pengingat Pembayaran</option>
                                <option value="event_invite">🎉 Undangan Acara / Event</option>
                                <option value="feedback">⭐ Minta Ulasan / Feedback</option>
                            </optgroup>
                            <optgroup label="🏪 Toko / Retail / Online Shop">
                                <option value="order_confirm">📦 Konfirmasi Pesanan</option>
                                <option value="shipping">🚚 Notifikasi Pengiriman</option>
                                <option value="restock">🔔 Produk Tersedia Kembali</option>
                            </optgroup>
                            <optgroup label="🔧 Jasa / Layanan">
                                <option value="booking_confirm">📋 Konfirmasi Booking</option>
                                <option value="appointment_remind">⏰ Pengingat Jadwal</option>
                                <option value="service_done">✅ Layanan Selesai</option>
                            </optgroup>
                            <optgroup label="🎓 Sekolah / Pendidikan">
                                <option value="school_info">📢 Info Sekolah / Pengumuman</option>
                                <option value="school_payment">💳 Tagihan SPP / Biaya</option>
                                <option value="school_event">🏫 Undangan Kegiatan Sekolah</option>
                            </optgroup>
                            <optgroup label="🏢 Kantor / Perusahaan">
                                <option value="meeting_invite">📅 Undangan Rapat / Meeting</option>
                                <option value="company_announce">📣 Pengumuman Perusahaan</option>
                            </optgroup>
                            <optgroup label="🍽️ F&B / Restoran / Kafe">
                                <option value="order_ready">🍔 Pesanan Siap</option>
                                <option value="menu_promo">🍕 Promo Menu Baru</option>
                            </optgroup>
                            <optgroup label="🏥 Kesehatan / Klinik">
                                <option value="appointment_health">🩺 Pengingat Jadwal Kontrol</option>
                                <option value="health_promo">💊 Promo Layanan Kesehatan</option>
                            </optgroup>
                        </select>
                    </div>

                    <hr class="my-3">

                    {{-- Nama Template --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Nama Template</label>
                        <input type="text" name="nama" id="createNama" class="form-control form-control-soft" placeholder="Contoh: Promo Akhir Tahun" required>
                    </div>

                    {{-- Kategori --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Kategori</label>
                        <select name="kategori" id="createKategori" class="form-select form-select-soft" required>
                            <option value="">Pilih kategori...</option>
                            <option value="marketing">Marketing / Promosi</option>
                            <option value="utility">Utility (Notifikasi, Transaksi)</option>
                            <option value="authentication">Authentication (OTP, Verifikasi)</option>
                        </select>
                    </div>

                    {{-- Bahasa --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Bahasa</label>
                        <select name="bahasa" class="form-select form-select-soft" required>
                            <option value="id">Indonesia</option>
                            <option value="en_US">English (US)</option>
                        </select>
                    </div>

                    {{-- Isi Pesan --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Isi Pesan</label>
                        <textarea name="konten" id="createKonten" class="form-control form-control-soft textarea-soft" placeholder="Tulis isi pesan di sini..." required></textarea>
                        <small class="text-muted">Gunakan variabel di bawah agar pesan otomatis diisi data penerima.</small>
                    </div>

                    {{-- Variable Buttons --}}
                    <div class="variable-helper">
                        <div class="variable-helper-title">
                            <i class="ni ni-bulb-61 me-1" style="color: #fbcf33;"></i>
                            Sisipkan variabel ke pesan:
                        </div>

                        <div class="variable-info-box">
                            Variabel akan otomatis diganti saat pesan dikirim.<br>
                            Contoh: <code>Halo @{{nama}}</code> → <code>Halo Budi</code>, <code>Halo Siti</code>, dst.
                        </div>

                        <div class="variable-tags">
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('nama', 'createKonten')" title="Nama penerima dari data Kontak">+ Nama Penerima</span>
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('telepon', 'createKonten')" title="No HP penerima dari data Kontak">+ No HP</span>
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('email', 'createKonten')" title="Email penerima dari data Kontak">+ Email</span>
                        </div>
                    </div>

                    {{-- Live Preview --}}
                    <div class="mt-3" id="createPreviewWrap" style="display:none;">
                        <label class="form-label-soft">Preview — Contoh pesan yang diterima pelanggan:</label>
                        <div class="alert border" id="createPreview" style="white-space: pre-wrap; font-size: 0.875rem; background: #f0fdf4; border-color: #bbf7d0 !important;"></div>
                        <small class="text-muted"><i class="ni ni-bell-55 me-1"></i>Nama, No HP, Email otomatis diisi dari data Kontak setiap penerima.</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Batal</button>
                <button type="submit" form="createTemplateForm" class="btn-soft-primary">
                    <i class="ni ni-check-bold"></i>
                    <span>Simpan Template</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Edit Template Modal --}}
<div class="modal fade modal-soft" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editTemplateForm" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label-soft">Nama Template</label>
                        <input type="text" name="nama" id="editNama" class="form-control form-control-soft" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-soft">Kategori</label>
                        <select name="kategori" id="editKategori" class="form-select form-select-soft" required>
                            <option value="marketing">Marketing / Promosi</option>
                            <option value="utility">Utility (Notifikasi, Transaksi)</option>
                            <option value="authentication">Authentication (OTP, Verifikasi)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-soft">Isi Pesan</label>
                        <textarea name="konten" id="editKonten" class="form-control form-control-soft textarea-soft" required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" data-bs-dismiss="modal">Batal</button>
                <button type="submit" form="editTemplateForm" class="btn-soft-primary">
                    <i class="ni ni-check-bold"></i>
                    <span>Update Template</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('dashboard')
@verbatim
<script>
// Template siap pakai
const quickTemplates = {
    // === UMUM (Semua Usaha) ===
    welcome: {
        nama: 'Selamat Datang',
        kategori: 'utility',
        konten: 'Halo {{nama}}, selamat datang! 👋\n\nTerima kasih telah bergabung bersama kami. Kami siap membantu kebutuhan Anda.\n\nJika ada pertanyaan, silakan hubungi kami kapan saja.'
    },
    thank_you: {
        nama: 'Ucapan Terima Kasih',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, terima kasih atas kepercayaan Anda! 🙏\n\nKami sangat menghargai Anda sebagai pelanggan kami. Semoga kami bisa terus memberikan layanan terbaik.\n\nSampai jumpa kembali!'
    },
    promo: {
        nama: 'Promo Diskon',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, ada promo spesial untuk Anda! 🔥\n\nDapatkan diskon hingga 50% untuk semua produk kami.\n\nPromo berlaku sampai akhir bulan ini. Jangan sampai kelewatan!\n\nInfo lebih lanjut hubungi kami.'
    },
    payment_remind: {
        nama: 'Pengingat Pembayaran',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini pengingat pembayaran Anda. 💰\n\nMohon segera selesaikan pembayaran agar layanan bisa diproses.\n\nJika sudah bayar, abaikan pesan ini. Terima kasih!'
    },
    event_invite: {
        nama: 'Undangan Acara',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, Anda diundang ke acara spesial kami! 🎉\n\nJangan lewatkan! Kami tunggu kehadiran Anda.\n\nUntuk info lengkap, silakan hubungi kami.'
    },
    feedback: {
        nama: 'Minta Ulasan',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, terima kasih telah menggunakan layanan kami! ⭐\n\nKami ingin tahu pendapat Anda. Mohon luangkan waktu sebentar untuk memberikan ulasan.\n\nMasukan Anda sangat berarti bagi kami. Terima kasih! 🙏'
    },

    // === TOKO / RETAIL / ONLINE SHOP ===
    order_confirm: {
        nama: 'Konfirmasi Pesanan',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda sudah kami terima! 📦\n\nPesanan sedang diproses dan kami akan kabari jika sudah dikirim.\n\nTerima kasih telah berbelanja!'
    },
    shipping: {
        nama: 'Notifikasi Pengiriman',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda sudah dikirim! 🚚\n\nSilakan cek status pengiriman secara berkala.\n\nTerima kasih telah berbelanja!'
    },
    restock: {
        nama: 'Produk Tersedia Kembali',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, kabar baik! 🔔\n\nProduk yang Anda tunggu sudah tersedia kembali.\n\nSegera pesan sebelum kehabisan lagi. Stok terbatas!'
    },

    // === JASA / LAYANAN ===
    booking_confirm: {
        nama: 'Konfirmasi Booking',
        kategori: 'utility',
        konten: 'Halo {{nama}}, booking Anda sudah dikonfirmasi! 📋\n\nMohon hadir tepat waktu sesuai jadwal.\n\nJika perlu reschedule, silakan hubungi kami. Terima kasih!'
    },
    appointment_remind: {
        nama: 'Pengingat Jadwal',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini pengingat untuk jadwal Anda besok. ⏰\n\nJika perlu reschedule, silakan hubungi kami segera.\n\nTerima kasih!'
    },
    service_done: {
        nama: 'Layanan Selesai',
        kategori: 'utility',
        konten: 'Halo {{nama}}, layanan Anda sudah selesai! ✅\n\nTerima kasih telah mempercayakan kepada kami.\n\nSemoga puas dengan hasilnya! 🙏'
    },

    // === SEKOLAH / PENDIDIKAN ===
    school_info: {
        nama: 'Info Sekolah',
        kategori: 'utility',
        konten: 'Kepada Bapak/Ibu {{nama}}, 📢\n\nDengan ini kami sampaikan informasi penting dari sekolah.\n\nSilakan hubungi pihak sekolah untuk informasi lebih lanjut.\n\nTerima kasih atas perhatiannya.'
    },
    school_payment: {
        nama: 'Tagihan SPP',
        kategori: 'utility',
        konten: 'Kepada Bapak/Ibu {{nama}}, 💳\n\nMohon segera melakukan pembayaran SPP/biaya sekolah sebelum jatuh tempo.\n\nJika sudah membayar, abaikan pesan ini.\n\nTerima kasih.'
    },
    school_event: {
        nama: 'Undangan Kegiatan Sekolah',
        kategori: 'marketing',
        konten: 'Kepada Bapak/Ibu {{nama}}, 🏫\n\nDengan ini kami mengundang Bapak/Ibu untuk hadir pada kegiatan sekolah.\n\nKehadiran Bapak/Ibu sangat kami harapkan.\n\nTerima kasih.'
    },

    // === KANTOR / PERUSAHAAN ===
    meeting_invite: {
        nama: 'Undangan Rapat',
        kategori: 'utility',
        konten: 'Halo {{nama}}, 📅\n\nAnda diundang untuk menghadiri rapat.\n\nMohon konfirmasi kehadiran Anda. Terima kasih.'
    },
    company_announce: {
        nama: 'Pengumuman Perusahaan',
        kategori: 'utility',
        konten: 'Kepada {{nama}}, 📣\n\nBerikut informasi penting dari perusahaan.\n\nDemikian pengumuman ini disampaikan. Terima kasih atas perhatiannya.'
    },

    // === F&B / RESTORAN / KAFE ===
    order_ready: {
        nama: 'Pesanan Siap',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda sudah siap! 🍔\n\nSilakan diambil.\n\nSelamat menikmati! 😊'
    },
    menu_promo: {
        nama: 'Promo Menu Baru',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, ada menu baru yang wajib dicoba! 🍕\n\nDapatkan harga spesial untuk menu terbaru kami.\n\nYuk, pesan sekarang!'
    },

    // === KESEHATAN / KLINIK ===
    appointment_health: {
        nama: 'Pengingat Jadwal Kontrol',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini pengingat jadwal kontrol kesehatan Anda. 🩺\n\nMohon hadir tepat waktu.\n\nJika perlu ubah jadwal, segera hubungi kami. Terima kasih!'
    },
    health_promo: {
        nama: 'Promo Layanan Kesehatan',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, jaga kesehatan Anda! 💊\n\nDapatkan promo spesial untuk layanan kesehatan kami.\n\nSegera daftarkan diri Anda. Kesehatan adalah investasi terbaik!'
    }
};

// Apply template siap pakai
function applyQuickTemplate() {
    const selected = document.getElementById('quickTemplate').value;
    if (!selected) return;
    
    const t = quickTemplates[selected];
    document.getElementById('createNama').value = t.nama;
    document.getElementById('createKategori').value = t.kategori;
    document.getElementById('createKonten').value = t.konten;
    updatePreview('createKonten', 'createPreview', 'createPreviewWrap');
}

// Insert variable at cursor position in textarea
function insertVariable(varName, textareaId) {
    const textarea = document.getElementById(textareaId);
    const variable = '{{' + varName + '}}';
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    
    textarea.value = text.substring(0, start) + variable + text.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + variable.length;
    textarea.focus();
    updatePreview(textareaId, textareaId === 'createKonten' ? 'createPreview' : null, textareaId === 'createKonten' ? 'createPreviewWrap' : null);
}

// Live preview
function updatePreview(textareaId, previewId, wrapId) {
    if (!previewId) return;
    const text = document.getElementById(textareaId).value;
    const preview = document.getElementById(previewId);
    const wrap = document.getElementById(wrapId);
    
    if (text.trim()) {
        // Show preview with clear labels showing what gets replaced
        let html = text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\{\{nama\}\}/g, '<span style="background:#2dce89;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Nama Penerima</span>')
            .replace(/\{\{telepon\}\}/g, '<span style="background:#2dce89;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">No HP Penerima</span>')
            .replace(/\{\{email\}\}/g, '<span style="background:#2dce89;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Email Penerima</span>')
            .replace(/\{\{produk\}\}/g, '<span style="background:#5e72e4;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Produk</span>')
            .replace(/\{\{harga\}\}/g, '<span style="background:#5e72e4;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Harga</span>')
            .replace(/\{\{tanggal\}\}/g, '<span style="background:#5e72e4;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Tanggal</span>')
            .replace(/\{\{no_order\}\}/g, '<span style="background:#5e72e4;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">No Order</span>')
            .replace(/\n/g, '<br>');
        preview.innerHTML = html;
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
}

// Auto-update preview on typing
document.addEventListener('DOMContentLoaded', function() {
    const createKonten = document.getElementById('createKonten');
    if (createKonten) {
        createKonten.addEventListener('input', function() {
            updatePreview('createKonten', 'createPreview', 'createPreviewWrap');
        });
    }
});

// Edit template
function editTemplate(id) {
    const card = document.querySelector('[data-template-id="' + id + '"]');
    if (!card) {
        alert('Template tidak ditemukan. Coba refresh halaman.');
        return;
    }
    
    const nama = card.querySelector('.template-item-name').textContent.trim();
    const kategori = card.getAttribute('data-kategori') || 'other';
    const fullContentEl = card.querySelector('.template-full-content');
    const konten = fullContentEl ? fullContentEl.textContent.trim() : '';
    
    document.getElementById('editNama').value = nama;
    document.getElementById('editKategori').value = kategori;
    document.getElementById('editKonten').value = konten;
    document.getElementById('editTemplateForm').action = '/template/' + id;
    new bootstrap.Modal(document.getElementById('editTemplateModal')).show();
}

// Konfirmasi submit ke Meta
function konfirmasiSubmitMeta(id) {
    Swal.fire({
        title: 'Submit ke WhatsApp?',
        html: '<div style="text-align:left;font-size:14px;line-height:1.6">' +
            '<p>Template ini akan dikirim ke <strong>Meta (WhatsApp)</strong> untuk di-review.</p>' +
            '<ul style="padding-left:18px;margin:8px 0">' +
            '<li>Proses review biasanya <strong>beberapa menit</strong> hingga 24 jam</li>' +
            '<li>Setelah di-approve, template bisa dipakai untuk <strong>WA Blast</strong></li>' +
            '</ul></div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2dce89',
        cancelButtonColor: '#8898aa',
        confirmButtonText: '<i class="fab fa-whatsapp"></i> Ya, Submit Sekarang',
        cancelButtonText: 'Batal',
        customClass: { popup: 'swal-wide' }
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('submitMetaForm-' + id).submit();
        }
    });
}

// Hapus template
async function hapusTemplate(id) {
    const result = await Swal.fire({
        title: 'Hapus Template?',
        text: 'Template yang dihapus tidak dapat dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f5365c',
        cancelButtonColor: '#8898aa',
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal'
    });
    if (!result.isConfirmed) return;
    
    const res = await fetch('/template/' + id, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        }
    });
    const data = await res.json();
    if (data.success) {
        Swal.fire({ title: 'Terhapus!', text: 'Template berhasil dihapus.', icon: 'success', timer: 1500, showConfirmButton: false });
        setTimeout(() => location.reload(), 1500);
    } else {
        Swal.fire('Gagal', 'Gagal menghapus template.', 'error');
    }
}
</script>
@endverbatim
@endpush