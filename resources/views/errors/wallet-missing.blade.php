@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-gradient-danger text-white">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <div>
                            <h4 class="mb-0">Wallet Sistem Tidak Ditemukan</h4>
                            <p class="mb-0 text-sm">Terjadi kesalahan pada sistem wallet Anda</p>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Informasi Teknis:</strong> {{ $error ?? 'Wallet tidak ditemukan di sistem' }}
                    </div>
                    
                    <h5 class="mt-4 mb-3">Detail Akun:</h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span><strong>User ID:</strong></span>
                            <span>{{ $user->id }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><strong>Email:</strong></span>
                            <span>{{ $user->email }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><strong>Onboarding Status:</strong></span>
                            <span>
                                @if($user->onboarding_complete)
                                    <span class="badge bg-success">Complete</span>
                                @else
                                    <span class="badge bg-warning">Incomplete</span>
                                @endif
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><strong>Klien ID:</strong></span>
                            <span>{{ $user->klien_id ?? 'N/A' }}</span>
                        </li>
                    </ul>
                    
                    <div class="mt-4 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="fas fa-clipboard-list me-2"></i>Langkah Selanjutnya:</h6>
                        <ol class="mb-0">
                            <li>Screenshot halaman ini</li>
                            <li>Hubungi administrator melalui support@talkabiz.com</li>
                            <li>Sertakan User ID dan informasi error di atas</li>
                        </ol>
                    </div>
                    
                    <div class="mt-4 d-flex gap-2">
                        <a href="{{ route('logout') }}" 
                           onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                           class="btn btn-outline-secondary">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                        
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                            @csrf
                        </form>
                        
                        <a href="{{ route('profile') }}" class="btn btn-outline-primary">
                            <i class="fas fa-user me-2"></i>Lihat Profil
                        </a>
                        
                        @if(in_array($user->role, ['super_admin', 'superadmin', 'admin']))
                        <a href="{{ route('settings') }}" class="btn btn-primary">
                            <i class="fas fa-cog me-2"></i>Pengaturan Sistem
                        </a>
                        @endif
                    </div>
                </div>
                
                <div class="card-footer text-muted">
                    <small>
                        <i class="fas fa-clock me-1"></i>
                        Error terjadi pada: {{ now()->format('d M Y, H:i:s') }} WIB
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
