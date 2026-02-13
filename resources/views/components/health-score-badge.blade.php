{{--
    Health Score Badge Component for Client Dashboard
    
    Menampilkan health score dalam format yang mudah dipahami client.
    Tidak menampilkan detail teknis - hanya grade dan pesan sederhana.
    
    Usage:
    @include('components.health-score-badge', ['connection' => $whatsappConnection])
    
    atau dengan Blade Component:
    <x-health-score-badge :connection="$whatsappConnection" />
    
    Props:
    - connection: WhatsappConnection instance with healthScore relationship
    - compact: boolean, show compact version (default: false)
--}}

@php
    $healthScore = $connection->healthScore ?? null;
    $score = $healthScore?->score ?? null;
    $status = $healthScore?->status ?? 'unknown';
    
    // Mapping status to user-friendly labels and colors
    $statusConfig = [
        'excellent' => [
            'grade' => 'A',
            'label' => 'Excellent',
            'color' => 'success',
            'icon' => 'fa-check-circle',
            'message' => 'Nomor WhatsApp Anda dalam kondisi sangat baik.',
            'tip' => 'Terus pertahankan pola pengiriman yang baik.',
        ],
        'good' => [
            'grade' => 'B',
            'label' => 'Baik',
            'color' => 'info',
            'icon' => 'fa-thumbs-up',
            'message' => 'Nomor WhatsApp Anda dalam kondisi baik.',
            'tip' => 'Hindari mengirim pesan berulang terlalu cepat.',
        ],
        'warning' => [
            'grade' => 'C',
            'label' => 'Perlu Perhatian',
            'color' => 'warning',
            'icon' => 'fa-exclamation-triangle',
            'message' => 'Nomor WhatsApp Anda perlu perhatian.',
            'tip' => 'Kurangi frekuensi pengiriman dan hindari konten spam.',
        ],
        'critical' => [
            'grade' => 'D',
            'label' => 'Kritis',
            'color' => 'danger',
            'icon' => 'fa-exclamation-circle',
            'message' => 'Nomor WhatsApp Anda dalam kondisi kritis.',
            'tip' => 'Hubungi support untuk bantuan lebih lanjut.',
        ],
        'unknown' => [
            'grade' => '?',
            'label' => 'Belum Dihitung',
            'color' => 'secondary',
            'icon' => 'fa-question-circle',
            'message' => 'Health score sedang dihitung.',
            'tip' => 'Silakan tunggu beberapa saat.',
        ],
    ];
    
    $config = $statusConfig[$status] ?? $statusConfig['unknown'];
    $compact = $compact ?? false;
@endphp

@if($compact)
    {{-- Compact Version - Just Badge --}}
    <span class="badge bg-{{ $config['color'] }}" title="{{ $config['message'] }}">
        <i class="fas {{ $config['icon'] }} me-1"></i>
        {{ $config['grade'] }}
    </span>
@else
    {{-- Full Version with Card --}}
    <div class="health-score-badge-card" id="health-score-card-{{ $connection->id }}">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center">
                {{-- Grade Circle --}}
                <div class="grade-circle bg-gradient-{{ $config['color'] }} me-3">
                    <span class="grade-letter">{{ $config['grade'] }}</span>
                </div>
                
                {{-- Status Info --}}
                <div>
                    <h6 class="mb-0 font-weight-bold">{{ $config['label'] }}</h6>
                    <p class="text-xs text-muted mb-0">Health Score</p>
                </div>
            </div>
            
            {{-- Score Number (if available) --}}
            @if($score !== null)
                <div class="text-end">
                    <span class="text-lg font-weight-bolder text-{{ $config['color'] }}">
                        {{ number_format($score, 0) }}
                    </span>
                    <span class="text-xs text-muted">/ 100</span>
                </div>
            @endif
        </div>
        
        {{-- Progress Bar --}}
        @if($score !== null)
            <div class="progress mb-2" style="height: 6px;">
                <div class="progress-bar bg-{{ $config['color'] }}" 
                     role="progressbar" 
                     style="width: {{ $score }}%"
                     aria-valuenow="{{ $score }}" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                </div>
            </div>
        @endif
        
        {{-- Message --}}
        <p class="text-sm mb-1">
            <i class="fas {{ $config['icon'] }} text-{{ $config['color'] }} me-1"></i>
            {{ $config['message'] }}
        </p>
        
        {{-- Tip --}}
        <p class="text-xs text-muted mb-0">
            <i class="fas fa-lightbulb me-1"></i>
            {{ $config['tip'] }}
        </p>
        
        {{-- Restrictions Warning (if critical) --}}
        @if($status === 'critical')
            <div class="alert alert-danger mt-3 py-2 px-3 mb-0">
                <small>
                    <i class="fas fa-ban me-1"></i>
                    Pengiriman campaign otomatis dibatasi. Hubungi support untuk informasi lebih lanjut.
                </small>
            </div>
        @elseif($status === 'warning')
            <div class="alert alert-warning mt-3 py-2 px-3 mb-0">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    Beberapa fitur mungkin dibatasi. Pertahankan pengiriman yang sehat.
                </small>
            </div>
        @endif
    </div>
@endif

<style>
.health-score-badge-card {
    background: #fff;
    border-radius: 0.75rem;
    padding: 1rem;
    border: 1px solid #e9ecef;
}

.grade-circle {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 3px 6px rgba(0,0,0,0.1);
}

.grade-letter {
    color: #fff;
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}

/* Compact badge animations */
.badge[title] {
    cursor: help;
}
</style>
