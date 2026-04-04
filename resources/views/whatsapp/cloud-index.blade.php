@extends('layouts.user_type.auth')

@section('title', 'WhatsApp Business')

@section('content')
@php
    // View-only mode: impersonating OR owner/admin with no klien (CLIENT VIEW)
    $__isViewOnly = ($__isImpersonating ?? false) || (!$klien && in_array(auth()->user()->role, ['super_admin', 'superadmin', 'owner'], true));
@endphp
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
                @if($connection && $connection->isConnected() && !$__isViewOnly)
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
                    @if($connection && $connection->isConnected())
                        {{-- Connected --}}
                        <div class="d-flex align-items-center mb-4">
                            <span class="badge bg-gradient-success me-2">
                                <i class="fas fa-check-circle me-1"></i>Terhubung
                            </span>
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
                            @if(!$__isViewOnly)
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnSyncTemplates">
                                <i class="fas fa-sync me-1"></i>Sync Templates
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnDisconnect">
                                <i class="fas fa-unlink me-1"></i>Putuskan
                            </button>
                            @endif
                        </div>
                    @else
                        {{-- Not Connected / Disconnected --}}
                        <div class="text-center py-4">
                            <div class="icon icon-shape icon-xxl bg-gradient-secondary shadow-secondary text-center border-radius-xl mb-3">
                                <i class="fab fa-whatsapp text-white" style="font-size: 3rem;"></i>
                            </div>
                            <h5>Belum Terhubung</h5>
                            <p class="text-sm text-muted mb-4">
                                @if($__isViewOnly)
                                    @if($__isImpersonating ?? false)
                                        Klien ini belum menghubungkan WhatsApp Business.
                                    @else
                                        Belum ada koneksi WhatsApp Business.
                                    @endif
                                @else
                                    Hubungkan WhatsApp Business Anda untuk mulai mengirim pesan template dan broadcast.
                                @endif
                            </p>
                            @if(!$__isViewOnly)
                            <button type="button" class="btn btn-success btn-lg" id="btnEmbeddedSignup" onclick="launchWhatsAppSignup()">
                                <i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp Business
                            </button>
                            <p class="text-xs text-muted mt-2 mb-0">
                                <i class="fas fa-shield-alt me-1"></i>Koneksi resmi via Meta WhatsApp Cloud API. Aman & terverifikasi.
                            </p>
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
                    @if(!$__isViewOnly)
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
                                        @if($template->isApproved() && !$__isViewOnly)
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

