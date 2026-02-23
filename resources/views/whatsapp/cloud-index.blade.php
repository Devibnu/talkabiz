@extends('layouts.user_type.auth')

@section('title', 'WhatsApp Business')

@section('content')
<div class="container-fluid py-4">
    {{-- Impersonation View-Only Banner --}}
    @if($__isImpersonating ?? false)
    <div class="alert alert-info border-0 shadow-sm mb-4" style="background: linear-gradient(310deg, #e8f4fd 0%, #f0e8fd 100%); border-left: 4px solid #5e72e4 !important;">
        <div class="d-flex align-items-center">
            <i class="fas fa-eye me-3 text-primary" style="font-size: 1.25rem;"></i>
            <div>
                <strong class="text-dark">Mode Lihat Saja</strong>
                <p class="text-sm text-secondary mb-0">Anda sedang melihat halaman WhatsApp milik <strong>{{ $__impersonationMeta['client_name'] ?? 'Klien' }}</strong>. Aksi koneksi & pengaturan dinonaktifkan.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">WhatsApp Business Cloud API</h4>
                    <p class="text-sm text-muted mb-0">Kelola koneksi WhatsApp Business resmi via Gupshup</p>
                </div>
                @if($connection && $connection->isConnected() && !($__isImpersonating ?? false))
                <a href="{{ route('whatsapp.campaigns.index') }}" class="btn btn-primary">
                    <i class="fas fa-paper-plane me-2"></i>WA Blast
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Connection Status Card --}}
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <div class="d-flex align-items-center">
                        <div class="icon icon-shape icon-md bg-gradient-success shadow text-center border-radius-md me-3">
                            <i class="fab fa-whatsapp text-white opacity-10" style="font-size: 1.2rem;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Status Koneksi</h6>
                            <p class="text-xs text-muted mb-0">WhatsApp Business API</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if(!$connection)
                        {{-- Not Connected --}}
                        <div class="text-center py-4">
                            <div class="icon icon-shape icon-xxl bg-gradient-secondary shadow-secondary text-center border-radius-xl mb-3">
                                <i class="fab fa-whatsapp text-white" style="font-size: 3rem;"></i>
                            </div>
                            <h5>Belum Terhubung</h5>
                            <p class="text-sm text-muted mb-4">
                                @if($__isImpersonating ?? false)
                                    Klien ini belum menghubungkan WhatsApp Business.
                                @else
                                    Hubungkan WhatsApp Business Anda untuk mulai mengirim pesan template dan broadcast.
                                @endif
                            </p>
                            @if(!($__isImpersonating ?? false))
                            <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#connectModal">
                                <i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp Business (Resmi)
                            </button>
                            @endif
                        </div>
                    @else
                        {{-- Connection Status --}}
                        <div class="d-flex align-items-center mb-4">
                            @if($connection->status === 'connected')
                                <span class="badge bg-gradient-success me-2">
                                    <i class="fas fa-check-circle me-1"></i>Terhubung
                                </span>
                            @elseif($connection->status === 'pending')
                                <span class="badge bg-gradient-warning me-2">
                                    <i class="fas fa-clock me-1"></i>Menunggu Verifikasi
                                </span>
                            @elseif($connection->status === 'restricted')
                                <span class="badge bg-gradient-danger me-2">
                                    <i class="fas fa-exclamation-circle me-1"></i>Dibatasi
                                </span>
                            @else
                                <span class="badge bg-gradient-secondary me-2">
                                    <i class="fas fa-times-circle me-1"></i>Terputus
                                </span>
                            @endif
                        </div>

                        @if($connection->business_name || $connection->phone_number)
                        <div class="row mb-4">
                            @if($connection->business_name)
                            <div class="col-6">
                                <p class="text-xs text-muted mb-1">Nama Bisnis</p>
                                <h6 class="mb-0">{{ $connection->business_name }}</h6>
                            </div>
                            @endif
                            @if($connection->phone_number)
                            <div class="col-6">
                                <p class="text-xs text-muted mb-1">Nomor WhatsApp</p>
                                <h6 class="mb-0">+{{ $connection->phone_number }}</h6>
                            </div>
                            @endif
                        </div>
                        @endif

                        @if($connection->connected_at)
                        <p class="text-xs text-muted mb-0">
                            <i class="fas fa-clock me-1"></i>
                            Terhubung sejak: {{ $connection->connected_at->format('d M Y H:i') }}
                        </p>
                        @endif

                        <hr class="horizontal dark my-3">

                        <div class="d-flex justify-content-between">
                            @if($connection->isConnected() && !($__isImpersonating ?? false))
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnSyncTemplates">
                                <i class="fas fa-sync me-1"></i>Sync Templates
                            </button>
                            @endif
                            @if(!($__isImpersonating ?? false))
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnDisconnect">
                                <i class="fas fa-unlink me-1"></i>Putuskan
                            </button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Quick Stats --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Statistik Cepat</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 mb-4">
                            <div class="d-flex">
                                <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-2">
                                    <i class="fas fa-file-alt text-white opacity-10"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-muted mb-0">Templates</p>
                                    <h5 class="font-weight-bolder mb-0">{{ $templates->count() }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 mb-4">
                            <div class="d-flex">
                                <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-2">
                                    <i class="fas fa-users text-white opacity-10"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-muted mb-0">Kontak Opt-in</p>
                                    <h5 class="font-weight-bolder mb-0">{{ $klien ? \App\Models\WhatsappContact::where('klien_id', $klien->id)->optedIn()->count() : 0 }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex">
                                <div class="icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md me-2">
                                    <i class="fas fa-paper-plane text-white opacity-10"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-muted mb-0">Kampanye</p>
                                    <h5 class="font-weight-bolder mb-0">{{ $klien ? \App\Models\WhatsappCampaign::where('klien_id', $klien->id)->count() : 0 }}</h5>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex">
                                <div class="icon icon-shape icon-sm bg-gradient-warning shadow text-center border-radius-md me-2">
                                    <i class="fas fa-envelope text-white opacity-10"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-muted mb-0">Pesan Terkirim</p>
                                    <h5 class="font-weight-bolder mb-0">{{ $klien ? \App\Models\WhatsappMessageLog::where('klien_id', $klien->id)->outbound()->count() : 0 }}</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Templates Section --}}
    @if($connection && $connection->isConnected())
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Template Pesan</h6>
                        <p class="text-xs text-muted mb-0">Template yang disetujui untuk broadcast</p>
                    </div>
                    @if(!($__isImpersonating ?? false))
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSyncTemplates2">
                        <i class="fas fa-sync me-1"></i>Sync
                    </button>
                    @endif
                </div>
                <div class="card-body px-0 pb-0">
                    @if($templates->count() > 0)
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Template</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Kategori</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Bahasa</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($templates as $template)
                                <tr>
                                    <td>
                                        <div class="d-flex px-2 py-1">
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">{{ $template->name }}</h6>
                                                <p class="text-xs text-muted mb-0">{{ Str::limit($template->sample_text, 50) }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-secondary">{{ $template->category ?? 'N/A' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ strtoupper($template->language) }}</span>
                                    </td>
                                    <td>
                                        @if($template->status === 'approved')
                                            <span class="badge badge-sm bg-gradient-success">Disetujui</span>
                                        @elseif($template->status === 'pending')
                                            <span class="badge badge-sm bg-gradient-warning">Menunggu</span>
                                        @else
                                            <span class="badge badge-sm bg-gradient-danger">Ditolak</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        @if($template->isApproved() && !($__isImpersonating ?? false))
                                        <a href="{{ route('whatsapp.campaigns.create', ['template' => $template->id]) }}" 
                                           class="btn btn-link text-primary mb-0" title="Buat Kampanye">
                                            <i class="fas fa-paper-plane"></i>
                                        </a>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-4">
                        <i class="fas fa-file-alt text-secondary mb-3" style="font-size: 3rem;"></i>
                        <p class="text-muted mb-0">Belum ada template. Klik "Sync Templates" untuk mengambil dari Gupshup.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Info Cards --}}
    <div class="row mt-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="icon icon-shape icon-md bg-gradient-info shadow text-center border-radius-md me-3">
                            <i class="fas fa-info-circle text-white opacity-10"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Apa itu Cloud API?</h6>
                            <p class="text-xs text-muted mb-0">
                                WhatsApp Cloud API adalah API resmi dari Meta/WhatsApp untuk bisnis. 
                                Berbeda dengan WhatsApp Web, ini adalah solusi enterprise yang aman dan reliable.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="icon icon-shape icon-md bg-gradient-warning shadow text-center border-radius-md me-3">
                            <i class="fas fa-file-alt text-white opacity-10"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Template Message</h6>
                            <p class="text-xs text-muted mb-0">
                                Untuk broadcast (WA Blast), Anda harus menggunakan template yang sudah disetujui oleh Meta. 
                                Pesan di luar template hanya bisa dikirim dalam window 24 jam.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="icon icon-shape icon-md bg-gradient-success shadow text-center border-radius-md me-3">
                            <i class="fas fa-check-circle text-white opacity-10"></i>
                        </div>
                        <div>
                            <h6 class="mb-1">Opt-in Required</h6>
                            <p class="text-xs text-muted mb-0">
                                Semua penerima broadcast harus sudah memberikan persetujuan (opt-in) untuk menerima pesan dari bisnis Anda.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Connect Modal (SaaS Flow - NO API Key from user) --}}
