@extends('owner.layouts.app')

@section('title', 'Payment Gateway')
@section('page-title', 'Payment Gateway')

@section('content')
{{-- Header --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="font-weight-bolder mb-0">Payment Gateway</h5>
                <p class="text-sm text-secondary mb-0">Kelola konfigurasi payment gateway untuk top-up saldo</p>
            </div>
            <div>
                @if($activeGateway)
                    <span class="badge bg-gradient-success px-3 py-2">
                        <i class="fas fa-check-circle me-1"></i>
                        {{ $activeGateway->display_name }} Active
                    </span>
                @else
                    <span class="badge bg-gradient-warning px-3 py-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Tidak Ada Gateway Aktif
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Alerts --}}
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <span class="alert-icon"><i class="fas fa-check-circle"></i></span>
        <span class="alert-text">{{ session('success') }}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <span class="alert-icon"><i class="fas fa-exclamation-circle"></i></span>
        <span class="alert-text">{{ session('error') }}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Warning if no active gateway --}}
@if(!$activeGateway)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-warning">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="icon icon-shape bg-white shadow text-center border-radius-md me-3">
                            <i class="fas fa-exclamation-triangle text-warning text-lg"></i>
                        </div>
                        <div class="text-white">
                            <p class="text-sm font-weight-bold mb-0">Perhatian: Tidak Ada Gateway Aktif</p>
                            <p class="text-xs mb-0 opacity-8">User tidak dapat melakukan top-up saldo. Silakan konfigurasi dan aktifkan salah satu payment gateway di bawah.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Gateway Cards --}}
<div class="row">
    @forelse($gateways as $gateway)
        <div class="col-lg-6 mb-4">
            <div class="card h-100 {{ $gateway->is_active ? 'border border-success border-2' : '' }}">
                <div class="card-header p-3 {{ $gateway->is_active ? 'bg-gradient-success' : 'bg-gradient-dark' }}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center">
                            <x-payment-logo :name="$gateway->name" size="md" class="me-2" />
                            <h6 class="mb-0 text-white">{{ $gateway->display_name }}</h6>
                        </div>
                        <div class="d-flex gap-1">
                            @if($gateway->is_active)
                                <span class="badge bg-white text-success">ACTIVE</span>
                            @endif
                            @if($gateway->is_enabled)
                                <span class="badge bg-success">Enabled</span>
                            @else
                                <span class="badge bg-secondary">Disabled</span>
                            @endif
                            <span class="badge {{ $gateway->environment === 'production' ? 'bg-danger' : 'bg-info' }}">
                                {{ ucfirst($gateway->environment) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-3">
                    <p class="text-xs text-secondary mb-3">{{ $gateway->description }}</p>

                    <form action="{{ route('owner.payment-gateway.update', $gateway) }}" method="POST">
                        @csrf
                        @method('PUT')

                        {{-- Environment --}}
                        <div class="mb-3">
                            <label class="form-label text-sm font-weight-bold">Environment</label>
                            <select name="environment" class="form-select form-select-sm">
                                <option value="sandbox" {{ $gateway->environment === 'sandbox' ? 'selected' : '' }}>
                                    🧪 Sandbox (Testing)
                                </option>
                                <option value="production" {{ $gateway->environment === 'production' ? 'selected' : '' }}>
                                    🚀 Production (Live)
                                </option>
                            </select>
                        </div>

                        {{-- Server Key --}}
                        <div class="mb-3">
                            <label class="form-label text-sm font-weight-bold">
                                {{ $gateway->name === 'xendit' ? 'Secret Key' : 'Server Key' }}
                                @if($gateway->server_key)
                                    <span class="badge bg-gradient-success ms-1">✓</span>
                                @else
                                    <span class="badge bg-gradient-warning ms-1">!</span>
                                @endif
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                       name="server_key" 
                                       class="form-control" 
                                       id="server_key_{{ $gateway->id }}"
                                       placeholder="{{ $gateway->server_key ? '••••••••••••••••' : 'Masukkan Server Key' }}"
                                       autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary mb-0" onclick="togglePassword('server_key_{{ $gateway->id }}')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <p class="text-xs text-secondary mt-1 mb-0">Kosongkan jika tidak ingin mengubah</p>
                            @error('server_key')
                                <p class="text-xs text-danger mt-1 mb-0">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Client Key --}}
                        <div class="mb-3">
                            <label class="form-label text-sm font-weight-bold">
                                {{ $gateway->name === 'xendit' ? 'Public Key' : 'Client Key' }}
                                @if($gateway->client_key)
                                    <span class="badge bg-gradient-success ms-1">✓</span>
                                @else
                                    <span class="badge bg-gradient-secondary ms-1">-</span>
                                @endif
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                       name="client_key" 
                                       class="form-control" 
                                       id="client_key_{{ $gateway->id }}"
                                       placeholder="{{ $gateway->client_key ? '••••••••••••••••' : 'Masukkan Client Key' }}"
                                       autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary mb-0" onclick="togglePassword('client_key_{{ $gateway->id }}')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        @if($gateway->name === 'xendit')
                        {{-- Webhook Secret (Xendit only) --}}
                        <div class="mb-3">
                            <label class="form-label text-sm font-weight-bold">
                                Webhook Verification Token
                                @if($gateway->webhook_secret)
                                    <span class="badge bg-gradient-success ms-1">✓</span>
                                @else
                                    <span class="badge bg-gradient-secondary ms-1">-</span>
                                @endif
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" 
                                       name="webhook_secret" 
                                       class="form-control" 
                                       id="webhook_secret_{{ $gateway->id }}"
                                       placeholder="{{ $gateway->webhook_secret ? '••••••••••••••••' : 'Masukkan Webhook Token' }}"
                                       autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary mb-0" onclick="togglePassword('webhook_secret_{{ $gateway->id }}')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        @endif

                        {{-- Enable Toggle --}}
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_enabled" value="1" 
                                   id="is_enabled_{{ $gateway->id }}" {{ $gateway->is_enabled ? 'checked' : '' }}>
                            <label class="form-check-label text-sm" for="is_enabled_{{ $gateway->id }}">
                                Enable Gateway
                            </label>
                        </div>

                        {{-- Buttons --}}
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-sm btn-primary mb-0">
                                <i class="fas fa-save me-1"></i> Simpan
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary mb-0" onclick="testConnection({{ $gateway->id }})">
                                <i class="fas fa-plug me-1"></i> Test
                            </button>
                        </div>
                    </form>

                    <hr class="horizontal dark my-3">

                    {{-- Activation Section --}}
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <p class="text-xs text-secondary mb-0">
                                @if($gateway->last_verified_at)
                                    Last verified: {{ $gateway->last_verified_at->diffForHumans() }}
                                @else
                                    Belum pernah diverifikasi
                                @endif
                            </p>
                        </div>
                        <div>
                            @if($gateway->is_active)
                                <form action="{{ route('owner.payment-gateway.deactivate', $gateway) }}" method="POST" class="d-inline"
                                      id="deactivate-form-{{ $gateway->id }}"
                                      onsubmit="return false;">
                                    @csrf
                                    @method('PUT')
                                    <button type="button" class="btn btn-sm btn-outline-warning mb-0" onclick="confirmDeactivate({{ $gateway->id }}, '{{ $gateway->name }}')">
                                        <i class="fas fa-power-off me-1"></i> Deactivate
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('owner.payment-gateway.set-active', $gateway) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-success mb-0"
                                            {{ !$gateway->is_enabled || !$gateway->isConfigured() ? 'disabled' : '' }}>
                                        <i class="fas fa-check me-1"></i> Set Active
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        {{-- Empty State --}}
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="icon icon-shape icon-lg bg-gradient-secondary shadow text-center border-radius-lg mb-3">
                        <i class="fas fa-credit-card text-white opacity-10"></i>
                    </div>
                    <h6 class="text-secondary">Belum Ada Payment Gateway</h6>
                    <p class="text-xs text-secondary mb-0">Silakan jalankan seeder untuk membuat gateway default.</p>
                </div>
            </div>
        </div>
    @endforelse
