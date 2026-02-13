@extends('layouts.user_type.auth')

@push('styles')
<style>
/* ============================================
   PAYMENT GATEWAY SETTINGS - Soft UI Style
   ============================================ */

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

/* Gateway Card */
.gateway-card {
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 8px 26px -4px hsla(0,0%,8%,.15), 0 8px 9px -5px hsla(0,0%,8%,.06);
    overflow: hidden;
    margin-bottom: 1.5rem;
    transition: all 0.2s ease;
}

.gateway-card.is-active {
    border: 2px solid #17ad37;
}

.gateway-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(310deg, #f8f9fa 0%, #fff 100%);
}

.gateway-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.gateway-logo {
    width: 48px;
    height: 48px;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #fff;
}

.gateway-logo.midtrans {
    background: linear-gradient(310deg, #0070ba 0%, #00a1f1 100%);
}

.gateway-logo.xendit {
    background: linear-gradient(310deg, #4f46e5 0%, #7c3aed 100%);
}

.gateway-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}

.gateway-desc {
    font-size: 0.8125rem;
    color: #67748e;
    margin: 0.25rem 0 0 0;
}

.gateway-badges {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.badge-status {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.badge-status.active {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}

.badge-status.enabled {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
}

.badge-status.disabled {
    background: #e9ecef;
    color: #67748e;
}

.badge-env {
    padding: 0.25rem 0.625rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    border-radius: 0.375rem;
}

.badge-env.sandbox {
    background: #fff3cd;
    color: #856404;
}

.badge-env.production {
    background: #d4edda;
    color: #155724;
}

.gateway-card-body {
    padding: 1.5rem;
}

/* Form Styles */
.form-section {
    margin-bottom: 1.5rem;
}

.form-section-title {
    font-size: 0.875rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title i {
    color: #5e72e4;
}

.form-label-soft {
    font-size: 0.8125rem;
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
    width: 100%;
}

.form-control-soft:focus {
    border-color: #5e72e4;
    box-shadow: 0 0 0 2px rgba(94, 114, 228, 0.25);
    outline: none;
}

.form-control-soft.is-masked {
    font-family: 'Courier New', monospace;
    letter-spacing: 0.1em;
}

.form-select-soft {
    border-radius: 0.5rem;
    border: 1px solid #e9ecef;
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    background-color: #fff;
    cursor: pointer;
}

/* Toggle Switch */
.toggle-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.toggle-switch {
    position: relative;
    width: 48px;
    height: 26px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #e9ecef;
    transition: 0.3s;
    border-radius: 26px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.toggle-switch input:checked + .toggle-slider {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(22px);
}

.toggle-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #344767;
}

/* Key Input Group */
.key-input-group {
    position: relative;
}

.key-input-group .form-control-soft {
    padding-right: 3rem;
}

.key-toggle-btn {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #67748e;
    cursor: pointer;
    padding: 0.25rem;
}

.key-toggle-btn:hover {
    color: #5e72e4;
}

/* Action Buttons */
.btn-soft-primary {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border: none;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 700;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.btn-soft-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
}

.btn-soft-success {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
    border: none;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 700;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-soft-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 7px -1px rgba(23, 173, 55, 0.4);
}

.btn-soft-success:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-outline-secondary {
    background: transparent;
    border: 1px solid #e9ecef;
    color: #67748e;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 600;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.btn-outline-secondary:hover {
    background: #f8f9fa;
    border-color: #d1d5db;
}

/* Card Footer */
.gateway-card-footer {
    padding: 1rem 1.5rem;
    background: #fafbfc;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.last-updated {
    font-size: 0.75rem;
    color: #67748e;
}

/* Alert Styles */
.alert-soft {
    padding: 1rem 1.25rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-soft.alert-success {
    background: linear-gradient(310deg, rgba(23, 173, 55, 0.1) 0%, rgba(152, 236, 45, 0.1) 100%);
    border: 1px solid rgba(23, 173, 55, 0.2);
    color: #17ad37;
}

.alert-soft.alert-danger {
    background: linear-gradient(310deg, rgba(234, 6, 6, 0.1) 0%, rgba(255, 102, 124, 0.1) 100%);
    border: 1px solid rgba(234, 6, 6, 0.2);
    color: #ea0606;
}

.alert-soft.alert-warning {
    background: linear-gradient(310deg, rgba(251, 207, 51, 0.1) 0%, rgba(255, 219, 102, 0.1) 100%);
    border: 1px solid rgba(251, 207, 51, 0.3);
    color: #856404;
}

.alert-soft i {
    font-size: 1.25rem;
}

/* Info Box */
.info-box {
    background: linear-gradient(310deg, #f8f9fa 0%, #fff 100%);
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-top: 1rem;
}

.info-box-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 0.5rem;
}

.info-box-text {
    font-size: 0.8125rem;
    color: #67748e;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .gateway-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .gateway-badges {
        flex-wrap: wrap;
    }
    
    .gateway-card-footer {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
}
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="page-header-row">
        <div>
            <h4 class="page-title">
                <i class="ni ni-credit-card me-2"></i>
                Payment Gateway
            </h4>
            <p class="page-subtitle">Kelola konfigurasi payment gateway untuk sistem billing</p>
        </div>
    </div>

    {{-- Alerts --}}
    @if(session('success'))
    <div class="alert-soft alert-success">
        <i class="ni ni-check-bold"></i>
        <span>{{ session('success') }}</span>
    </div>
    @endif

    @if(session('error'))
    <div class="alert-soft alert-danger">
        <i class="ni ni-fat-remove"></i>
        <span>{{ session('error') }}</span>
    </div>
    @endif

    @if($errors->any())
    <div class="alert-soft alert-danger">
        <i class="ni ni-fat-remove"></i>
        <div>
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Active Gateway Info --}}
    @if($activeGateway)
    <div class="alert-soft alert-success mb-4">
        <i class="ni ni-check-bold"></i>
        <span>Gateway aktif saat ini: <strong>{{ $activeGateway->display_name }}</strong> ({{ $activeGateway->environment }})</span>
    </div>
    @else
    <div class="alert-soft alert-warning mb-4">
        <i class="ni ni-notification-70"></i>
        <span>Belum ada payment gateway aktif. Silakan konfigurasi dan aktifkan salah satu gateway di bawah.</span>
    </div>
    @endif

    {{-- Midtrans Card --}}
    <div class="gateway-card {{ $midtrans->is_active ? 'is-active' : '' }}">
        <div class="gateway-card-header">
            <div class="gateway-info">
                <div class="gateway-logo midtrans">
                    <i class="ni ni-money-coins"></i>
                </div>
                <div>
                    <h5 class="gateway-title">{{ $midtrans->display_name ?? 'Midtrans' }}</h5>
                    <p class="gateway-desc">{{ $midtrans->description ?? 'Payment gateway untuk Indonesia' }}</p>
                </div>
            </div>
            <div class="gateway-badges">
                @if($midtrans->is_active)
                    <span class="badge-status active"><i class="ni ni-check-bold"></i> Aktif</span>
                @endif
                @if($midtrans->is_enabled)
                    <span class="badge-status enabled">Enabled</span>
                @else
                    <span class="badge-status disabled">Disabled</span>
                @endif
                <span class="badge-env {{ $midtrans->environment ?? 'sandbox' }}">{{ ucfirst($midtrans->environment ?? 'sandbox') }}</span>
            </div>
        </div>
        
        <div class="gateway-card-body">
            <form action="{{ route('settings.payment-gateway.update-midtrans') }}" method="POST">
                @csrf
                
                {{-- Enable Toggle --}}
                <div class="form-section">
                    <div class="toggle-wrapper">
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_enabled" value="1" {{ $midtrans->is_enabled ? 'checked' : '' }}>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Enable Midtrans</span>
                    </div>
                </div>

                {{-- Environment --}}
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="ni ni-settings-gear-65"></i>
                        Environment
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <select name="environment" class="form-select-soft w-100">
                                <option value="sandbox" {{ ($midtrans->environment ?? 'sandbox') == 'sandbox' ? 'selected' : '' }}>Sandbox (Testing)</option>
                                <option value="production" {{ ($midtrans->environment ?? 'sandbox') == 'production' ? 'selected' : '' }}>Production (Live)</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- API Keys --}}
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="ni ni-key-25"></i>
                        API Credentials
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-soft">Server Key</label>
                            <div class="key-input-group">
                                <input type="password" 
                                       name="server_key" 
                                       class="form-control-soft is-masked" 
                                       placeholder="{{ $midtrans->isConfigured() ? $midtrans->getMaskedServerKey() : 'Masukkan Server Key' }}"
                                       autocomplete="off">
                                <button type="button" class="key-toggle-btn" onclick="toggleKeyVisibility(this)">
                                    <i class="ni ni-watch-time"></i>
                                </button>
                            </div>
                            <small class="text-muted">Kosongkan jika tidak ingin mengubah</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-soft">Client Key</label>
                            <div class="key-input-group">
                                <input type="password" 
                                       name="client_key" 
                                       class="form-control-soft is-masked" 
                                       placeholder="{{ $midtrans->isConfigured() ? $midtrans->getMaskedClientKey() : 'Masukkan Client Key' }}"
                                       autocomplete="off">
                                <button type="button" class="key-toggle-btn" onclick="toggleKeyVisibility(this)">
                                    <i class="ni ni-watch-time"></i>
                                </button>
                            </div>
                            <small class="text-muted">Kosongkan jika tidak ingin mengubah</small>
                        </div>
                    </div>
                </div>

                {{-- Info Box --}}
                <div class="info-box">
                    <div class="info-box-title">
                        <i class="ni ni-bulb-61 me-1" style="color: #fbcf33;"></i>
                        Cara Mendapatkan API Key
                    </div>
                    <p class="info-box-text">
                        1. Login ke <a href="https://dashboard.midtrans.com" target="_blank">Midtrans Dashboard</a><br>
                        2. Pilih Environment (Sandbox/Production)<br>
                        3. Pergi ke Settings → Access Keys<br>
                        4. Copy Server Key dan Client Key
                    </p>
                </div>

                <div class="gateway-card-footer">
                    <div class="last-updated">
                        @if($midtrans->updated_at)
                            Terakhir diperbarui: {{ $midtrans->updated_at->format('d M Y H:i') }}
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        @if($midtrans->is_enabled && $midtrans->isConfigured() && !$midtrans->is_active)
                        <form action="{{ route('settings.payment-gateway.set-active') }}" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="gateway" value="midtrans">
                            <button type="submit" class="btn-soft-success">
                                <i class="ni ni-check-bold"></i>
                                Jadikan Aktif
                            </button>
                        </form>
                        @endif
                        <button type="submit" class="btn-soft-primary">
                            <i class="ni ni-check-bold"></i>
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Xendit Card --}}
    <div class="gateway-card {{ $xendit->is_active ? 'is-active' : '' }}">
        <div class="gateway-card-header">
            <div class="gateway-info">
                <div class="gateway-logo xendit">
                    <i class="ni ni-world"></i>
                </div>
                <div>
                    <h5 class="gateway-title">{{ $xendit->display_name ?? 'Xendit' }}</h5>
                    <p class="gateway-desc">{{ $xendit->description ?? 'Payment gateway untuk Southeast Asia' }}</p>
                </div>
            </div>
            <div class="gateway-badges">
                @if($xendit->is_active)
                    <span class="badge-status active"><i class="ni ni-check-bold"></i> Aktif</span>
                @endif
                @if($xendit->is_enabled)
                    <span class="badge-status enabled">Enabled</span>
                @else
                    <span class="badge-status disabled">Disabled</span>
                @endif
                <span class="badge-env {{ $xendit->environment ?? 'sandbox' }}">{{ ucfirst($xendit->environment ?? 'sandbox') }}</span>
            </div>
        </div>
        
        <div class="gateway-card-body">
            <form action="{{ route('settings.payment-gateway.update-xendit') }}" method="POST">
                @csrf
                
                {{-- Enable Toggle --}}
                <div class="form-section">
                    <div class="toggle-wrapper">
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_enabled" value="1" {{ $xendit->is_enabled ? 'checked' : '' }}>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">Enable Xendit</span>
                    </div>
                </div>

                {{-- Environment --}}
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="ni ni-settings-gear-65"></i>
                        Environment
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <select name="environment" class="form-select-soft w-100">
                                <option value="sandbox" {{ ($xendit->environment ?? 'sandbox') == 'sandbox' ? 'selected' : '' }}>Sandbox (Testing)</option>
                                <option value="production" {{ ($xendit->environment ?? 'sandbox') == 'production' ? 'selected' : '' }}>Production (Live)</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- API Keys --}}
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="ni ni-key-25"></i>
                        API Credentials
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-soft">Secret API Key</label>
                            <div class="key-input-group">
                                <input type="password" 
                                       name="server_key" 
                                       class="form-control-soft is-masked" 
                                       placeholder="{{ $xendit->isConfigured() ? $xendit->getMaskedServerKey() : 'Masukkan Secret API Key' }}"
                                       autocomplete="off">
                                <button type="button" class="key-toggle-btn" onclick="toggleKeyVisibility(this)">
                                    <i class="ni ni-watch-time"></i>
                                </button>
                            </div>
                            <small class="text-muted">Kosongkan jika tidak ingin mengubah</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-soft">Public API Key</label>
                            <div class="key-input-group">
                                <input type="password" 
                                       name="client_key" 
                                       class="form-control-soft is-masked" 
                                       placeholder="{{ $xendit->isConfigured() ? $xendit->getMaskedClientKey() : 'Masukkan Public API Key' }}"
                                       autocomplete="off">
                                <button type="button" class="key-toggle-btn" onclick="toggleKeyVisibility(this)">
                                    <i class="ni ni-watch-time"></i>
                                </button>
                            </div>
                            <small class="text-muted">Kosongkan jika tidak ingin mengubah</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-soft">Webhook Verification Token</label>
                            <div class="key-input-group">
                                <input type="password" 
                                       name="webhook_secret" 
                                       class="form-control-soft is-masked" 
                                       placeholder="Masukkan Webhook Token"
                                       autocomplete="off">
                                <button type="button" class="key-toggle-btn" onclick="toggleKeyVisibility(this)">
                                    <i class="ni ni-watch-time"></i>
                                </button>
                            </div>
                            <small class="text-muted">Optional - untuk verifikasi webhook</small>
                        </div>
                    </div>
                </div>

                {{-- Info Box --}}
                <div class="info-box">
                    <div class="info-box-title">
                        <i class="ni ni-bulb-61 me-1" style="color: #fbcf33;"></i>
                        Cara Mendapatkan API Key
                    </div>
                    <p class="info-box-text">
                        1. Login ke <a href="https://dashboard.xendit.co" target="_blank">Xendit Dashboard</a><br>
                        2. Pergi ke Settings → API Keys<br>
                        3. Generate atau copy Secret/Public API Key<br>
                        4. Untuk webhook token, pergi ke Settings → Webhooks
                    </p>
                </div>

                <div class="gateway-card-footer">
                    <div class="last-updated">
                        @if($xendit->updated_at)
                            Terakhir diperbarui: {{ $xendit->updated_at->format('d M Y H:i') }}
                        @endif
                    </div>
                    <div class="d-flex gap-2">
                        @if($xendit->is_enabled && $xendit->isConfigured() && !$xendit->is_active)
                        <form action="{{ route('settings.payment-gateway.set-active') }}" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="gateway" value="xendit">
                            <button type="submit" class="btn-soft-success">
                                <i class="ni ni-check-bold"></i>
                                Jadikan Aktif
                            </button>
                        </form>
                        @endif
                        <button type="submit" class="btn-soft-primary">
                            <i class="ni ni-check-bold"></i>
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('dashboard')
<script>
function toggleKeyVisibility(btn) {
    const input = btn.parentElement.querySelector('input');
    const icon = btn.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'ni ni-glasses-2';
    } else {
        input.type = 'password';
        icon.className = 'ni ni-watch-time';
    }
}
</script>
@endpush
