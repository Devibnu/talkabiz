{{--
    WhatsApp Connection Status Badge Component
    
    Usage:
    @include('components.whatsapp-status-badge', ['connection' => $whatsappConnection])
    
    atau dengan Blade Component:
    <x-whatsapp-status-badge :connection="$whatsappConnection" />
--}}

@php
    $status = $connection->status ?? 'disconnected';
    $statusLabel = $connection->status_label ?? 'Belum Terhubung';
    $statusColor = $connection->status_color ?? 'secondary';
    $phoneNumber = $connection->phone_number ?? null;
    $displayName = $connection->display_name ?? null;
    $qualityRating = $connection->quality_rating ?? null;
    $connectedAt = $connection->connected_at ?? null;
    
    // Poll URL - uses sanctum auth
    $pollUrl = route('api.whatsapp.connection.status');
@endphp

<div class="whatsapp-status-container">
    {{-- Status Badge dengan Auto-Polling --}}
    <div id="wa-status-badge" 
         class="d-inline-block"
         data-status="{{ $status }}"
         data-poll-url="{{ $pollUrl }}">
        <span class="badge bg-{{ $statusColor }}">
            @switch($status)
                @case('disconnected')
                    <i class="fas fa-times-circle me-1"></i>
                    @break
                @case('pending')
                    <i class="fas fa-clock me-1"></i>
                    @break
                @case('connected')
                    <i class="fas fa-check-circle me-1"></i>
                    @break
                @case('restricted')
                @case('failed')
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    @break
                @case('suspended')
                    <i class="fas fa-ban me-1"></i>
                    @break
                @default
                    <i class="fas fa-question-circle me-1"></i>
            @endswitch
            {{ $statusLabel }}
        </span>
    </div>

    {{-- Info Tambahan jika Connected --}}
    @if($status === 'connected')
        <div id="wa-connected-info" class="mt-2">
            @if($phoneNumber)
                <div class="text-sm text-muted">
                    <i class="fas fa-phone me-1"></i>
                    {{ preg_replace('/(\+62|62)(\d{3})(\d{4})(\d+)/', '+62 $2-$3-$4', $phoneNumber) }}
                </div>
            @endif
            
            @if($displayName)
                <div class="text-sm text-muted">
                    <i class="fas fa-building me-1"></i>
                    {{ $displayName }}
                </div>
            @endif
            
            @if($qualityRating)
                @php
                    $qualityColor = match($qualityRating) {
                        'GREEN' => 'success',
                        'YELLOW' => 'warning',
                        default => 'danger',
                    };
                @endphp
                <div class="text-sm mt-1">
                    <span class="badge bg-{{ $qualityColor }}">
                        Quality: {{ $qualityRating }}
                    </span>
                </div>
            @endif
            
            @if($connectedAt)
                <div class="text-sm text-muted mt-1">
                    <i class="fas fa-clock me-1"></i>
                    Terhubung sejak {{ $connectedAt->format('d M Y H:i') }}
                </div>
            @endif
        </div>
    @endif

    {{-- Error Reason jika Failed --}}
    @if($status === 'failed' && $connection->error_reason)
        <div class="alert alert-danger mt-2 py-2 px-3 small">
            <i class="fas fa-exclamation-circle me-1"></i>
            {{ $connection->error_reason }}
        </div>
    @endif
</div>

{{-- Auto-polling Script --}}
@push('scripts')
<script src="{{ asset('js/whatsapp-status-poller.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        WhatsAppStatusPoller.init('#wa-status-badge');
    });
</script>
@endpush
