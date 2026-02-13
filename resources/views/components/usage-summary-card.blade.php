{{-- 
    Usage Summary Card Component
    
    Menampilkan ringkasan usage: saldo, kuota harian, kuota bulanan
    
    Usage:
    @include('components.usage-summary-card', ['saldo' => $saldo, 'limitData' => $limitData])
--}}

@php
    $daily = $limitData['daily'] ?? [];
    $monthly = $limitData['monthly'] ?? [];
    $plan = $limitData['plan'] ?? [];
@endphp

<div class="card mb-4">
    <div class="card-header pb-0">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Ringkasan Penggunaan</h6>
            <span class="badge bg-gradient-primary">{{ $plan['display_name'] ?? 'Free' }}</span>
        </div>
    </div>
    <div class="card-body pt-3">
        <div class="row">
            {{-- Saldo --}}
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="border-end pe-3">
                    <p class="text-xs text-secondary mb-1">💰 Saldo Tersedia</p>
                    <h5 class="font-weight-bold mb-0 {{ ($saldo ?? 0) < 50000 ? 'text-danger' : 'text-success' }}">
                        @if(isset($saldo) && $saldo !== null)
                            Rp {{ number_format($saldo, 0, ',', '.') }}
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </h5>
                    @if(isset($saldo) && $saldo < 50000 && $saldo !== null)
                        <p class="text-xs text-danger mb-0 mt-1">
                            <i class="ni ni-fat-delete"></i> Saldo hampir habis
                        </p>
                    @endif
                </div>
            </div>
            
            {{-- Kuota Harian --}}
            <div class="col-md-4 mb-3 mb-md-0">
                <div class="border-end pe-3">
                    <p class="text-xs text-secondary mb-1">📊 Kuota Hari Ini</p>
                    @if($daily['unlimited'] ?? false)
                        <h5 class="font-weight-bold mb-0 text-success">
                            {{ number_format($daily['used'] ?? 0) }} <span class="text-sm fw-normal">/ Unlimited</span>
                        </h5>
                    @else
                        <h5 class="font-weight-bold mb-0 {{ ($daily['warning_level'] ?? 'none') !== 'none' ? 'text-warning' : '' }}">
                            {{ number_format($daily['used'] ?? 0) }} 
                            <span class="text-sm fw-normal text-muted">/ {{ number_format($daily['limit'] ?? 0) }}</span>
                        </h5>
                        {{-- Progress --}}
                        <div class="progress mt-2" style="height: 4px;">
                            <div class="progress-bar bg-gradient-{{ ($daily['warning_level'] ?? 'none') === 'danger' ? 'danger' : (($daily['warning_level'] ?? 'none') === 'warning' ? 'warning' : 'primary') }}" 
                                 style="width: {{ min($daily['percentage'] ?? 0, 100) }}%">
                            </div>
                        </div>
                        <p class="text-xs text-muted mb-0 mt-1">
                            Tersisa {{ number_format($daily['remaining'] ?? 0) }} pesan
                        </p>
                    @endif
                </div>
            </div>
            
            {{-- Kuota Bulanan --}}
            <div class="col-md-4">
                <div>
                    <p class="text-xs text-secondary mb-1">📅 Kuota Bulan Ini</p>
                    @if($monthly['unlimited'] ?? false)
                        <h5 class="font-weight-bold mb-0 text-success">
                            {{ number_format($monthly['used'] ?? 0) }} <span class="text-sm fw-normal">/ Unlimited</span>
                        </h5>
                    @else
                        <h5 class="font-weight-bold mb-0 {{ ($monthly['warning_level'] ?? 'none') !== 'none' ? 'text-warning' : '' }}">
                            {{ number_format($monthly['used'] ?? 0) }} 
                            <span class="text-sm fw-normal text-muted">/ {{ number_format($monthly['limit'] ?? 0) }}</span>
                        </h5>
                        {{-- Progress --}}
                        <div class="progress mt-2" style="height: 4px;">
                            <div class="progress-bar bg-gradient-{{ ($monthly['warning_level'] ?? 'none') === 'danger' ? 'danger' : (($monthly['warning_level'] ?? 'none') === 'warning' ? 'warning' : 'success') }}" 
                                 style="width: {{ min($monthly['percentage'] ?? 0, 100) }}%">
                            </div>
                        </div>
                        <p class="text-xs text-muted mb-0 mt-1">
                            Tersisa {{ number_format($monthly['remaining'] ?? 0) }} pesan
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
