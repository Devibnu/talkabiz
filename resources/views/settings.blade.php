@extends('layouts.user_type.auth')

@push('styles')
<style>
/* Settings Page - Soft UI Style */
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

.settings-card {
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 8px 26px -4px hsla(0,0%,8%,.15), 0 8px 9px -5px hsla(0,0%,8%,.06);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.settings-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e9ecef;
    background: linear-gradient(310deg, #f8f9fa 0%, #fff 100%);
}

.settings-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.settings-card-title i {
    color: #5e72e4;
}

.settings-card-body {
    padding: 1.5rem;
}

.settings-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid #f0f2f5;
}

.settings-item:last-child {
    border-bottom: none;
}

.settings-item-info h6 {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #344767;
    margin: 0 0 0.25rem 0;
}

.settings-item-info p {
    font-size: 0.8125rem;
    color: #67748e;
    margin: 0;
}

.settings-value {
    font-size: 0.875rem;
    color: #344767;
    font-weight: 500;
}

.badge-env {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.5rem;
}

.badge-env.production {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}

.badge-env.local {
    background: #fff3cd;
    color: #856404;
}

.quick-links {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.quick-link-card {
    background: linear-gradient(310deg, #f8f9fa 0%, #fff 100%);
    border: 1px solid #e9ecef;
    border-radius: 0.75rem;
    padding: 1.25rem;
    text-decoration: none;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.quick-link-card:hover {
    border-color: #5e72e4;
    box-shadow: 0 4px 12px -2px rgba(94, 114, 228, 0.15);
    transform: translateY(-2px);
}

.quick-link-icon {
    width: 48px;
    height: 48px;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #fff;
}

.quick-link-icon.payment {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
}

.quick-link-icon.profile {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
}

.quick-link-icon.security {
    background: linear-gradient(310deg, #f5365c 0%, #f56036 100%);
}

.quick-link-info h6 {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #344767;
    margin: 0 0 0.25rem 0;
}

.quick-link-info p {
    font-size: 0.75rem;
    color: #67748e;
    margin: 0;
}
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="page-header-row">
        <div>
            <h4 class="page-title">
                <i class="ni ni-settings-gear-65 me-2"></i>
                Settings
            </h4>
            <p class="page-subtitle">Pengaturan umum aplikasi {{ $__brandName ?? 'Talkabiz' }}</p>
        </div>
    </div>

    {{-- App Info Card --}}
    <div class="settings-card">
        <div class="settings-card-header">
            <h6 class="settings-card-title">
                <i class="ni ni-app"></i>
                Informasi Aplikasi
            </h6>
        </div>
        <div class="settings-card-body">
            <div class="settings-item">
                <div class="settings-item-info">
                    <h6>Nama Aplikasi</h6>
                    <p>Nama yang ditampilkan di aplikasi</p>
                </div>
                <span class="settings-value">{{ config('app.name', 'Talkabiz') }}</span>
            </div>
            <div class="settings-item">
                <div class="settings-item-info">
                    <h6>Environment</h6>
                    <p>Mode aplikasi saat ini</p>
                </div>
                <span class="badge-env {{ config('app.env', 'local') }}">{{ ucfirst(config('app.env', 'local')) }}</span>
            </div>
            <div class="settings-item">
                <div class="settings-item-info">
                    <h6>Versi Laravel</h6>
                    <p>Framework yang digunakan</p>
                </div>
                <span class="settings-value">{{ app()->version() }}</span>
            </div>
            <div class="settings-item">
                <div class="settings-item-info">
                    <h6>PHP Version</h6>
                    <p>Versi PHP server</p>
                </div>
                <span class="settings-value">{{ phpversion() }}</span>
            </div>
            <div class="settings-item">
                <div class="settings-item-info">
                    <h6>Timezone</h6>
                    <p>Zona waktu aplikasi</p>
                </div>
                <span class="settings-value">{{ config('app.timezone', 'UTC') }}</span>
            </div>
        </div>
    </div>

    {{-- Quick Links --}}
    <div class="settings-card">
        <div class="settings-card-header">
            <h6 class="settings-card-title">
                <i class="ni ni-compass-04"></i>
                Pengaturan Lainnya
            </h6>
        </div>
        <div class="settings-card-body">
            <div class="quick-links">
                @if(auth()->user()->role === 'super_admin')
                <a href="{{ url('settings/payment-gateway') }}" class="quick-link-card">
                    <div class="quick-link-icon payment">
                        <i class="ni ni-credit-card"></i>
                    </div>
                    <div class="quick-link-info">
                        <h6>Payment Gateway</h6>
                        <p>Kelola Midtrans & Xendit</p>
                    </div>
                </a>
                @endif
                
                <a href="{{ url('user-profile') }}" class="quick-link-card">
                    <div class="quick-link-icon profile">
                        <i class="ni ni-single-02"></i>
                    </div>
                    <div class="quick-link-info">
                        <h6>Profil Saya</h6>
                        <p>Ubah informasi akun</p>
                    </div>
                </a>
                
                <a href="#" class="quick-link-card" onclick="ClientPopup.comingSoon('Fitur Keamanan'); return false;">
                    <div class="quick-link-icon security">
                        <i class="ni ni-lock-circle-open"></i>
                    </div>
                    <div class="quick-link-info">
                        <h6>Keamanan</h6>
                        <p>Password & 2FA</p>
                    </div>
                </a>
            </div>
        </div>
    </div>

</div>
@endsection