@if(!($__isImpersonating ?? false))
<div class="modal fade" id="connectModal" tabindex="-1" aria-labelledby="connectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-gradient-success">
                <h5 class="modal-title text-white" id="connectModalLabel">
                    <i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp Business
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('whatsapp.connect') }}" method="POST" id="connectForm">
                @csrf
                <div class="modal-body">
                    {{-- Info Box --}}
                    <div class="alert alert-info mb-4">
                        <div class="d-flex">
                            <i class="fas fa-shield-alt fa-lg me-3 mt-1"></i>
                            <div>
                                <strong>WhatsApp Business Cloud API Resmi</strong>
                                <p class="text-sm mb-0 mt-1">
                                    Koneksi aman via Gupshup Partner API. Anda hanya perlu memasukkan nomor WhatsApp dan nama bisnis.
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Nama Bisnis --}}
                    <div class="mb-4">
                        <label class="form-label">Nama Bisnis <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-building"></i></span>
                            <input type="text" name="business_name" class="form-control" required 
                                   placeholder="Contoh: Toko Berkah Jaya"
                                   value="{{ $klien?->nama_perusahaan ?? '' }}"
                                   minlength="3" maxlength="100">
                        </div>
                        <small class="text-muted">Nama yang akan tampil di WhatsApp Business</small>
                    </div>

                    {{-- Nomor WhatsApp --}}
                    <div class="mb-4">
                        <label class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fab fa-whatsapp"></i></span>
                            <input type="text" name="phone_number" class="form-control" required 
                                   placeholder="628123456789"
                                   pattern="62[0-9]{9,12}"
                                   value="{{ $klien?->no_whatsapp ?? '' }}">
                        </div>
                        <small class="text-muted">Format: 62 + nomor (tanpa + atau 0). Contoh: 628123456789</small>
                    </div>

                    {{-- Checklist --}}
                    <div class="bg-light rounded p-3">
                        <p class="text-sm font-weight-bold mb-2">Dengan menghubungkan, Anda menyetujui:</p>
                        <ul class="list-unstyled mb-0 text-sm">
                            <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Nomor digunakan untuk WhatsApp Business API</li>
                            <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Hanya mengirim pesan template yang disetujui</li>
                            <li><i class="fas fa-check text-success me-2"></i>Mematuhi kebijakan WhatsApp Business</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success" id="btnSubmitConnect">
                        <i class="fab fa-whatsapp me-2"></i>Hubungkan Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Connect Form Submit
    const connectForm = document.getElementById('connectForm');
    if (connectForm) {
        connectForm.addEventListener('submit', function() {
            const btn = document.getElementById('btnSubmitConnect');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menghubungkan...';
        });
    }

    // Sync Templates
    const syncButtons = document.querySelectorAll('#btnSyncTemplates, #btnSyncTemplates2');
    syncButtons.forEach(btn => {
        btn.addEventListener('click', async function() {
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...';
            
            try {
                const response = await fetch('{{ route("whatsapp.sync-templates") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    ClientPopup.actionSuccess('Template berhasil disinkronkan').then(() => window.location.reload());
                } else {
                    ClientPopup.actionFailed('Sinkronisasi template belum berhasil. Coba lagi dalam beberapa saat.');
                }
            } catch (error) {
                ClientPopup.connectionError();
            }
            
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Templates';
        });
    });

    // Disconnect
    const disconnectBtn = document.getElementById('btnDisconnect');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', async function() {
            // SweetAlert2 confirmation dialog
            const result = await Swal.fire({
                title: 'Putuskan WhatsApp Business?',
                html: `
                    <div class="text-start">
                        <p class="mb-2">Anda akan memutuskan koneksi WhatsApp Business dari akun ini.</p>
                        <div class="alert alert-light border mb-0">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Dampak:</strong>
                                <ul class="mb-0 mt-1 ps-3">
                                    <li>Tidak dapat mengirim pesan WhatsApp</li>
                                    <li>Tidak dapat menerima notifikasi</li>
                                    <li>Campaign aktif akan dihentikan</li>
                                </ul>
                            </small>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-unlink me-1"></i> Ya, Putuskan',
                cancelButtonText: 'Batal',
                customClass: {
                    confirmButton: 'btn btn-danger px-4',
                    cancelButton: 'btn btn-secondary px-4'
                },
                buttonsStyling: false,
                reverseButtons: true,
                focusCancel: true
            });

            if (!result.isConfirmed) return;
            
            this.disabled = true;
            
            // Show loading state
            Swal.fire({
                title: 'Memutuskan...',
                html: 'Mohon tunggu sebentar',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            try {
                const response = await fetch('{{ route("whatsapp.disconnect") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                
                if (!response.ok) {
                    const errorData = await response.json().catch(() => null);
                    throw new Error(errorData?.message || `HTTP error ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Diputuskan',
                        text: data.message || 'WhatsApp Business berhasil diputuskan.',
                        showConfirmButton: false,
                        timer: 1500
                    });
                    window.location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: data.message || 'Gagal memutuskan WhatsApp.',
                        confirmButtonText: 'OK',
                        customClass: {
                            confirmButton: 'btn btn-primary'
                        },
                        buttonsStyling: false
                    });
                }
            } catch (error) {
                console.error('Disconnect error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Terjadi Kesalahan',
                    text: error.message || 'Gagal memutuskan WhatsApp.',
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'btn btn-primary'
                    },
                    buttonsStyling: false
                });
            }
            
            this.disabled = false;
        });
    }
});
</script>
@endpush
