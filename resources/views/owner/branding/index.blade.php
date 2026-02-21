@extends('layouts.owner')

@section('title', 'Branding & Logo')
@section('page-title', 'Branding & Logo')

@section('content')
<div class="container-fluid py-4">

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px; border: none; background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);">
            <i class="fas fa-check-circle me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px; border: none; background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);">
            <i class="fas fa-exclamation-triangle me-2"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px; border: none;">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- INFO BOX --}}
    <div class="alert alert-info" style="border-radius: 12px; border: none; background: linear-gradient(135deg, #cce5ff 0%, #b8daff 100%);">
        <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i> SSOT Branding</h6>
        <p class="mb-0" style="font-size: 0.85rem;">
            Semua perubahan di halaman ini langsung diterapkan ke <strong>seluruh halaman publik</strong> (Landing, Login, Register, Sidebar).
            Logo & nama brand diambil dari sini — tidak ada hardcode di tempat lain.
        </p>
    </div>

    <div class="row">
        {{-- LEFT COLUMN: Logo & Favicon Upload --}}
        <div class="col-lg-7">
            {{-- Logo Upload Card --}}
            <div class="card mb-4" style="border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
                <div class="card-header pb-0" style="background: transparent; border: none;">
                    <h6 class="mb-1"><i class="fas fa-image me-2 text-primary"></i> Logo Utama</h6>
                    <p class="text-muted mb-0" style="font-size: 0.8rem;">
                        Ditampilkan di navbar landing, login, register, dan sidebar. Rekomendasi: PNG/SVG transparan.
                        <br><span class="badge bg-info text-white mt-1" style="font-weight: 500;"><i class="fas fa-magic me-1"></i> Auto-resize & compress — Anda tidak perlu resize manual</span>
                    </p>
                </div>
                <div class="card-body">
                    {{-- Current Logo Preview --}}
                    <div class="text-center mb-4 p-4" id="logo-preview-container" style="background: #f8f9fa; border-radius: 12px; border: 2px dashed #dee2e6;">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="Logo saat ini" id="logo-preview-img" style="max-height: 80px; max-width: 280px; object-fit: contain;">
                            <p class="text-muted mt-2 mb-0" style="font-size: 0.75rem;" id="logo-preview-label">Logo aktif saat ini</p>
                        @else
                            <div style="font-size: 2.5rem; color: #adb5bd;">
                                <i class="fas fa-image"></i>
                            </div>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">Belum ada logo. Tampil sebagai teks "<strong>{{ $branding['site_name'] }}</strong>"</p>
                        @endif

                        {{-- Processing indicator (shown when job is running) --}}
                        @if(!empty($logoProcessing))
                        <div id="logo-processing-banner" class="mt-3 p-2" style="background: linear-gradient(135deg, #e0cffc 0%, #d4e0ff 100%); border-radius: 8px;">
                            <div class="d-flex align-items-center justify-content-center">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                                    <span class="visually-hidden">Processing...</span>
                                </div>
                                <span style="font-size: 0.8rem; font-weight: 600; color: #5a3e8e;">
                                    <i class="fas fa-magic me-1"></i> Mengoptimasi logo... (resize + konversi WebP)
                                </span>
                            </div>
                            <p class="mb-0 mt-1" style="font-size: 0.7rem; color: #7c6b9e;">Preview akan otomatis terupdate setelah selesai.</p>
                        </div>
                        @endif
                    </div>

                    {{-- Upload Form --}}
                    <form action="{{ route('owner.branding.upload-logo') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="input-group">
                            <input type="file" class="form-control" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload me-1"></i> Upload Logo
                            </button>
                        </div>
                        <small class="text-muted">
                            Format: PNG, JPG, SVG, WebP. Maks upload 10MB.
                            <br>Sistem otomatis resize ke maks 800×400px & compress ≤ 2MB. Transparansi dijaga.
                        </small>
                    </form>

                    @if($logoUrl)
                        <form action="{{ route('owner.branding.remove-logo') }}" method="POST" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Hapus logo? Semua halaman akan kembali menampilkan teks default.')">
                                <i class="fas fa-trash me-1"></i> Hapus Logo
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Favicon Upload Card --}}
            <div class="card mb-4" style="border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
                <div class="card-header pb-0" style="background: transparent; border: none;">
                    <h6 class="mb-1"><i class="fas fa-globe me-2 text-warning"></i> Favicon</h6>
                    <p class="text-muted mb-0" style="font-size: 0.8rem;">
                        Icon kecil di tab browser. Rekomendasi: PNG 32×32 atau 64×64.
                        <br><span class="badge bg-info text-white mt-1" style="font-weight: 500;"><i class="fas fa-magic me-1"></i> Auto-resize & compress</span>
                    </p>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4 p-3" style="background: #f8f9fa; border-radius: 12px; border: 2px dashed #dee2e6;">
                        <img src="{{ $faviconUrl }}" alt="Favicon saat ini" style="width: 48px; height: 48px; object-fit: contain;">
                        <p class="text-muted mt-2 mb-0" style="font-size: 0.75rem;">
                            {{ $branding['site_favicon'] ? 'Favicon custom aktif' : 'Favicon default' }}
                        </p>
                    </div>

                    <form action="{{ route('owner.branding.upload-favicon') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="input-group">
                            <input type="file" class="form-control" name="favicon" accept="image/png,image/x-icon,image/svg+xml,image/webp" required>
                            <button type="submit" class="btn btn-warning text-white">
                                <i class="fas fa-upload me-1"></i> Upload Favicon
                            </button>
                        </div>
                        <small class="text-muted">
                            Format: PNG, ICO, SVG, WebP. Maks upload 5MB.
                            <br>Sistem otomatis resize ke maks 256×256px & compress.
                        </small>
                    </form>

                    @if($branding['site_favicon'])
                        <form action="{{ route('owner.branding.remove-favicon') }}" method="POST" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Hapus favicon? Kembali ke default.')">
                                <i class="fas fa-trash me-1"></i> Hapus Favicon
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- RIGHT COLUMN: Site Name & Tagline --}}
        <div class="col-lg-5">
            <div class="card mb-4" style="border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
                <div class="card-header pb-0" style="background: transparent; border: none;">
                    <h6 class="mb-1"><i class="fas fa-pen-fancy me-2 text-success"></i> Nama & Tagline</h6>
                    <p class="text-muted mb-0" style="font-size: 0.8rem;">
                        Nama brand ditampilkan di semua halaman. Tagline muncul di footer & meta description.
                    </p>
                </div>
                <div class="card-body">
                    <form action="{{ route('owner.branding.update-info') }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label fw-bold">Nama Brand / Situs</label>
                            <input type="text" class="form-control" name="site_name" 
                                   value="{{ old('site_name', $branding['site_name']) }}" 
                                   maxlength="50" required>
                            <small class="text-muted">Ditampilkan di navbar, sidebar, footer, title halaman.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Tagline</label>
                            <input type="text" class="form-control" name="site_tagline" 
                                   value="{{ old('site_tagline', $branding['site_tagline']) }}" 
                                   maxlength="150" required>
                            <small class="text-muted">Ditampilkan di footer landing & meta description.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="fab fa-whatsapp text-success me-1"></i> Nomor WhatsApp Sales
                            </label>
                            <input type="text" class="form-control" name="sales_whatsapp" 
                                   value="{{ old('sales_whatsapp', $branding['sales_whatsapp']) }}" 
                                   maxlength="20"
                                   placeholder="628xxxxxxxxxx">
                            <small class="text-muted">
                                Format: 628xxx (tanpa + atau 0 di depan). Digunakan di tombol "Hubungi via WhatsApp" pada halaman Upgrade & Plan.
                            </small>
                            @if(empty($branding['sales_whatsapp']))
                                <div class="alert alert-warning mt-2 py-2 px-3" style="font-size: 0.8rem; border-radius: 8px;">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    <strong>Belum diisi!</strong> Tombol WhatsApp di halaman Upgrade akan <strong>non-aktif</strong> sampai nomor diset.
                                </div>
                            @else
                                <div class="mt-2">
                                    <a href="{{ $salesWhatsappUrl }}" target="_blank" class="btn btn-sm btn-outline-success">
                                        <i class="fab fa-whatsapp me-1"></i> Test: Buka WA ke {{ $branding['sales_whatsapp'] }}
                                    </a>
                                </div>
                            @endif
                        </div>

                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-save me-1"></i> Simpan Perubahan
                        </button>
                    </form>
                </div>
            </div>

            {{-- PREVIEW CARD --}}
            <div class="card" style="border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
                <div class="card-header pb-0" style="background: transparent; border: none;">
                    <h6 class="mb-1"><i class="fas fa-eye me-2 text-info"></i> Preview Tampilan</h6>
                </div>
                <div class="card-body">
                    {{-- Navbar Preview --}}
                    <div class="p-3 mb-3" style="background: #fff; border: 1px solid #e9ecef; border-radius: 8px;">
                        <small class="text-muted d-block mb-2">Navbar Landing:</small>
                        <div class="d-flex align-items-center">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="Logo" style="max-height: 32px; max-width: 120px; object-fit: contain;">
                            @else
                                <span style="font-weight: 700; font-size: 1.1rem;">
                                    <i class="fab fa-whatsapp text-success me-1"></i>
                                    {{ $branding['site_name'] }}
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Footer Preview --}}
                    <div class="p-3 mb-3" style="background: #1a1a2e; border-radius: 8px; color: #ccc;">
                        <small class="text-muted d-block mb-2" style="color: #888 !important;">Footer Landing:</small>
                        <div class="d-flex align-items-center mb-1">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="Logo" style="max-height: 24px; max-width: 100px; object-fit: contain; filter: brightness(1.5);">
                            @else
                                <span style="font-weight: 700; color: #fff;">
                                    <i class="fab fa-whatsapp text-success me-1"></i>
                                    {{ $branding['site_name'] }}
                                </span>
                            @endif
                        </div>
                        <small style="color: #888;">{{ $branding['site_tagline'] }}</small>
                    </div>

                    {{-- Sidebar Preview --}}
                    <div class="p-3" style="background: #1a1a2e; border-radius: 8px; color: #fff;">
                        <small class="text-muted d-block mb-2" style="color: #888 !important;">Sidebar Auth:</small>
                        <div class="d-flex align-items-center">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="Logo" style="max-height: 28px; max-width: 100px; object-fit: contain;">
                            @else
                                <span style="font-weight: 600;">
                                    <i class="ni ni-chat-round me-1"></i>
                                    {{ $branding['site_name'] }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SSOT Sync Status --}}
    <div class="card mt-4" style="border-radius: 16px; border: none; box-shadow: 0 4px 24px rgba(0,0,0,0.08);">
        <div class="card-body">
            <h6 class="mb-3"><i class="fas fa-sync-alt me-2"></i> Status Sinkronisasi SSOT</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="font-size: 0.8rem;">Halaman</th>
                            <th style="font-size: 0.8rem;">Komponen</th>
                            <th style="font-size: 0.8rem;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>Landing Page</code></td>
                            <td>Navbar logo, Footer logo, Copyright</td>
                            <td><span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT</span></td>
                        </tr>
                        <tr>
                            <td><code>Login</code></td>
                            <td>Guest navbar brand</td>
                            <td><span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT</span></td>
                        </tr>
                        <tr>
                            <td><code>Register</code></td>
                            <td>Guest navbar brand</td>
                            <td><span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT</span></td>
                        </tr>
                        <tr>
                            <td><code>Auth Sidebar</code></td>
                            <td>Sidebar brand header</td>
                            <td><span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT</span></td>
                        </tr>
                        <tr>
                            <td><code>All Pages</code></td>
                            <td>Favicon & Apple Touch Icon</td>
                            <td><span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT</span></td>
                        </tr>
                        <tr>
                            <td><code>Upgrade & Plan</code></td>
                            <td>CTA WhatsApp Sales</td>
                            <td>
                                @if($branding['sales_whatsapp'])
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i> SSOT ({{ $branding['sales_whatsapp'] }})</span>
                                @else
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> Belum diset</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
/**
 * Logo Processing Poller — auto-refresh preview when background job completes.
 * Only active when processing flag is set.
 */
