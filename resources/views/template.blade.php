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

    {{-- Alert --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
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
                    <script type="text/template" class="template-full-content">{{ $template->isi_body ?? '' }}</script>
                    {{-- Card Header --}}
                    <div class="template-item-header">
                        <h6 class="template-item-name" title="{{ $template->nama }}">{{ $template->nama }}</h6>
                        <span class="template-item-category">{{ $template->kategori ?? 'Umum' }}</span>
                    </div>
                    {{-- Card Body --}}
                    <div class="template-item-body">
                        <p class="template-item-preview">{{ $template->isi_body ?? 'Tidak ada isi pesan' }}</p>
                    </div>
                    {{-- Card Footer --}}
                    <div class="template-item-footer">
                        @if($template->status == 'disetujui')
                            <span class="status-badge status-approved"><i class="ni ni-check-bold"></i> Approved</span>
                        @elseif($template->status == 'diajukan')
                            <span class="status-badge status-pending"><i class="ni ni-time-alarm"></i> Pending</span>
                        @else
                            <span class="status-badge status-draft"><i class="ni ni-single-copy-04"></i> Draft</span>
                        @endif
                        <div class="template-item-actions">
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
                    {{-- Nama Template --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Nama Template</label>
                        <input type="text" name="nama" class="form-control form-control-soft" placeholder="Contoh: Selamat Datang Pelanggan Baru" required>
                    </div>

                    {{-- Kategori --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Kategori</label>
                        <select name="kategori" class="form-select form-select-soft" required>
                            <option value="">Pilih kategori...</option>
                            <option value="marketing">Marketing / Promosi</option>
                            <option value="utility">Utility</option>
                            <option value="authentication">Authentication</option>
                            <option value="transactional">Transaksional</option>
                            <option value="notification">Notifikasi</option>
                            <option value="greeting">Sapaan / Ucapan</option>
                            <option value="follow_up">Follow Up</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    {{-- Isi Pesan --}}
                    <div class="mb-3">
                        <label class="form-label-soft">Isi Pesan</label>
                        <textarea name="konten" class="form-control form-control-soft textarea-soft" placeholder="Tulis isi pesan template Anda di sini...

Contoh:
Halo @{{ nama }}, terima kasih telah berbelanja di toko kami!

Pesanan Anda untuk @{{ produk }} sedang diproses." required></textarea>
                    </div>

                    {{-- Variable Helper --}}
                    <div class="variable-helper">
                        <div class="variable-helper-title">
                            <i class="ni ni-bulb-61 me-1" style="color: #fbcf33;"></i>
                            Variable yang tersedia (klik untuk menyalin):
                        </div>
                        <div class="variable-tags">
                            <span class="variable-tag" data-var="nama" onclick="copyVariable(this)">@{{ nama }}</span>
                            <span class="variable-tag" data-var="telepon" onclick="copyVariable(this)">@{{ telepon }}</span>
                            <span class="variable-tag" data-var="email" onclick="copyVariable(this)">@{{ email }}</span>
                            <span class="variable-tag" data-var="produk" onclick="copyVariable(this)">@{{ produk }}</span>
                            <span class="variable-tag" data-var="harga" onclick="copyVariable(this)">@{{ harga }}</span>
                            <span class="variable-tag" data-var="tanggal" onclick="copyVariable(this)">@{{ tanggal }}</span>
                            <span class="variable-tag" data-var="no_order" onclick="copyVariable(this)">@{{ no_order }}</span>
                        </div>
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
                            <option value="utility">Utility</option>
                            <option value="authentication">Authentication</option>
                            <option value="transactional">Transaksional</option>
                            <option value="notification">Notifikasi</option>
                            <option value="greeting">Sapaan / Ucapan</option>
                            <option value="follow_up">Follow Up</option>
                            <option value="other">Lainnya</option>
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
<script>
// Copy variable to clipboard
function copyVariable(element) {
    const varName = element.getAttribute('data-var');
    // Wrap with double curly braces for WhatsApp placeholder format
    const variable = '{{' + varName + '}}';
    
    navigator.clipboard.writeText(variable).then(function() {
        const originalText = element.innerText;
        element.innerText = 'Tersalin!';
        element.style.background = 'linear-gradient(310deg, #17ad37 0%, #98ec2d 100%)';
        element.style.color = '#fff';
        element.style.borderColor = 'transparent';
        
        setTimeout(function() {
            element.innerText = originalText;
            element.style.background = '';
            element.style.color = '';
            element.style.borderColor = '';
        }, 1000);
    });
}

// Edit template - fetch data from card element (safe from JS escape issues)
function editTemplate(id) {
    const card = document.querySelector('[data-template-id="' + id + '"]');
    if (!card) {
        ClientPopup.actionFailed('Template tidak ditemukan. Coba refresh halaman.');
        return;
    }
    
    // Get data from card elements
    const nama = card.querySelector('.template-item-name').textContent.trim();
    const kategori = card.getAttribute('data-kategori') || 'other';
    
    // Get full content from hidden script element (not truncated)
    const fullContentEl = card.querySelector('.template-full-content');
    const konten = fullContentEl ? fullContentEl.textContent.trim() : '';
    
    document.getElementById('editNama').value = nama;
    document.getElementById('editKategori').value = kategori;
    document.getElementById('editKonten').value = konten;
    document.getElementById('editTemplateForm').action = '/template/' + id;
    new bootstrap.Modal(document.getElementById('editTemplateModal')).show();
}

// Hapus template
async function hapusTemplate(id) {
    const confirmed = await ClientPopup.confirm({
        title: 'Hapus Template?',
        text: 'Template yang dihapus tidak dapat dikembalikan.',
        confirmText: 'Ya, Hapus',
        cancelText: 'Batal'
    });
    
    if (confirmed) {
        ClientPopup.loading('Menghapus template...');
        
        fetch('/template/' + id, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                ClientPopup.actionSuccess('Template berhasil dihapus').then(() => location.reload());
            } else {
                ClientPopup.actionFailed('Template belum bisa dihapus saat ini.');
            }
        })
        .catch(() => ClientPopup.connectionError());
    }
}
</script>
@endpush