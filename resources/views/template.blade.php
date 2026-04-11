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

@media (max-width: 991.98px) {
    .container-fluid.py-4 {
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }

    .page-header-row {
        flex-direction: column;
        align-items: stretch;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .page-title {
        font-size: 1.35rem;
    }

    .btn-soft-primary {
        justify-content: center;
        width: 100%;
        padding: 0.85rem 1rem;
    }

    .info-banner {
        padding: 1rem;
        border-radius: 1rem;
    }

    .info-banner h6 {
        font-size: 1rem;
        line-height: 1.4;
    }

    .info-banner p,
    .info-banner .steps li {
        font-size: 0.85rem;
        line-height: 1.7;
    }

    .template-card {
        border-radius: 1.1rem;
    }

    .template-card-header,
    .template-card-body {
        padding: 1rem;
    }

    .template-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .template-card-header .badge {
        align-self: flex-start;
        padding: 0.5rem 0.85rem;
        border-radius: 0.75rem;
        font-size: 0.8rem;
        text-transform: uppercase;
    }

    .template-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .template-item {
        border-radius: 1rem;
    }

    .template-item-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 1rem;
    }

    .template-item-name {
        max-width: 100%;
        white-space: normal;
        line-height: 1.4;
    }

    .template-item-category {
        font-size: 0.7rem;
        letter-spacing: 0.04em;
    }

    .template-item-body {
        padding: 1rem;
    }

    .template-item-preview {
        -webkit-line-clamp: 3;
        min-height: auto;
        font-size: 0.88rem;
    }

    .template-item-footer {
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
        padding: 1rem;
    }

    .status-badge {
        justify-content: center;
        width: 100%;
        padding: 0.65rem 0.9rem;
        border-radius: 0.85rem;
    }

    .template-item-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.6rem;
        width: 100%;
    }

    .btn-template-action {
        justify-content: center;
        min-height: 2.75rem;
        padding: 0.7rem 0.75rem;
        font-size: 0.78rem;
        border-radius: 0.75rem;
        width: 100%;
    }

    .btn-template-action i {
        font-size: 0.85rem;
    }

    .empty-state-container {
        padding: 2.5rem 1.25rem;
    }

    .empty-state-icon {
        width: 5rem;
        height: 5rem;
        border-radius: 1.25rem;
    }

    .empty-state-btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 575.98px) {
    .page-title {
        font-size: 1.2rem;
    }

    .page-subtitle {
        font-size: 0.82rem;
        line-height: 1.6;
    }

    .info-banner {
        margin-left: -0.1rem;
        margin-right: -0.1rem;
    }

    .info-banner .steps {
        padding-left: 1rem;
    }

    .template-card-title {
        font-size: 1.2rem;
    }

    .template-item-actions {
        grid-template-columns: 1fr;
    }

    .btn-template-action {
        font-size: 0.82rem;
    }

    .modal-soft .modal-body,
    .modal-soft .modal-header,
    .modal-soft .modal-footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .variable-tags {
        gap: 0.4rem;
    }

    .variable-tag {
        width: 100%;
        justify-content: center;
    }
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
            <li>Halaman ini akan auto-sync status Meta maksimal setiap {{ $templateAutoSyncCooldownMinutes ?? 5 }} menit. Anda tetap bisa klik <strong>"Sync Templates"</strong> di halaman <a href="{{ route('whatsapp.index') }}" style="color: #fff; text-decoration: underline;">Nomor WhatsApp</a> jika ingin paksa refresh.</li>
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
            <div>
                <h6 class="template-card-title mb-0">Daftar Template</h6>
                <p class="text-xs text-muted mb-0 mt-1">
                    Status Meta auto-sync maksimal setiap {{ $templateAutoSyncCooldownMinutes ?? 5 }} menit
                    @if(!empty($latestTemplateSyncAt))
                        • terakhir sync {{ $latestTemplateSyncAt->format('d M Y H:i') }}
                    @endif
                </p>
            </div>
            <span class="badge bg-gradient-primary">{{ isset($templates) ? $templates->count() : 0 }} internal / {{ $syncedTemplateCount ?? 0 }} Meta</span>
        </div>
        <div class="template-card-body">
            @if(isset($metaOnlyTemplates) && $metaOnlyTemplates->count() > 0)
            <div class="alert alert-info text-white" style="background: linear-gradient(310deg, #17c1e8 0%, #3a416f 100%); border: 0;">
                Halaman ini sekarang menampilkan status hasil sync Meta. Ada {{ $metaOnlyTemplates->count() }} template yang hanya ada di Meta dan belum dibuat dari form internal Talkabiz.
            </div>
            @endif
            @if(isset($templates) && $templates->count() > 0)
            {{-- Template Grid --}}
            <div class="template-grid">
                @foreach($templates as $template)
                @php($effectiveStatus = $template->effective_status ?? $template->status)
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
                        @if($effectiveStatus == 'disetujui')
                            <span class="status-badge status-approved"><i class="ni ni-check-bold"></i> Approved Meta</span>
                        @elseif($effectiveStatus == 'diajukan')
                            <span class="status-badge status-pending"><i class="ni ni-time-alarm"></i> Menunggu Review Meta</span>
                        @elseif($effectiveStatus == 'ditolak')
                            <span class="status-badge status-rejected"><i class="ni ni-fat-remove"></i> Ditolak Meta</span>
                        @else
                            <span class="status-badge status-draft"><i class="ni ni-single-copy-04"></i> Draft</span>
                        @endif
                        <div class="template-item-actions">
                            @if($effectiveStatus == 'draft' || $effectiveStatus == 'ditolak')
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
                    @if(!empty($template->meta_synced))
                    <div style="padding: 0 1.25rem 1rem; font-size: 0.75rem; color: #8392ab;">
                        Status disinkron dari Meta{{ !empty($template->meta_template_name) ? ': ' . $template->meta_template_name : '' }}
                        @if(!empty($template->meta_rejection_reason))
                            <br>Alasan: {{ $template->meta_rejection_reason }}
                        @endif
                    </div>
                    @endif
                </div>
                @endforeach
            </div>

            @if(isset($metaOnlyTemplates) && $metaOnlyTemplates->count() > 0)
            <div class="mt-4 pt-3" style="border-top: 1px solid #e9ecef;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="template-card-title mb-0">Template Dari Meta</h6>
                    <span class="badge bg-gradient-info">{{ $metaOnlyTemplates->count() }} template</span>
                </div>
                <div class="template-grid">
                    @foreach($metaOnlyTemplates as $template)
                    <div class="template-item" data-kategori="{{ strtolower($template->category ?? 'other') }}">
                        <div class="template-item-header">
                            <h6 class="template-item-name" title="{{ $template->name }}">{{ $template->name }}</h6>
                            <span class="template-item-category">{{ strtolower($template->category ?? 'umum') }}</span>
                        </div>
                        <div class="template-item-body">
                            <p class="template-item-preview">{{ $template->getBodyText() ?? $template->sample_text ?? 'Template hasil sync dari Meta' }}</p>
                        </div>
                        <div class="template-item-footer">
                            @if($template->status === 'approved')
                                <span class="status-badge status-approved"><i class="ni ni-check-bold"></i> Approved Meta</span>
                            @elseif($template->status === 'rejected')
                                <span class="status-badge status-rejected"><i class="ni ni-fat-remove"></i> Ditolak Meta</span>
                            @else
                                <span class="status-badge status-pending"><i class="ni ni-time-alarm"></i> Status Meta: {{ ucfirst($template->status) }}</span>
                            @endif
                            <div style="font-size: 0.75rem; color: #8392ab;">Hasil sync Meta</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
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
                                <option value="otp_login">🔐 Kode Login / OTP</option>
                            </optgroup>
                            <optgroup label="🏪 Toko / Retail / Online Shop">
                                <option value="order_confirm">📦 Konfirmasi Pesanan</option>
                                <option value="shipping">🚚 Notifikasi Pengiriman</option>
                                <option value="restock">🔔 Produk Tersedia Kembali</option>
                                <option value="payment_due">🧾 Tagihan Pesanan</option>
                            </optgroup>
                            <optgroup label="🔧 Jasa / Layanan">
                                <option value="booking_confirm">📋 Konfirmasi Booking</option>
                                <option value="appointment_remind">⏰ Pengingat Jadwal</option>
                                <option value="service_done">✅ Layanan Selesai</option>
                                <option value="service_verification">🛡️ Verifikasi Jadwal</option>
                            </optgroup>
                            <optgroup label="🎓 Sekolah / Pendidikan">
                                <option value="school_info">📢 Info Sekolah / Pengumuman</option>
                                <option value="school_payment">💳 Tagihan SPP / Biaya</option>
                                <option value="school_event">🏫 Undangan Kegiatan Sekolah</option>
                                <option value="school_otp">🔢 Kode Verifikasi Orang Tua</option>
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
                        <small class="text-muted d-block mt-1">Nama aman untuk Meta akan dibuat otomatis dari nama ini.</small>
                        <small class="text-primary d-block mt-1" id="createMetaNamePreview" style="display:none;"></small>
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
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('kode', 'createKonten')" title="Kode verifikasi atau referensi">+ Kode</span>
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('otp', 'createKonten')" title="Kode OTP verifikasi">+ OTP</span>
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('no_order', 'createKonten')" title="Nomor order atau invoice">+ No Order</span>
                            <span class="variable-tag variable-tag-auto" onclick="insertVariable('tanggal', 'createKonten')" title="Tanggal jatuh tempo atau jadwal">+ Tanggal</span>
                        </div>
                    </div>

                    {{-- Live Preview --}}
                    <div class="mt-3" id="createPreviewWrap" style="display:none;">
                        <label class="form-label-soft">Preview — Contoh pesan yang diterima pelanggan:</label>
                        <div class="alert border" id="createPreview" style="white-space: pre-wrap; font-size: 0.875rem; background: #f0fdf4; border-color: #bbf7d0 !important;"></div>
                        <small class="text-muted"><i class="ni ni-bell-55 me-1"></i>Nama, No HP, Email otomatis diisi dari data Kontak setiap penerima.</small>
                    </div>

                    <div class="alert alert-light border mt-3 mb-0" id="createTemplateChecklist" style="font-size: 0.85rem;">
                        Mulai isi nama, kategori, dan konten untuk melihat checklist kelolosan dasar.
                    </div>

                    <div class="mt-3" id="createRiskWrap" style="display:none;">
                        <label class="form-label-soft mb-2">Risiko Review Meta</label>
                        <div id="createRiskBadge"></div>
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
                        <small class="text-primary d-block mt-1" id="editMetaNamePreview" style="display:none;"></small>
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
                    <div class="alert alert-light border mt-3 mb-0" id="editTemplateChecklist" style="font-size: 0.85rem;">
                        Checklist kelolosan dasar akan muncul di sini.
                    </div>
                    <div class="mt-3" id="editRiskWrap" style="display:none;">
                        <label class="form-label-soft mb-2">Risiko Review Meta</label>
                        <div id="editRiskBadge"></div>
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
        konten: 'Halo {{nama}}, selamat datang di layanan kami.\n\nTerima kasih telah bergabung. Jika Anda memerlukan bantuan atau informasi lebih lanjut, silakan hubungi kami.\n\nSalam hormat.'
    },
    thank_you: {
        nama: 'Ucapan Terima Kasih',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, terima kasih telah menggunakan layanan kami.\n\nKami menghargai kepercayaan Anda dan siap membantu jika masih ada kebutuhan lanjutan.\n\nSalam hangat dari tim kami.'
    },
    promo: {
        nama: 'Promo Diskon',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, saat ini tersedia penawaran khusus untuk {{produk}}.\n\nPeriode penawaran berlaku sampai {{tanggal}}. Jika Anda ingin detail harga atau syaratnya, silakan balas pesan ini.\n\nTerima kasih.'
    },
    payment_remind: {
        nama: 'Pengingat Pembayaran',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini adalah pengingat pembayaran untuk layanan atau transaksi Anda.\n\nMohon lakukan pembayaran sebelum {{tanggal}} agar proses dapat dilanjutkan. Jika pembayaran sudah dilakukan, abaikan pesan ini.\n\nTerima kasih.'
    },
    event_invite: {
        nama: 'Undangan Acara',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, kami ingin mengundang Anda ke acara yang kami selenggarakan pada {{tanggal}}.\n\nJika Anda berkenan hadir, silakan balas pesan ini untuk informasi lebih lanjut.\n\nTerima kasih.'
    },
    feedback: {
        nama: 'Minta Ulasan',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, terima kasih telah menggunakan layanan kami.\n\nJika berkenan, kami sangat terbantu bila Anda memberikan masukan singkat mengenai pengalaman Anda.\n\nTerima kasih atas waktunya.'
    },
    otp_login: {
        nama: 'Kode Login',
        kategori: 'authentication',
        konten: 'Halo {{nama}}, kode verifikasi login Anda adalah {{otp}}.\n\nKode ini berlaku sampai {{tanggal}} dan mohon tidak dibagikan kepada siapa pun.'
    },

    // === TOKO / RETAIL / ONLINE SHOP ===
    order_confirm: {
        nama: 'Konfirmasi Pesanan',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda dengan nomor {{no_order}} telah kami terima.\n\nSaat ini pesanan sedang diproses. Kami akan mengirimkan informasi lanjutan setelah status pengiriman tersedia.\n\nTerima kasih.'
    },
    shipping: {
        nama: 'Notifikasi Pengiriman',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda dengan nomor {{no_order}} sudah dikirim.\n\nSilakan lakukan pengecekan status pengiriman secara berkala melalui kanal informasi yang telah kami sampaikan.\n\nTerima kasih.'
    },
    restock: {
        nama: 'Produk Tersedia Kembali',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, {{produk}} yang sebelumnya Anda tanyakan saat ini sudah tersedia kembali.\n\nJika Anda ingin kami bantu proses pemesanan, silakan balas pesan ini.\n\nTerima kasih.'
    },
    payment_due: {
        nama: 'Tagihan Pesanan',
        kategori: 'utility',
        konten: 'Halo {{nama}}, tagihan untuk pesanan {{no_order}} akan jatuh tempo pada {{tanggal}}.\n\nSilakan lakukan pembayaran sesuai instruksi yang telah Anda terima.\n\nTerima kasih.'
    },

    // === JASA / LAYANAN ===
    booking_confirm: {
        nama: 'Konfirmasi Booking',
        kategori: 'utility',
        konten: 'Halo {{nama}}, jadwal booking Anda telah dikonfirmasi untuk tanggal {{tanggal}}.\n\nMohon hadir sesuai waktu yang telah ditentukan. Jika Anda perlu melakukan penyesuaian jadwal, silakan hubungi kami.\n\nTerima kasih.'
    },
    appointment_remind: {
        nama: 'Pengingat Jadwal',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini adalah pengingat untuk jadwal Anda pada {{tanggal}}.\n\nJika Anda memerlukan perubahan jadwal, silakan hubungi kami sesegera mungkin.\n\nTerima kasih.'
    },
    service_done: {
        nama: 'Layanan Selesai',
        kategori: 'utility',
        konten: 'Halo {{nama}}, layanan yang Anda ajukan telah selesai diproses.\n\nJika ada hal yang masih perlu ditindaklanjuti, silakan hubungi tim kami.\n\nTerima kasih atas kepercayaan Anda.'
    },
    service_verification: {
        nama: 'Verifikasi Jadwal',
        kategori: 'authentication',
        konten: 'Halo {{nama}}, kode verifikasi untuk konfirmasi jadwal Anda adalah {{kode}}.\n\nMasukkan kode ini pada sistem kami sebelum {{tanggal}}.'
    },

    // === SEKOLAH / PENDIDIKAN ===
    school_info: {
        nama: 'Info Sekolah',
        kategori: 'utility',
        konten: 'Kepada Bapak/Ibu {{nama}},\n\nDengan ini kami menyampaikan informasi penting dari sekolah pada tanggal {{tanggal}}.\n\nUntuk detail lebih lanjut, silakan menghubungi pihak sekolah melalui kanal resmi.\n\nTerima kasih atas perhatian Bapak/Ibu.'
    },
    school_payment: {
        nama: 'Tagihan SPP',
        kategori: 'utility',
        konten: 'Kepada Bapak/Ibu {{nama}},\n\nIni adalah pengingat pembayaran SPP atau biaya sekolah yang jatuh tempo pada {{tanggal}}.\n\nJika pembayaran sudah dilakukan, mohon abaikan pesan ini.\n\nTerima kasih.'
    },
    school_event: {
        nama: 'Undangan Kegiatan Sekolah',
        kategori: 'marketing',
        konten: 'Kepada Bapak/Ibu {{nama}},\n\nKami mengundang Bapak/Ibu untuk menghadiri kegiatan sekolah pada {{tanggal}}.\n\nUntuk informasi waktu dan lokasi, silakan hubungi pihak sekolah.\n\nTerima kasih.'
    },
    school_otp: {
        nama: 'Kode Verifikasi Orang Tua',
        kategori: 'authentication',
        konten: 'Kepada Bapak/Ibu {{nama}}, kode verifikasi akses portal sekolah adalah {{otp}}.\n\nKode ini bersifat rahasia dan berlaku sampai {{tanggal}}.'
    },

    // === KANTOR / PERUSAHAAN ===
    meeting_invite: {
        nama: 'Undangan Rapat',
        kategori: 'utility',
        konten: 'Halo {{nama}}, Anda dijadwalkan menghadiri rapat pada {{tanggal}}.\n\nMohon lakukan konfirmasi kehadiran sesuai prosedur yang berlaku.\n\nTerima kasih.'
    },
    company_announce: {
        nama: 'Pengumuman Perusahaan',
        kategori: 'utility',
        konten: 'Kepada {{nama}},\n\nBerikut kami sampaikan informasi penting dari perusahaan untuk perhatian Anda.\n\nApabila diperlukan tindak lanjut, silakan menghubungi pihak terkait.\n\nTerima kasih.'
    },

    // === F&B / RESTORAN / KAFE ===
    order_ready: {
        nama: 'Pesanan Siap',
        kategori: 'utility',
        konten: 'Halo {{nama}}, pesanan Anda dengan nomor {{no_order}} sudah siap.\n\nSilakan lakukan pengambilan sesuai jadwal atau ketentuan yang berlaku.\n\nTerima kasih.'
    },
    menu_promo: {
        nama: 'Promo Menu Baru',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, kami ingin menginformasikan bahwa saat ini tersedia menu baru di tempat kami.\n\nJika Anda ingin melihat detail menu atau harga, silakan balas pesan ini.\n\nTerima kasih.'
    },

    // === KESEHATAN / KLINIK ===
    appointment_health: {
        nama: 'Pengingat Jadwal Kontrol',
        kategori: 'utility',
        konten: 'Halo {{nama}}, ini adalah pengingat untuk jadwal kontrol kesehatan Anda pada {{tanggal}}.\n\nMohon hadir sesuai waktu yang telah ditentukan. Jika perlu perubahan jadwal, silakan hubungi kami.\n\nTerima kasih.'
    },
    health_promo: {
        nama: 'Promo Layanan Kesehatan',
        kategori: 'marketing',
        konten: 'Halo {{nama}}, saat ini tersedia informasi layanan kesehatan untuk periode {{tanggal}}.\n\nJika Anda ingin mengetahui jadwal, biaya, atau jenis layanan yang tersedia, silakan hubungi kami.\n\nTerima kasih.'
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
    updateTemplateGuidance('create');
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
    updateTemplateGuidance(textareaId === 'createKonten' ? 'create' : 'edit');
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function toMetaSafeName(value) {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function updateTemplateGuidance(prefix) {
    const nameInput = document.getElementById(prefix + 'Nama');
    const categoryInput = document.getElementById(prefix + 'Kategori');
    const contentInput = document.getElementById(prefix + 'Konten');
    const checklist = document.getElementById(prefix + 'TemplateChecklist');
    const metaNamePreview = document.getElementById(prefix + 'MetaNamePreview');
    const riskWrap = document.getElementById(prefix + 'RiskWrap');
    const riskBadge = document.getElementById(prefix + 'RiskBadge');

    if (!nameInput || !categoryInput || !contentInput || !checklist || !metaNamePreview || !riskWrap || !riskBadge) {
        return;
    }

    const allowedVariables = ['nama', 'telepon', 'email', 'kode', 'otp', 'produk', 'harga', 'tanggal', 'no_order'];
    const promoPattern = /\b(promo|diskon|cashback|voucher|gratis|sale|penawaran|flash sale|stok terbatas|beli sekarang|pesan sekarang)\b|%/i;
    const authPattern = /\b(otp|kode|verifikasi|verification|password|pin|login)\b/i;
    const shortenerPattern = /(bit\.ly|tinyurl\.com|cutt\.ly|s\.id)/i;
    const highRiskPattern = /\b(slot|judi|casino|pinjaman online|paylater tanpa syarat|investasi pasti untung|jamin untung|cepat kaya|hadiah tunai instan)\b/i;
    const urlPattern = /https?:\/\//i;

    const displayName = nameInput.value.trim();
    const safeName = toMetaSafeName(displayName);
    const category = categoryInput.value;
    const body = contentInput.value.trim();
    const variableMatches = [...contentInput.value.matchAll(/\{\{([^{}]+)\}\}/g)].map(match => match[1].trim().toLowerCase());
    const invalidVariables = [...new Set(variableMatches.filter(value => !allowedVariables.includes(value)))];

    if (safeName) {
        metaNamePreview.style.display = 'block';
        metaNamePreview.textContent = 'Nama aman untuk Meta: ' + safeName;
    } else {
        metaNamePreview.style.display = 'none';
    }

    const checks = [
        {
            ok: displayName.length >= 3,
            text: displayName.length >= 3 ? 'Nama template cukup jelas.' : 'Nama template minimal 3 karakter.'
        },
        {
            ok: body.length >= 15,
            text: body.length >= 15 ? 'Isi pesan cukup spesifik.' : 'Isi pesan terlalu pendek untuk review Meta.'
        },
        {
            ok: invalidVariables.length === 0,
            text: invalidVariables.length === 0
                ? 'Variabel yang dipakai didukung sistem.'
                : 'Variabel tidak didukung: ' + invalidVariables.join(', ')
        },
        {
            ok: !shortenerPattern.test(body),
            text: !shortenerPattern.test(body)
                ? 'Tidak ada short link berisiko.'
                : 'Hindari short link seperti bit.ly atau s.id.'
        },
        {
            ok: !highRiskPattern.test(body),
            text: !highRiskPattern.test(body)
                ? 'Tidak ada kata berisiko tinggi.'
                : 'Ada kata berisiko tinggi yang sering memicu penolakan review.'
        },
        {
            ok: category !== 'utility' || !promoPattern.test(body),
            text: category !== 'utility' || !promoPattern.test(body)
                ? 'Kategori sesuai isi pesan.'
                : 'Isi terdeteksi promosi. Lebih aman pakai kategori Marketing.'
        },
        {
            ok: category !== 'authentication' || authPattern.test(body),
            text: category !== 'authentication' || authPattern.test(body)
                ? 'Kategori Authentication sesuai.'
                : 'Authentication sebaiknya hanya untuk OTP, PIN, login, atau verifikasi.'
        },
        {
            ok: category !== 'authentication' || !urlPattern.test(body),
            text: category !== 'authentication' || !urlPattern.test(body)
                ? 'Authentication tidak memakai link.'
                : 'Authentication sebaiknya tidak menyertakan link.'
        }
    ];

    const failedChecks = checks.filter(item => !item.ok).length;
    let riskLevel = 'Rendah';
    let riskColor = '#2dce89';
    let riskBackground = '#ecfdf3';
    let riskMessage = 'Template terlihat aman untuk disimpan dan diajukan, tetapi tetap review isi akhirnya sebelum submit.';

    if (failedChecks >= 3) {
        riskLevel = 'Tinggi';
        riskColor = '#f5365c';
        riskBackground = '#fff1f2';
        riskMessage = 'Ada beberapa indikator berisiko yang bisa memicu penolakan. Perbaiki poin merah terlebih dahulu.';
    } else if (failedChecks >= 1) {
        riskLevel = 'Sedang';
        riskColor = '#fb6340';
        riskBackground = '#fff7ed';
        riskMessage = 'Masih ada beberapa hal yang sebaiknya dirapikan agar peluang lolos review lebih baik.';
    }

    if (displayName || category || body) {
        riskWrap.style.display = 'block';
        riskBadge.innerHTML = '<div style="border:1px solid ' + riskColor + ';background:' + riskBackground + ';color:' + riskColor + ';border-radius:12px;padding:12px 14px;">'
            + '<div style="font-weight:700;margin-bottom:4px;">Risiko ' + riskLevel + '</div>'
            + '<div style="font-size:0.85rem;line-height:1.5;">' + escapeHtml(riskMessage) + '</div>'
            + '</div>';
    } else {
        riskWrap.style.display = 'none';
    }

    checklist.innerHTML = checks.map(item => {
        const color = item.ok ? '#2dce89' : '#f5365c';
        const icon = item.ok ? 'ni ni-check-bold' : 'ni ni-fat-remove';
        return '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">'
            + '<i class="' + icon + '" style="color:' + color + ';margin-top:2px;"></i>'
            + '<span>' + escapeHtml(item.text) + '</span>'
            + '</div>';
    }).join('');
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
            .replace(/\{\{kode\}\}/g, '<span style="background:#11cdef;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">Kode Verifikasi</span>')
            .replace(/\{\{otp\}\}/g, '<span style="background:#11cdef;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;">OTP</span>')
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
            updateTemplateGuidance('create');
        });
    }

    ['createNama', 'createKategori'].forEach(function(id) {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', function() { updateTemplateGuidance('create'); });
            element.addEventListener('change', function() { updateTemplateGuidance('create'); });
        }
    });

    ['editNama', 'editKategori', 'editKonten'].forEach(function(id) {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', function() { updateTemplateGuidance('edit'); });
            element.addEventListener('change', function() { updateTemplateGuidance('edit'); });
        }
    });

    updateTemplateGuidance('create');
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
    updateTemplateGuidance('edit');
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