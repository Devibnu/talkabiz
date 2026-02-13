@extends('owner.layouts.app')

@section('page-title', 'System Settings')

@section('content')

{{-- Page Header --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h4 class="font-weight-bolder mb-1">System Settings</h4>
                <p class="text-sm text-muted mb-0">Konfigurasi sistem — company info, kontak, dan default keuangan.</p>
            </div>
            <span class="badge bg-gradient-dark">
                <i class="fas fa-cog me-1"></i> Core Config
            </span>
        </div>
    </div>
</div>

{{-- Success Alert --}}
@if(session('success'))
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <span>{{ session('success') }}</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
</div>
@endif

{{-- Validation Errors --}}
@if($errors->any())
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Validasi gagal:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
</div>
@endif

<form action="{{ route('owner.settings.update') }}" method="POST">
    @csrf
    @method('PUT')

    {{-- Section 1: Company Info --}}
    <div class="row mb-4">
        <div class="col-lg-4 mb-3 mb-lg-0">
            <h6 class="font-weight-bolder">
                <i class="fas fa-building me-2 text-primary"></i>Company Info
            </h6>
            <p class="text-sm text-muted">Identitas perusahaan yang ditampilkan di halaman publik.</p>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-sm font-weight-bold">Company Name</label>
                            <input type="text" name="company_name" class="form-control"
                                   value="{{ old('company_name', $setting->company_name) }}"
                                   placeholder="Talkabiz">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-sm font-weight-bold">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control"
                                   value="{{ old('contact_email', $setting->contact_email) }}"
                                   placeholder="support@talkabiz.id">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-sm font-weight-bold">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control"
                                   value="{{ old('contact_phone', $setting->contact_phone) }}"
                                   placeholder="+62 812-3456-7890">
                            <small class="text-muted">Format tampil di halaman Contact</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-sm font-weight-bold">Operating Hours</label>
                            <input type="text" name="operating_hours" class="form-control"
                                   value="{{ old('operating_hours', $setting->operating_hours) }}"
                                   placeholder="Senin – Jumat, 09.00 – 17.00 WIB">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-sm font-weight-bold">Company Address</label>
                        <textarea name="company_address" class="form-control" rows="2"
                                  placeholder="Jakarta, Indonesia">{{ old('company_address', $setting->company_address) }}</textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Section 2: Sales & Marketing --}}
    <div class="row mb-4">
        <div class="col-lg-4 mb-3 mb-lg-0">
            <h6 class="font-weight-bolder">
                <i class="fab fa-whatsapp me-2 text-success"></i>Sales & Marketing
            </h6>
            <p class="text-sm text-muted">Nomor WhatsApp sales dan konfigurasi Google Maps.</p>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-sm font-weight-bold">Sales WhatsApp</label>
                        <input type="text" name="sales_whatsapp" class="form-control"
                               value="{{ old('sales_whatsapp', $setting->sales_whatsapp) }}"
                               placeholder="628123456789">
                        <small class="text-muted">Format: 628xxx (tanpa + atau spasi). Digunakan di CTA Upgrade & Plan Corporate.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-sm font-weight-bold">Google Maps Embed URL</label>
                        <input type="url" name="maps_embed_url" class="form-control"
                               value="{{ old('maps_embed_url', $setting->maps_embed_url) }}"
                               placeholder="https://www.google.com/maps?q=Jakarta&output=embed">
                        <small class="text-muted">URL iframe embed untuk halaman Contact. Salin dari Google Maps → Share → Embed.</small>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-sm font-weight-bold">Google Maps Link</label>
                        <input type="url" name="maps_link" class="form-control"
                               value="{{ old('maps_link', $setting->maps_link) }}"
                               placeholder="https://www.google.com/maps?q=Jakarta">
                        <small class="text-muted">URL untuk tombol "Buka di Google Maps".</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Section 3: Financial Defaults --}}
    <div class="row mb-4">
        <div class="col-lg-4 mb-3 mb-lg-0">
            <h6 class="font-weight-bolder">
                <i class="fas fa-calculator me-2 text-warning"></i>Financial Defaults
            </h6>
            <p class="text-sm text-muted">Default mata uang dan tarif pajak untuk billing dan invoice.</p>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <label class="form-label text-sm font-weight-bold">Default Currency</label>
                            <select name="default_currency" class="form-control">
                                <option value="IDR" {{ old('default_currency', $setting->default_currency) === 'IDR' ? 'selected' : '' }}>IDR — Rupiah</option>
                                <option value="USD" {{ old('default_currency', $setting->default_currency) === 'USD' ? 'selected' : '' }}>USD — US Dollar</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-sm font-weight-bold">Default Tax Rate (%)</label>
                            <input type="number" name="default_tax_percent" class="form-control"
                                   value="{{ old('default_tax_percent', $setting->default_tax_percent) }}"
                                   step="0.01" min="0" max="100"
                                   placeholder="11.00">
                            <small class="text-muted">PPN — diterapkan ke invoice billing.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Sticky Save Button --}}
    <div class="row">
        <div class="col-lg-8 offset-lg-4">
            <div class="card bg-gradient-dark">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <span class="text-white text-sm">
                        <i class="fas fa-info-circle me-1"></i>
                        Perubahan langsung berlaku setelah disimpan.
                    </span>
                    <button type="submit" class="btn btn-white mb-0">
                        <i class="fas fa-save me-1"></i> Simpan Settings
                    </button>
                </div>
            </div>
        </div>
    </div>

</form>
@endsection
