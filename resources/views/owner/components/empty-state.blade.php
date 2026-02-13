{{--
    EMPTY STATE COMPONENT - Consistent across Owner Panel
    Usage: @include('owner.components.empty-state', ['icon' => 'fas fa-inbox', 'title' => 'No Data', 'message' => '...'])
--}}

@props([
    'icon' => 'fas fa-inbox',
    'title' => 'Tidak ada data',
    'message' => 'Belum ada data yang tersedia.',
    'action' => null,
    'actionUrl' => '#',
    'actionIcon' => 'fas fa-plus'
])

<div class="empty-state">
    <div class="icon-wrapper">
        <i class="{{ $icon ?? 'fas fa-inbox' }}"></i>
    </div>
    <h6>{{ $title ?? 'Tidak ada data' }}</h6>
    <p class="text-sm">{{ $message ?? 'Belum ada data yang tersedia.' }}</p>
    @if($action ?? false)
    <a href="{{ $actionUrl ?? '#' }}" class="btn btn-sm btn-primary">
        <i class="{{ $actionIcon ?? 'fas fa-plus' }} me-1"></i>
        {{ $action }}
    </a>
    @endif
</div>
