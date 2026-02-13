@props([
    'type' => 'active',  // active, inactive, popular, self-serve, enterprise, recommended
    'size' => 'sm',      // sm, md, lg
    'text' => null,      // Optional custom text
])

@php
    $badges = [
        'active' => [
            'text' => 'Active',
            'bg' => 'bg-success',
            'icon' => 'fas fa-check-circle',
        ],
        'inactive' => [
            'text' => 'Inactive',
            'bg' => 'bg-secondary',
            'icon' => 'fas fa-times-circle',
        ],
        'popular' => [
            'text' => 'Popular',
            'bg' => 'bg-warning',
            'icon' => 'fas fa-star',
        ],
        'self-serve' => [
            'text' => 'Self-Serve',
            'bg' => 'bg-info',
            'icon' => 'fas fa-shopping-cart',
        ],
        'enterprise' => [
            'text' => 'Enterprise',
            'bg' => 'bg-primary',
            'icon' => 'fas fa-building',
        ],
        'recommended' => [
            'text' => 'Recommended',
            'bg' => 'bg-gradient-primary',
            'icon' => 'fas fa-thumbs-up',
        ],
        'free' => [
            'text' => 'Free',
            'bg' => 'bg-success',
            'icon' => 'fas fa-gift',
        ],
        'unlimited' => [
            'text' => 'Unlimited',
            'bg' => 'bg-dark',
            'icon' => 'fas fa-infinity',
        ],
    ];

    $badge = $badges[$type] ?? $badges['active'];
    $displayText = $text ?? $badge['text'];

    $sizeClasses = [
        'sm' => 'badge-sm px-2 py-1 text-xs',
        'md' => 'px-2 py-1 text-sm',
        'lg' => 'px-3 py-2 text-base',
    ];
    $sizeClass = $sizeClasses[$size] ?? $sizeClasses['sm'];
@endphp

<span {{ $attributes->merge(['class' => "badge {$badge['bg']} {$sizeClass} text-white rounded-pill d-inline-flex align-items-center gap-1"]) }}>
    <i class="{{ $badge['icon'] }}" style="font-size: 0.7em;"></i>
    {{ $displayText }}
</span>