{{-- Facebook JS SDK for Embedded Signup --}}
@if(!$__isViewOnly)
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
<script>
    // Facebook SDK initialization
    window.fbAsyncInit = function() {
        FB.init({
            appId: '{{ config("whatsapp.meta.app_id") }}',
            autoLogAppEvents: true,
            xfbml: true,
            version: '{{ config("whatsapp.meta.graph_version", "v22.0") }}'
        });
    };

    // Session logging — captures WABA ID, phone_number_id from Embedded Signup
    let embeddedSignupData = {};
    let embeddedSignupError = null;
    window.addEventListener('message', (event) => {
        if (!event.origin.endsWith('facebook.com')) return;
        try {
            const data = JSON.parse(event.data);
            if (data.type === 'WA_EMBEDDED_SIGNUP') {
                if (data.event === 'CANCEL') {
                    embeddedSignupError = data.data || null;
                    console.log('[EmbeddedSignup] User cancelled at step:', data.data?.current_step, data.data);
                    return;
                }
                // Successful completion — capture asset IDs
                embeddedSignupData = data.data || {};
                embeddedSignupError = null;
                console.log('[EmbeddedSignup] Session data:', embeddedSignupData);
            }
        } catch {
            // Non-JSON message, ignore
        }
    });

    // Response callback — receives exchangeable token code
    const fbLoginCallback = (response) => {
        if (response.authResponse) {
            const code = response.authResponse.code;
            console.log('[EmbeddedSignup] Got code, sending to server...');

            // Send code + session info to backend
            processEmbeddedSignup(code, embeddedSignupData);
        } else {
            console.log('[EmbeddedSignup] Login failed or cancelled:', response);
            if (response.status === 'unknown') {
                if (embeddedSignupError?.error_message || embeddedSignupError?.error_code) {
                    const errorMessage = embeddedSignupError.error_message || 'Meta menghentikan proses Embedded Signup.';
                    const metaContext = [
                        embeddedSignupError.error_code ? 'Kode error: ' + embeddedSignupError.error_code : null,
                        embeddedSignupError.current_step ? 'Step: ' + embeddedSignupError.current_step : null,
                        embeddedSignupError.session_id ? 'Session ID: ' + embeddedSignupError.session_id : null,
                    ].filter(Boolean).join(' | ');

                    Swal.fire({
                        icon: 'error',
                        title: 'Meta Menolak Proses Koneksi',
                        html: `
                            <div class="text-start">
                                <p class="mb-2">${errorMessage}</p>
                                ${metaContext ? '<p class="text-xs text-muted mb-0">' + metaContext + '</p>' : ''}
                                <div class="alert alert-light border mt-3 mb-0 text-start">
                                    <small class="text-muted">
                                        Periksa konfigurasi Meta App: mode Live, Facebook Login for Business allowed domains, dan advanced access untuk permission WhatsApp.
                                    </small>
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'OK',
                        customClass: { confirmButton: 'btn btn-primary' },
                        buttonsStyling: false
                    });
                }

                // User closed popup without completing
                return;
            }
            ClientPopup.error('Login Facebook dibatalkan atau gagal. Silakan coba lagi.');
        }
    };

    // Launch Embedded Signup flow
    function launchWhatsAppSignup() {
        const configId = '{{ config("whatsapp.meta.config_id") }}';
        if (!configId) {
            ClientPopup.error('Embedded Signup belum dikonfigurasi. Hubungi admin.');
            return;
        }

        const btn = document.getElementById('btnEmbeddedSignup');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';

        FB.login(fbLoginCallback, {
            config_id: configId,
            response_type: 'code',
            override_default_response_type: true,
            extras: {
                setup: {},
            }
        });

        // Re-enable button after 3 seconds (in case user closes popup)
        setTimeout(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp Business';
        }, 3000);
    }

    // Send Embedded Signup data to server
    async function processEmbeddedSignup(code, sessionData) {
        // Show loading
        Swal.fire({
            title: 'Menghubungkan WhatsApp...',
            html: '<p class="mb-0">Sedang memproses koneksi WhatsApp Business Anda.</p><p class="text-xs text-muted">Mohon tunggu, jangan tutup halaman ini.</p>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        try {
            const response = await fetch('{{ route("whatsapp.embedded-signup-callback") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    code: code,
                    waba_id: sessionData.waba_id || '',
                    phone_number_id: sessionData.phone_number_id || '',
                    business_id: sessionData.business_id || '',
                })
            });

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'WhatsApp Terhubung!',
                    html: `
                        <div class="text-start">
                            <p class="mb-2">WhatsApp Business berhasil terhubung.</p>
                            ${data.connection?.business_name ? '<p class="text-sm mb-1"><strong>Bisnis:</strong> ' + data.connection.business_name + '</p>' : ''}
                            ${data.connection?.phone_number ? '<p class="text-sm mb-0"><strong>Nomor:</strong> +' + data.connection.phone_number + '</p>' : ''}
                        </div>
                    `,
                    confirmButtonText: 'OK',
                    customClass: { confirmButton: 'btn btn-success' },
                    buttonsStyling: false
                });
                window.location.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Menghubungkan',
                    text: data.message || 'Terjadi kesalahan saat menghubungkan WhatsApp.',
                    confirmButtonText: 'OK',
                    customClass: { confirmButton: 'btn btn-primary' },
                    buttonsStyling: false
                });
            }
        } catch (error) {
            console.error('[EmbeddedSignup] Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Terjadi Kesalahan',
                text: 'Gagal menghubungkan WhatsApp. Periksa koneksi internet Anda.',
                confirmButtonText: 'OK',
                customClass: { confirmButton: 'btn btn-primary' },
                buttonsStyling: false
            });
        }
    }
</script>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
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
