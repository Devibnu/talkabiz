@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5 text-center">
                    {{-- Icon --}}
                    <div class="icon icon-shape icon-xl bg-gradient-warning shadow-warning text-white rounded-circle mb-4 mx-auto">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                    </div>
                    
                    {{-- Title --}}
                    <h3 class="mb-3">Akun Belum Lengkap</h3>
                    
                    {{-- Message --}}
                    <p class="text-muted mb-4">
                        {{ $message ?? 'Akun Anda belum memiliki konfigurasi yang lengkap untuk menggunakan dashboard.' }}
                    </p>
                    
                    {{-- Status Details --}}
                    <div class="bg-light rounded p-3 mb-4 text-start">
                        <h6 class="text-sm mb-3">Status Akun:</h6>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                @if($user->klien_id)
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <span>Profil Bisnis: Terhubung</span>
                                @else
                                    <i class="fas fa-times-circle text-danger me-2"></i>
                                    <span>Profil Bisnis: <strong>Belum Dibuat</strong></span>
                                @endif
                            </li>
                            <li class="mb-2">
                                @if($has_klien && optional($user->klien)->dompet)
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <span>Wallet: Aktif</span>
                                @else
                                    <i class="fas fa-times-circle text-danger me-2"></i>
                                    <span>Wallet: <strong>Belum Dibuat</strong></span>
                                @endif
                            </li>
                            <li>
                                @if($user->current_plan_id)
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    <span>Paket: {{ $user->currentPlan?->name ?? 'Aktif' }}</span>
                                @else
                                    <i class="fas fa-times-circle text-danger me-2"></i>
                                    <span>Paket: <strong>Belum Diassign</strong></span>
                                @endif
                            </li>
                        </ul>
                    </div>
                    
                    {{-- Next Steps --}}
                    <div class="alert alert-info text-start mb-4">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle me-2"></i>Langkah Selanjutnya
                        </h6>
                        <p class="mb-0 text-sm">
                            Hubungi tim support {{ $__brandName ?? 'Talkabiz' }} untuk memperbaiki akun Anda. 
                            Tim kami akan membantu menyelesaikan konfigurasi yang diperlukan.
                        </p>
                    </div>
                    
                    {{-- Actions --}}
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="mailto:support@talkabiz.id?subject=Akun%20Belum%20Lengkap%20-%20{{ $user->email }}" 
                           class="btn btn-primary">
                            <i class="fas fa-envelope me-2"></i>
                            Hubungi Support
                        </a>
                        <a href="{{ route('logout') }}" 
                           class="btn btn-outline-secondary"
                           onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                            <i class="fas fa-sign-out-alt me-2"></i>
                            Logout
                        </a>
                    </div>
                    
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>
                    
                    {{-- Debug Info (dev only) --}}
                    @if(config('app.debug'))
                    <div class="mt-4 pt-4 border-top text-start">
                        <p class="text-xs text-muted mb-2">Debug Info:</p>
                        <code class="text-xs">
                            User ID: {{ $user->id }}<br>
                            Email: {{ $user->email }}<br>
                            Klien ID: {{ $user->klien_id ?? 'NULL' }}<br>
                            Role: {{ $user->role }}
                        </code>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
