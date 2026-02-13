{{-- 
    Logout Button Component
    Usage: <x-logout-button /> or <x-logout-button class="btn btn-danger" icon="ni ni-button-power" text="Keluar" />
--}}
@props([
    'class' => 'btn-logout',
    'icon' => 'ni ni-button-power',
    'text' => 'Logout',
    'formId' => 'logout-form-' . uniqid()
])

<a href="#" 
   {{ $attributes->merge(['class' => $class]) }}
   onclick="event.preventDefault(); document.getElementById('{{ $formId }}').submit();">
    @if($icon)
        <i class="{{ $icon }}"></i>
    @endif
    <span>{{ $text }}</span>
</a>

<form id="{{ $formId }}" action="{{ route('logout') }}" method="POST" class="d-none">
    @csrf
</form>
