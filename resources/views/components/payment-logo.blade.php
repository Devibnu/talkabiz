{{--
    Payment Gateway Logo Component
    
    Usage:
    <x-payment-logo name="midtrans" />
    <x-payment-logo name="xendit" size="lg" />
    <x-payment-logo name="midtrans" size="sm" theme="dark" />
    
    Props:
    - name (required): midtrans, xendit, dana, ovo, gopay, etc.
    - size (optional): sm, md (default), lg
    - theme (optional): light (default), dark
--}}

@props([
    'name',
    'size' => 'md',
    'theme' => 'light',
])

@php
    // Size mapping (width x height in pixels)
    $sizes = [
        'sm' => ['w' => 80, 'h' => 24],
        'md' => ['w' => 120, 'h' => 36],
        'lg' => ['w' => 160, 'h' => 48],
    ];
    
    // Logo registry - add new payment gateways here
    $logos = [
        'midtrans' => [
            'svg' => 'assets/payment/midtrans.svg',
            'png' => 'assets/payment/midtrans@2x.png',
            'alt' => 'Midtrans',
        ],
        'xendit' => [
            'svg' => 'assets/payment/xendit.svg',
            'png' => 'assets/payment/xendit@2x.png',
            'alt' => 'Xendit',
        ],
        'dana' => [
            'svg' => 'assets/payment/dana.svg',
            'png' => 'assets/payment/dana@2x.png',
            'alt' => 'DANA',
        ],
        'ovo' => [
            'svg' => 'assets/payment/ovo.svg',
            'png' => 'assets/payment/ovo@2x.png',
            'alt' => 'OVO',
        ],
        'gopay' => [
            'svg' => 'assets/payment/gopay.svg',
            'png' => 'assets/payment/gopay@2x.png',
            'alt' => 'GoPay',
        ],
        'qris' => [
            'svg' => 'assets/payment/qris.svg',
            'png' => 'assets/payment/qris@2x.png',
            'alt' => 'QRIS',
        ],
    ];
    
    $sizeConfig = $sizes[$size] ?? $sizes['md'];
    $logo = $logos[strtolower($name)] ?? null;
    
    // Determine best source (prefer SVG)
    $logoSrc = null;
    $logoAlt = $name;
    
    if ($logo) {
        $logoAlt = $logo['alt'];
        if (file_exists(public_path($logo['svg']))) {
            $logoSrc = asset($logo['svg']);
        } elseif (file_exists(public_path($logo['png']))) {
            $logoSrc = asset($logo['png']);
        }
    }
    
    // Theme-based background
    $bgClass = $theme === 'dark' ? 'bg-dark' : 'bg-white';
    $textClass = $theme === 'dark' ? 'text-white' : 'text-dark';
@endphp

<div {{ $attributes->merge(['class' => 'payment-logo-container']) }}
     style="
        width: {{ $sizeConfig['w'] }}px;
        height: {{ $sizeConfig['h'] }}px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        padding: 4px 8px;
        overflow: hidden;
        flex-shrink: 0;
     "
     class="{{ $bgClass }}"
>
    @if($logoSrc)
        <img src="{{ $logoSrc }}" 
             alt="{{ $logoAlt }}"
             loading="lazy"
             style="
                width: 100%;
                height: 100%;
                object-fit: contain;
                object-position: center;
                max-width: {{ $sizeConfig['w'] - 16 }}px;
                max-height: {{ $sizeConfig['h'] - 8 }}px;
             "
        >
    @else
        {{-- Fallback: Text-based logo --}}
        <span class="fw-bold {{ $textClass }}" style="font-size: {{ $size === 'sm' ? '10' : ($size === 'lg' ? '16' : '12') }}px;">
            {{ strtoupper($name) }}
        </span>
    @endif
</div>
