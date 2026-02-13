{{-- 
  Upgrade Nudge Component
  Non-intrusive nudge untuk upgrade saat kuota menipis
  
  Usage: @include('components.upgrade-nudge', ['quotaInfo' => $quotaInfo])
  
  RULES:
  - Tampil jika kuota < 30% (Phase 2: lebih awal untuk conversion)
  - Non-blocking (tidak mengganggu flow)
  - Tidak auto-redirect
  - Pesan halus, tidak agresif
--}}

@php
    $quotaInfo = $quotaInfo ?? [];
    $showNudge = false;
    $urgencyLevel = 'info'; // info, warning, danger
    $message = '';
    $subMessage = '';
    
    if (isset($quotaInfo['monthly']['percentage'])) {
        $usagePercent = $quotaInfo['monthly']['percentage'];
        $remaining = $quotaInfo['monthly']['remaining'] ?? 0;
        $planName = $quotaInfo['plan_name'] ?? 'Starter';
        
        if ($usagePercent >= 100) {
            // Kuota habis
            $showNudge = true;
            $urgencyLevel = 'danger';
            $message = 'Kuota paket ' . $planName . ' telah habis.';
            $subMessage = 'Upgrade untuk melanjutkan pengiriman pesan.';
        } elseif ($usagePercent >= 80) {
            // Kuota < 20% tersisa
            $showNudge = true;
            $urgencyLevel = 'warning';
            $message = 'Kuota Anda tinggal ' . number_format($remaining, 0, ',', '.') . ' pesan.';
            $subMessage = 'Upgrade ke Growth untuk kirim lebih banyak pesan.';
        } elseif ($usagePercent >= 70) {
            // Kuota < 30% tersisa - SOFT NUDGE (Phase 2)
            $showNudge = true;
            $urgencyLevel = 'info';
            $message = 'Bisnis Anda mulai aktif! 👏';
            $subMessage = 'Paket Growth cocok untuk volume pengiriman rutin.';
        }
    }
@endphp

@if($showNudge)
<div class="alert alert-{{ $urgencyLevel }} d-flex align-items-center justify-content-between mb-4" role="alert" style="border-radius: 12px; border-left: 4px solid;">
    <div class="d-flex align-items-center">
        @if($urgencyLevel === 'danger')
            <i class="fas fa-exclamation-circle me-3" style="font-size: 1.25rem;"></i>
        @elseif($urgencyLevel === 'warning')
            <i class="fas fa-exclamation-triangle me-3" style="font-size: 1.25rem;"></i>
        @else
            <i class="fas fa-lightbulb me-3" style="font-size: 1.25rem;"></i>
        @endif
        <div>
            <span class="text-sm font-weight-bold d-block">{{ $message }}</span>
            @if($subMessage)
                <span class="text-xs opacity-8">{{ $subMessage }}</span>
            @endif
        </div>
    </div>
    <a href="{{ route('subscription.index') }}" class="btn btn-sm {{ $urgencyLevel === 'danger' ? 'btn-danger' : ($urgencyLevel === 'warning' ? 'btn-warning' : 'btn-outline-info') }} mb-0 ms-3">
        @if($urgencyLevel === 'info')
            <i class="fas fa-arrow-right me-1"></i>Lihat Paket
        @else
            <i class="fas fa-arrow-up me-1"></i>Upgrade
        @endif
    </a>
</div>
@endif
