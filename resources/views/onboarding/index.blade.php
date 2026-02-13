@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-6">
            {{-- Progress Steps --}}
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-center flex-fill">
                            <div class="icon icon-shape icon-sm {{ $status['current_step'] >= 1 ? 'bg-gradient-primary' : 'bg-gradient-secondary' }} shadow text-white rounded-circle mx-auto mb-2">
                                <span class="text-sm">1</span>
                            </div>
                            <p class="text-xs mb-0 {{ $status['current_step'] == 1 ? 'text-primary font-weight-bold' : '' }}">Profil Bisnis</p>
                        </div>
                        <div class="flex-fill border-top mx-2" style="height: 2px;"></div>
                        <div class="text-center flex-fill">
                            <div class="icon icon-shape icon-sm {{ $status['has_wallet'] ? 'bg-gradient-success' : 'bg-gradient-secondary' }} shadow text-white rounded-circle mx-auto mb-2">
                                <span class="text-sm">2</span>
                            </div>
                            <p class="text-xs mb-0">Wallet</p>
                        </div>
                        <div class="flex-fill border-top mx-2" style="height: 2px;"></div>
                        <div class="text-center flex-fill">
                            <div class="icon icon-shape icon-sm {{ $status['has_plan'] ? 'bg-gradient-success' : 'bg-gradient-secondary' }} shadow text-white rounded-circle mx-auto mb-2">
                                <span class="text-sm">3</span>
                            </div>
                            <p class="text-xs mb-0">Paket</p>
                        </div>
                    </div>
                </div>
            </div>
            
            {{-- Main Card --}}
            <div class="card shadow-lg">
                <div class="card-header bg-gradient-primary p-4">
                    <div class="d-flex align-items-center">
                        <div class="icon icon-shape icon-lg bg-white shadow text-center border-radius-md me-3">
                            <i class="fas fa-building text-primary text-lg"></i>
                        </div>
                        <div>
                            <h4 class="text-white mb-0">Selamat Datang di {{ $__brandName ?? 'Talkabiz' }}!</h4>
                            <p class="text-white text-sm mb-0 opacity-8">Lengkapi profil bisnis Anda untuk memulai</p>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    {{-- Alert Messages --}}
                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <span class="alert-icon"><i class="fas fa-times-circle"></i></span>
                            <span class="alert-text">{{ session('error') }}</span>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif
                    
                    {{-- Info Box --}}
                    <div class="alert alert-info mb-4">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="fas fa-info-circle fa-lg"></i>
                            </div>
                            <div>
                                <p class="mb-0 text-sm">
                                    <strong>Satu langkah lagi!</strong><br>
                                    Isi form di bawah, maka wallet dan paket FREE akan otomatis dibuat untuk Anda.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    {{-- Form --}}
                    <form action="{{ route('onboarding.store') }}" method="POST" id="onboardingForm">
                        @csrf
                        
                        {{-- Nama Bisnis --}}
                        <div class="mb-4">
                            <label class="form-label">Nama Bisnis <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-store"></i></span>
                                <input type="text" 
                                       name="nama_perusahaan" 
                                       class="form-control @error('nama_perusahaan') is-invalid @enderror"
                                       value="{{ old('nama_perusahaan') }}"
                                       placeholder="Contoh: Toko Berkah Jaya"
                                       required>
                            </div>
                            @error('nama_perusahaan')
                                <div class="text-danger text-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        {{-- Tipe Bisnis --}}
                        <div class="mb-4">
                            <label class="form-label">Tipe Bisnis <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                <select name="tipe_bisnis" class="form-select @error('tipe_bisnis') is-invalid @enderror" required>
                                    <option value="">-- Pilih Tipe Bisnis --</option>
                                    @foreach($business_types as $type)
                                        <option value="{{ $type->code }}" {{ old('tipe_bisnis') == $type->code ? 'selected' : '' }}>
                                            {{ $type->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="text-xs text-muted mt-1">
                                <i class="fas fa-info-circle"></i> Pilih jenis badan usaha Anda
                            </div>
                            @error('tipe_bisnis')
                                <div class="text-danger text-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        {{-- No WhatsApp --}}
                        <div class="mb-4">
                            <label class="form-label">Nomor WhatsApp Bisnis <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fab fa-whatsapp"></i></span>
                                <input type="text" 
                                       name="no_whatsapp" 
                                       class="form-control @error('no_whatsapp') is-invalid @enderror"
                                       value="{{ old('no_whatsapp', $user->phone ?? '') }}"
                                       placeholder="628123456789"
                                       pattern="62[0-9]{9,12}"
                                       required>
                            </div>
                            <div class="text-xs text-muted mt-1">Format: 62 + nomor (tanpa + atau 0). Contoh: 628123456789</div>
                            @error('no_whatsapp')
                                <div class="text-danger text-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        {{-- Kota --}}
                        <div class="mb-4">
                            <label class="form-label">Kota <span class="text-muted">(opsional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                <input type="text" 
                                       name="kota" 
                                       class="form-control @error('kota') is-invalid @enderror"
                                       value="{{ old('kota') }}"
                                       placeholder="Contoh: Jakarta">
                            </div>
                            @error('kota')
                                <div class="text-danger text-xs mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        {{-- Submit --}}
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="fas fa-check me-2"></i>
                                Buat Akun Bisnis
                            </button>
                        </div>
                    </form>
                </div>
                
                {{-- Footer --}}
                <div class="card-footer bg-light py-3">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="fas fa-lock text-muted me-2"></i>
                        <span class="text-xs text-muted">Data Anda aman dan tidak akan dibagikan ke pihak lain</span>
                    </div>
                </div>
            </div>
            
            {{-- What You'll Get --}}
            <div class="card mt-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Yang Akan Anda Dapatkan</h6>
                </div>
                <div class="card-body pt-2">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <div class="icon icon-shape icon-md bg-gradient-success shadow text-white rounded-circle mx-auto mb-2">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <p class="text-sm mb-0 font-weight-bold">Wallet Otomatis</p>
                            <p class="text-xs text-muted mb-0">Untuk top-up & bayar pesan</p>
                        </div>
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <div class="icon icon-shape icon-md bg-gradient-info shadow text-white rounded-circle mx-auto mb-2">
                                <i class="fas fa-gift"></i>
                            </div>
                            <p class="text-sm mb-0 font-weight-bold">Paket FREE</p>
                            <p class="text-xs text-muted mb-0">100 pesan/bulan gratis</p>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="icon icon-shape icon-md bg-gradient-warning shadow text-white rounded-circle mx-auto mb-2">
                                <i class="fas fa-rocket"></i>
                            </div>
                            <p class="text-sm mb-0 font-weight-bold">Akses Dashboard</p>
                            <p class="text-xs text-muted mb-0">Mulai kirim campaign</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('onboardingForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Memproses...';
});
</script>
@endpush
@endsection
