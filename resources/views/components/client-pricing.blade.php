{{--
    Client Pricing Display Component
    
    SIMPLE VIEW untuk client - TANPA detail teknis!
    
    CLIENT MELIHAT:
    ✓ Sisa saldo
    ✓ Estimasi pesan
    ✓ Harga per pesan (flat)
    ✓ Status quota
    
    CLIENT TIDAK BOLEH LIHAT:
    ✗ Meta cost
    ✗ Margin
    ✗ Risk adjustment
    ✗ Detail perhitungan
    
    Usage:
    <x-client-pricing :klien-id="$klienId" :balance="$balance" />
    
    Variants:
    - full: Card lengkap dengan semua info
    - compact: Badge kecil saja
    - balance-only: Hanya saldo
--}}

@props([
    'klienId' => null,
    'balance' => 0,
    'variant' => 'full', // full, compact, balance-only
])

@php
    // Get pricing info
    $pricingService = app(\App\Services\EnhancedPricingService::class);
    $pricing = $pricingService->getClientPricing($klienId ?? auth()->user()->klien_id ?? 0, $balance);
    
    $isLowBalance = $pricing['status'] === 'low_balance';
    $estimatedMessages = $pricing['estimate']['messages'];
    $priceDisplay = $pricing['display']['price'];
@endphp

@if($variant === 'full')
    {{-- Full Card View --}}
    <div class="card h-100">
        <div class="card-header pb-0">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">💰 Saldo & Quota</h6>
                @if($isLowBalance)
                    <span class="badge bg-danger">Saldo Rendah</span>
                @else
                    <span class="badge bg-success">Aktif</span>
                @endif
            </div>
        </div>
        <div class="card-body">
            {{-- Balance Section --}}
            <div class="text-center mb-4">
                <p class="text-sm text-muted mb-1">Saldo Anda</p>
                <h3 class="mb-0 {{ $isLowBalance ? 'text-danger' : 'text-success' }}">
                    {{ $pricing['balance']['formatted'] }}
                </h3>
            </div>
            
            {{-- Estimate Section --}}
            <div class="bg-light rounded-3 p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-envelope text-primary me-2"></i>
                        <span class="text-sm">Estimasi Pesan</span>
                    </div>
                    <div class="text-end">
                        <strong class="text-lg">~{{ number_format($estimatedMessages, 0, ',', '.') }}</strong>
                        <span class="text-sm text-muted">pesan</span>
                    </div>
                </div>
            </div>
            
            {{-- Price Info --}}
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <span class="text-sm text-muted">{{ $pricing['display']['label'] }}</span>
                <strong>{{ $priceDisplay }}</strong>
            </div>
            
            {{-- Status Indicator --}}
            <div class="d-flex justify-content-between align-items-center py-2">
                <span class="text-sm text-muted">Status</span>
                @if($isLowBalance)
                    <span class="text-danger">
                        <i class="fas fa-exclamation-circle me-1"></i> Perlu Top Up
                    </span>
                @else
                    <span class="text-success">
                        <i class="fas fa-check-circle me-1"></i> Siap Kirim
                    </span>
                @endif
            </div>
            
            {{-- Low Balance Warning --}}
            @if($isLowBalance)
                <div class="alert alert-warning mt-3 mb-0 d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <small>Saldo tidak cukup untuk mengirim pesan. Silakan top up.</small>
                </div>
            @endif
            
            {{-- Top Up Button --}}
            <div class="mt-3">
                <a href="{{ route('billing.topup') ?? '#' }}" class="btn btn-primary w-100">
                    <i class="fas fa-plus me-1"></i> Top Up Saldo
                </a>
            </div>
        </div>
    </div>

@elseif($variant === 'compact')
    {{-- Compact Badge View --}}
    <div class="d-flex align-items-center bg-{{ $isLowBalance ? 'danger' : 'success' }} bg-opacity-10 rounded-3 p-2 px-3">
        <div class="me-3">
            <i class="fas fa-wallet fs-4 text-{{ $isLowBalance ? 'danger' : 'success' }}"></i>
        </div>
        <div>
            <div class="d-flex align-items-baseline gap-2">
                <span class="fw-bold">{{ $pricing['balance']['formatted'] }}</span>
                <small class="text-muted">•</small>
                <span class="text-sm">~{{ number_format($estimatedMessages, 0, ',', '.') }} pesan</span>
            </div>
            <div class="text-sm text-muted">
                {{ $priceDisplay }}/pesan
            </div>
        </div>
        @if($isLowBalance)
            <a href="{{ route('billing.topup') ?? '#' }}" class="btn btn-sm btn-danger ms-auto">
                <i class="fas fa-plus"></i> Top Up
            </a>
        @endif
    </div>

@elseif($variant === 'balance-only')
    {{-- Balance Only View --}}
    <div class="d-flex align-items-center">
        <i class="fas fa-wallet text-{{ $isLowBalance ? 'danger' : 'success' }} me-2"></i>
        <span class="{{ $isLowBalance ? 'text-danger' : 'text-success' }} fw-bold">
            {{ $pricing['balance']['formatted'] }}
        </span>
    </div>

@elseif($variant === 'estimate-card')
    {{-- Estimate Card for Campaign --}}
    <div class="card bg-gradient-light">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <div class="col-8">
                    <h6 class="mb-1">Saldo Tersedia</h6>
                    <h4 class="mb-0 {{ $isLowBalance ? 'text-danger' : 'text-dark' }}">
                        {{ $pricing['balance']['formatted'] }}
                    </h4>
                    <small class="text-muted">
                        {{ $pricing['estimate']['label'] }}
                    </small>
                </div>
                <div class="col-4 text-end">
                    <div class="icon icon-shape bg-gradient-{{ $isLowBalance ? 'danger' : 'success' }} shadow text-center rounded-circle">
                        <i class="fas fa-wallet text-lg opacity-10"></i>
                    </div>
                </div>
            </div>
            @if($isLowBalance)
                <a href="{{ route('billing.topup') ?? '#' }}" class="btn btn-sm btn-danger mt-2 w-100">
                    Top Up Sekarang
                </a>
            @endif
        </div>
    </div>
@endif
