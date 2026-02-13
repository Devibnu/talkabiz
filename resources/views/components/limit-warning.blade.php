{{-- 
    Limit Warning Component
    
    Menampilkan warning jika limit mendekati batas (80% atau 95%)
    
    Usage:
    @include('components.limit-warning', ['limitData' => $limitData])
    
    $limitData should come from LimitService::getUsageSummary()
--}}

@php
    $daily = $limitData['daily'] ?? [];
    $monthly = $limitData['monthly'] ?? [];
    $campaigns = $limitData['campaigns'] ?? [];
    $plan = $limitData['plan'] ?? [];
@endphp

{{-- Daily Limit Warning --}}
@if(isset($daily['warning_level']) && $daily['warning_level'] !== 'none' && !($daily['unlimited'] ?? false))
    <div class="alert alert-{{ $daily['warning_level'] === 'danger' ? 'danger' : 'warning' }} mb-3">
        <div class="d-flex align-items-center">
            <div class="icon icon-shape bg-gradient-{{ $daily['warning_level'] === 'danger' ? 'danger' : 'warning' }} shadow text-center border-radius-md me-3" style="width:38px;height:38px;min-width:38px;">
                <i class="ni ni-bell-55 text-white" style="font-size:0.9rem;line-height:38px;"></i>
            </div>
            <div class="flex-grow-1">
                <h6 class="alert-heading mb-1">
                    @if($daily['warning_level'] === 'danger')
                        Kuota Harian Hampir Habis 📊
                    @else
                        Info Kuota Harian
                    @endif
                </h6>
                <p class="mb-0 text-sm">
                    Anda sudah menggunakan <strong>{{ number_format($daily['used'] ?? 0) }}</strong> dari 
                    <strong>{{ number_format($daily['limit'] ?? 0) }}</strong> pesan hari ini.
                </p>
                @if($daily['warning_level'] === 'danger')
                    <p class="mb-0 text-xs text-muted mt-1">
                        <i class="fas fa-lightbulb me-1"></i>
                        Tenang, kuota akan reset otomatis besok pukul 00:00. Anda masih punya {{ number_format($daily['remaining'] ?? 0) }} pesan.
                    </p>
                @endif
            </div>
            <div class="text-end">
                <span class="badge bg-gradient-{{ $daily['warning_level'] === 'danger' ? 'danger' : 'warning' }}">
                    {{ $daily['percentage'] ?? 0 }}%
                </span>
            </div>
        </div>
        {{-- Progress Bar --}}
        <div class="progress mt-2" style="height: 6px;">
            <div class="progress-bar bg-gradient-{{ $daily['warning_level'] === 'danger' ? 'danger' : 'warning' }}" 
                 role="progressbar" 
                 style="width: {{ min($daily['percentage'] ?? 0, 100) }}%"
                 aria-valuenow="{{ $daily['percentage'] ?? 0 }}" 
                 aria-valuemin="0" 
                 aria-valuemax="100">
            </div>
        </div>
    </div>
@endif

{{-- Monthly Limit Warning --}}
@if(isset($monthly['warning_level']) && $monthly['warning_level'] !== 'none' && !($monthly['unlimited'] ?? false))
    <div class="alert alert-{{ $monthly['warning_level'] === 'danger' ? 'danger' : 'warning' }} mb-3">
        <div class="d-flex align-items-center">
            <div class="icon icon-shape bg-gradient-{{ $monthly['warning_level'] === 'danger' ? 'danger' : 'warning' }} shadow text-center border-radius-md me-3" style="width:38px;height:38px;min-width:38px;">
                <i class="ni ni-calendar-grid-58 text-white" style="font-size:0.9rem;line-height:38px;"></i>
            </div>
            <div class="flex-grow-1">
                <h6 class="alert-heading mb-1">
                    @if($monthly['warning_level'] === 'danger')
                        Kuota Bulanan Hampir Habis 📅
                    @else
                        Info Kuota Bulanan
                    @endif
                </h6>
                <p class="mb-0 text-sm">
                    Anda sudah menggunakan <strong>{{ number_format($monthly['used'] ?? 0) }}</strong> dari 
                    <strong>{{ number_format($monthly['limit'] ?? 0) }}</strong> pesan bulan ini.
                </p>
                @if($monthly['warning_level'] === 'danger')
                    <p class="mb-0 text-xs text-muted mt-1">
                        <i class="fas fa-lightbulb me-1"></i>
                        Ingin kuota lebih banyak? <a href="{{ route('subscription.index') }}" class="text-primary fw-bold">Lihat paket upgrade</a> untuk kuota yang lebih besar.
                    </p>
                @endif
            </div>
            <div class="text-end">
                <span class="badge bg-gradient-{{ $monthly['warning_level'] === 'danger' ? 'danger' : 'warning' }}">
                    {{ $monthly['percentage'] ?? 0 }}%
                </span>
            </div>
        </div>
        {{-- Progress Bar --}}
        <div class="progress mt-2" style="height: 6px;">
            <div class="progress-bar bg-gradient-{{ $monthly['warning_level'] === 'danger' ? 'danger' : 'warning' }}" 
                 role="progressbar" 
                 style="width: {{ min($monthly['percentage'] ?? 0, 100) }}%"
                 aria-valuenow="{{ $monthly['percentage'] ?? 0 }}" 
                 aria-valuemin="0" 
                 aria-valuemax="100">
            </div>
        </div>
    </div>
@endif