</div>

{{-- Info Cards --}}
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header p-3">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informasi Penting</h6>
            </div>
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <h6 class="text-sm font-weight-bold">Cara Mendapatkan API Keys</h6>
                        <ul class="text-xs text-secondary mb-0">
                            <li><strong>Midtrans:</strong> Dashboard Midtrans → Settings → Access Keys</li>
                            <li><strong>Xendit:</strong> Dashboard Xendit → Settings → API Keys</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-sm font-weight-bold">Keamanan</h6>
                        <ul class="text-xs text-secondary mb-0">
                            <li>API keys disimpan terenkripsi di database</li>
                            <li>Hanya 1 gateway yang dapat aktif dalam satu waktu</li>
                            <li>Semua perubahan tercatat di audit log</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Test Connection Modal --}}
<div class="modal fade" id="testResultModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Test Connection Result</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="testResultBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-sm mt-2">Testing connection...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary mb-0" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
    } else {
        input.type = 'password';
    }
}

function testConnection(gatewayId) {
    const modal = new bootstrap.Modal(document.getElementById('testResultModal'));
    modal.show();
    
    document.getElementById('testResultBody').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-sm mt-2">Testing connection...</p>
        </div>
    `;
    
    fetch(`{{ url('owner/payment-gateway') }}/${gatewayId}/test`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('testResultBody').innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                    <h5 class="text-success">Connection Successful!</h5>
                    <p class="text-sm text-secondary">${data.message}</p>
                    ${data.environment ? `<span class="badge bg-gradient-info">${data.environment}</span>` : ''}
                </div>
            `;
        } else {
            document.getElementById('testResultBody').innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-times-circle text-danger fa-4x mb-3"></i>
                    <h5 class="text-danger">Connection Failed</h5>
                    <p class="text-sm text-secondary">${data.message}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('testResultBody').innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-exclamation-triangle text-warning fa-4x mb-3"></i>
                <h5 class="text-warning">Error</h5>
                <p class="text-muted">${error.message}</p>
            </div>
        `;
    });
}

function confirmDeactivate(gatewayId, gatewayName) {
    OwnerPopup.confirmDanger({
        title: 'Nonaktifkan Gateway?',
        text: `
            <p class="mb-2">Anda akan menonaktifkan:</p>
            <p class="fw-bold mb-3">${gatewayName}</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                    <strong>Dampak:</strong> User tidak akan bisa top-up menggunakan gateway ini.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-power-off me-1"></i> Ya, Nonaktifkan',
        onConfirm: () => {
            const form = document.getElementById('deactivate-form-' + gatewayId);
            form.onsubmit = null;
            form.submit();
        }
    });
}
</script>
@endpush