(function() {
    const banner = document.getElementById('logo-processing-banner');
    if (!banner) return; // No processing in progress

    const statusUrl = '{{ route("owner.branding.logo-status") }}';
    let pollCount = 0;
    const maxPolls = 20; // Max ~60 seconds (20 × 3s)

    const poller = setInterval(async () => {
        pollCount++;
        if (pollCount > maxPolls) {
            clearInterval(poller);
            banner.innerHTML = '<p class="mb-0" style="font-size:0.8rem;color:#664d03;"><i class="fas fa-clock me-1"></i> Optimasi memakan waktu lebih lama dari biasanya. Refresh halaman secara manual.</p>';
            return;
        }

        try {
            const res = await fetch(statusUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (!data.processing) {
                clearInterval(poller);

                // Update preview
                const container = document.getElementById('logo-preview-container');
                const img = document.getElementById('logo-preview-img');
                const label = document.getElementById('logo-preview-label');

                if (data.logo_url && img) {
                    img.src = data.logo_url + '?t=' + Date.now();
                    if (label) label.textContent = 'Logo dioptimasi ✓';
                } else if (data.logo_url && container) {
                    // Logo baru — replace placeholder
                    container.innerHTML = '<img src="' + data.logo_url + '?t=' + Date.now() + '" alt="Logo" style="max-height:80px;max-width:280px;object-fit:contain;">' +
                        '<p class="text-muted mt-2 mb-0" style="font-size:0.75rem;">Logo dioptimasi ✓</p>';
                }

                // Remove processing banner with fade
                banner.style.transition = 'opacity 0.5s';
                banner.style.opacity = '0';
                setTimeout(() => banner.remove(), 500);

                // Show success toast
                const toast = document.createElement('div');
                toast.className = 'alert alert-success shadow-lg';
                toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;max-width:400px;border-radius:12px;animation:slideIn 0.3s ease;';
                toast.innerHTML = '<i class="fas fa-check-circle me-2"></i> Logo berhasil dioptimasi (WebP, max 800px).';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 4000);
            }
        } catch (e) {
            console.warn('Logo status poll failed:', e);
        }
    }, 3000);
})();
</script>
@endpush
