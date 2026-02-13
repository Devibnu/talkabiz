{{--
    Warmup Status Badge Component for Client Dashboard
    
    Menampilkan status warmup dalam format yang mudah dipahami client.
    ❌ TIDAK menampilkan angka detail limit
    ❌ TIDAK menampilkan info teknis
    ✅ Hanya tampilkan status dan pesan edukatif
    
    Usage:
    @include('components.warmup-status-badge', ['connection' => $whatsappConnection])
    
    Props:
    - connection: WhatsappConnection instance
    - warmupStatus: Optional pre-loaded status array from WarmupStateMachineService
    - compact: boolean, show compact version (default: false)
--}}

@php
    // Use pre-loaded status or generate default
    $status = $warmupStatus ?? null;
    
    if (!$status) {
        // Get status from warmup state machine service if not provided
        $warmupService = app(\App\Services\WarmupStateMachineService::class);
        $status = $warmupService->getClientStatus($connection);
    }
    
    $compact = $compact ?? false;
    
    // State configurations for UI
    $stateConfigs = [
        'NEW' => [
            'icon' => 'fa-seedling',
            'color' => 'info',
            'label' => 'Persiapan',
            'message' => 'Nomor sedang dipersiapkan untuk pengiriman optimal.',
            'tip' => 'Pengiriman dibatasi sementara untuk keamanan.',
        ],
        'WARMING' => [
            'icon' => 'fa-fire',
            'color' => 'primary',
            'label' => 'Pemanasan',
            'message' => 'Nomor dalam tahap pemanasan untuk performa terbaik.',
            'tip' => 'Kapasitas pengiriman akan meningkat secara bertahap.',
        ],
        'STABLE' => [
            'icon' => 'fa-check-circle',
            'color' => 'success',
            'label' => 'Normal',
            'message' => 'Nomor dalam kondisi prima.',
            'tip' => 'Semua fitur pengiriman tersedia.',
        ],
        'COOLDOWN' => [
            'icon' => 'fa-clock',
            'color' => 'warning',
            'label' => 'Istirahat',
            'message' => 'Nomor sedang istirahat untuk menjaga keamanan.',
            'tip' => 'Anda tetap bisa membalas chat yang masuk.',
        ],
        'SUSPENDED' => [
            'icon' => 'fa-pause-circle',
            'color' => 'danger',
            'label' => 'Terhenti',
            'message' => 'Pengiriman dihentikan sementara.',
            'tip' => 'Hubungi support untuk informasi lebih lanjut.',
        ],
    ];
    
    $state = $status['state'] ?? 'STABLE';
    $config = $stateConfigs[$state] ?? $stateConfigs['STABLE'];
    
    // Override with service-provided values if available
    $label = $status['label'] ?? $config['label'];
    $message = $status['message'] ?? $config['message'];
    $icon = $status['icon'] ?? $config['icon'];
    $color = $status['color'] ?? $config['color'];
    $canBlast = $status['can_blast'] ?? true;
    $canCampaign = $status['can_campaign'] ?? true;
    $isCooldown = $status['is_cooldown'] ?? false;
    $cooldownRemaining = $status['cooldown_remaining'] ?? 0;
@endphp

@if($compact)
    {{-- Compact Version - Just Badge --}}
    <span class="warmup-badge-compact badge bg-gradient-{{ $color }}" 
          title="{{ $message }}"
          data-bs-toggle="tooltip">
        <i class="fas {{ $icon }} me-1"></i>
        {{ $label }}
    </span>
@else
    {{-- Full Version with Card --}}
    <div class="warmup-status-card {{ $state === 'STABLE' && !$status['active'] ? 'd-none' : '' }}">
        <div class="d-flex align-items-start">
            {{-- Icon Circle --}}
            <div class="warmup-icon bg-gradient-{{ $color }} me-3">
                <i class="fas {{ $icon }}"></i>
            </div>
            
            {{-- Content --}}
            <div class="flex-grow-1">
                {{-- Header --}}
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <h6 class="mb-0 font-weight-bold text-sm">
                        Status Pengiriman: 
                        <span class="text-{{ $color }}">{{ $label }}</span>
                    </h6>
                </div>
                
                {{-- Message --}}
                <p class="text-sm text-muted mb-2">
                    {{ $message }}
                </p>
                
                {{-- Cooldown Timer (if applicable) --}}
                @if($isCooldown && $cooldownRemaining > 0)
                    <div class="alert alert-{{ $color }} py-2 px-3 mb-2">
                        <small>
                            <i class="fas fa-clock me-1"></i>
                            Waktu istirahat tersisa: <strong>{{ $cooldownRemaining }} jam</strong>
                        </small>
                    </div>
                @endif
                
                {{-- Capability Indicators --}}
                <div class="d-flex gap-3 text-xs">
                    <span class="{{ $canBlast ? 'text-success' : 'text-muted' }}">
                        <i class="fas {{ $canBlast ? 'fa-check-circle' : 'fa-times-circle' }} me-1"></i>
                        Blast
                    </span>
                    <span class="{{ $canCampaign ? 'text-success' : 'text-muted' }}">
                        <i class="fas {{ $canCampaign ? 'fa-check-circle' : 'fa-times-circle' }} me-1"></i>
                        Campaign
                    </span>
                    <span class="text-success">
                        <i class="fas fa-check-circle me-1"></i>
                        Inbox
                    </span>
                </div>
                
                {{-- Tip --}}
                @if($state !== 'STABLE')
                    <p class="text-xs text-muted mt-2 mb-0">
                        <i class="fas fa-lightbulb me-1 text-warning"></i>
                        {{ $config['tip'] }}
                    </p>
                @endif
            </div>
        </div>
    </div>
@endif

<style>
.warmup-status-card {
    background: linear-gradient(to right, rgba(var(--bs-{{ $color }}-rgb), 0.05), transparent);
    border-left: 3px solid var(--bs-{{ $color }});
    padding: 1rem;
    border-radius: 0 0.5rem 0.5rem 0;
    margin-bottom: 1rem;
}

.warmup-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.warmup-icon i {
    color: #fff;
    font-size: 1rem;
}

.warmup-badge-compact {
    cursor: help;
    font-size: 0.75rem;
}
</style>
