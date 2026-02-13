@extends('layouts.landing')

@section('content')

@php
    $sectionsByKey = ($sections ?? collect())->keyBy('key');
    $hero = $sectionsByKey->get('hero');
    $heroItems = $hero?->items ?? collect();
    $heroBadge = $heroItems->firstWhere('key', 'badge');
    $heroDescription = $heroItems->firstWhere('key', 'description');
    $heroPrimaryCta = $heroItems->firstWhere('key', 'cta-primary');
    $heroSecondaryCta = $heroItems->firstWhere('key', 'cta-secondary');
    $heroStats = $heroItems->filter(function ($item) {
        return is_string($item->key) && str_starts_with($item->key, 'stat-');
    });

    $featuresSection = $sectionsByKey->get('features');
    $trustSection = $sectionsByKey->get('trust');
    $faqSection = $sectionsByKey->get('faq');
    $ctaSection = $sectionsByKey->get('cta');
@endphp

<!-- TEST MARKER: If you see this, the correct file is loaded - v2026.01.31 -->

<!-- Navigation -->
<nav class="navbar">
    <div class="container">
        <div class="navbar-inner">
            <a href="/" class="logo" style="display: flex; align-items: center; gap: 10px; text-decoration: none;">
                @if($__brandLogoUrl)
                    <img src="{{ $__brandLogoUrl }}" alt="{{ $__brandName }}" style="height: 36px; width: auto; object-fit: contain;">
                @else
                    <i class="fab fa-whatsapp"></i>
                @endif
                <span style="font-weight: 700; font-size: 1.25rem;">{{ $__brandName }}</span>
            </a>
            
            <ul class="nav-links">
                <li><a href="#fitur">Fitur</a></li>
                @if($faqSection)
                    <li><a href="#faq">FAQ</a></li>
                @endif
                <li><a href="{{ route('contact') }}">Kontak</a></li>
            </ul>
            
            <div class="nav-cta">
                <a href="{{ route('enter') }}" class="btn btn-outline">Masuk</a>
                <a href="{{ route('register') }}" class="btn btn-primary cta-primary">Mulai Sekarang</a>
            </div>
            
            <button class="mobile-menu-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</nav>

<!-- Mobile Menu -->
<div class="mobile-overlay"></div>
<div class="mobile-menu">
    <button class="mobile-menu-close">
        <i class="fas fa-times"></i>
    </button>
    <ul>
        <li><a href="#fitur">Fitur</a></li>
        @if($faqSection)
            <li><a href="#faq">FAQ</a></li>
        @endif
        <li><a href="{{ route('contact') }}">Kontak</a></li>
    </ul>
    <a href="{{ route('enter') }}" class="btn btn-outline">Masuk</a>
    <a href="{{ route('register') }}" class="btn btn-primary">Mulai Sekarang</a>
</div>

<!-- Hero Section -->
@if($hero)
<section class="hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-text">
                @if($heroBadge || $hero->subtitle)
                    <span class="hero-badge">
                        <i class="fas fa-rocket"></i>
                        {{ $heroBadge?->title ?? $hero->subtitle }}
                    </span>
                @endif
                
                <h1>
                    {{ $hero->title }}
                </h1>
                
                @if($heroDescription?->description)
                    <p>{{ $heroDescription->description }}</p>
                @endif
                
                <div class="hero-cta">
                    @if($heroPrimaryCta?->cta_url && $heroPrimaryCta?->cta_label)
                        <a href="{{ $heroPrimaryCta->cta_url }}" class="btn btn-primary btn-lg cta-secondary">
                            <i class="fas fa-arrow-right"></i>
                            {{ $heroPrimaryCta->cta_label }}
                        </a>
                    @endif
                    @if($heroSecondaryCta?->cta_url && $heroSecondaryCta?->cta_label)
                        <a href="{{ $heroSecondaryCta->cta_url }}" class="btn btn-outline btn-lg">
                            {{ $heroSecondaryCta->cta_label }}
                        </a>
                    @endif
                </div>
                
                @if($heroStats->isNotEmpty())
                    <div class="hero-stats">
                        @foreach($heroStats as $stat)
                            <div class="hero-stat">
                                <div class="hero-stat-value">{{ $stat->title }}</div>
                                <div class="hero-stat-label">{{ $stat->description }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            
            <div class="hero-visual">
                <div class="hero-phone">
                    <div class="phone-mockup">
                        <div class="phone-screen">
                            <div class="phone-header">
                                <div class="phone-avatar">T</div>
                                <div class="phone-contact">
                                    <div class="phone-contact-name">{{ $__brandName }} Campaign</div>
                                    <div class="phone-contact-status">Online</div>
                                </div>
                            </div>
                            
                            <div class="chat-bubble">
                                <p>Halo Kak! 👋</p>
                                <div class="chat-time">09:30</div>
                            </div>
                            
                            <div class="chat-bubble sent">
                                <p>Hai! Ada promo apa hari ini?</p>
                                <div class="chat-time">09:31 ✓✓</div>
                            </div>
                            
                            <div class="chat-bubble">
                                <p>Ada promo spesial untuk Kakak! 🎉 Diskon 50% untuk pembelian pertama. Mau dibantu prosesnya?</p>
                                <div class="chat-time">09:31</div>
                            </div>
                            
                            <div class="chat-bubble sent">
                                <p>Wah boleh! Caranya gimana?</p>
                                <div class="chat-time">09:32 ✓✓</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

<!-- Features Section -->
@if($featuresSection)
<section class="features" id="fitur">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-star"></i>
                {{ $featuresSection->title }}
            </span>
            @if($featuresSection->subtitle)
                <p>{{ $featuresSection->subtitle }}</p>
            @endif
        </div>
        
        <div class="features-grid">
            @foreach($featuresSection->items as $item)
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="{{ $item->icon ?? 'fas fa-check-circle' }}"></i>
                    </div>
                    <h3>{{ $item->title }}</h3>
                    @if($item->description)
                        <p>{{ $item->description }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif



<!-- ==================== PRICING SECTION (MANDATORY) ==================== -->
<!-- SSOT: section pricing WAJIB tampil, dari plans table -->
<!-- Owner Landing CMS TIDAK BOLEH menghapus/hide section ini -->
<!-- Hybrid CTA: is_self_serve → Auto signup, !is_self_serve → Contact sales -->
<section class="pricing" id="paket">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-tags"></i>
                Pilih Paket Terbaik Anda
            </span>
            <h2>Harga Terjangkau untuk Semua Skala Bisnis</h2>
            <p>Mulai dari UMKM hingga Corporate, kami punya paket yang tepat untuk Anda</p>
        </div>
        
        @php
            $allFeatureLabels = \App\Models\Plan::getAllFeatures();
        @endphp

        <div class="pricing-grid">
            @foreach($plans as $plan)
                @php
                    $isPopular = $popularPlan && $popularPlan->id === $plan->id;
                @endphp
                <div class="pricing-card {{ $isPopular ? 'popular' : '' }}">
                    @if($isPopular)
                        <div class="pricing-badge">Paling Populer</div>
                    @endif
                    <div class="pricing-header">
                        <div class="pricing-name">{{ $plan->name }}</div>
                        @if($plan->description)
                            <div class="pricing-desc">{{ $plan->description }}</div>
                        @endif
                    </div>
                    
                    <div class="pricing-price">
                        @if($plan->price_monthly > 0)
                            <span class="pricing-currency">Rp</span>
                            <span class="pricing-amount">{{ number_format($plan->price_monthly, 0, ',', '.') }}</span>
                            <span class="pricing-period">/bulan</span>
                        @else
                            <span class="pricing-amount">Gratis</span>
                        @endif
                    </div>
                    
                    <ul class="pricing-features">
                        <li>
                            <i class="fas fa-check"></i>
                            <span>{{ $plan->max_wa_numbers }} nomor WhatsApp</span>
                        </li>
                        <li>
                            <i class="fas fa-check"></i>
                            <span>{{ $plan->max_campaigns >= 9999 ? 'Unlimited' : $plan->max_campaigns }} campaign</span>
                        </li>
                        <li>
                            <i class="fas fa-check"></i>
                            <span>{{ number_format($plan->max_recipients_per_campaign) }} penerima/campaign</span>
                        </li>
                        <li>
                            <i class="fas fa-check"></i>
                            <span>Saldo pesan (topup terpisah)</span>
                        </li>
                        @if($plan->features && is_array($plan->features))
                            @foreach($plan->features as $featureKey)
                                <li>
                                    <i class="fas fa-check"></i>
                                    <span>{{ $allFeatureLabels[$featureKey] ?? ucfirst(str_replace('_', ' ', $featureKey)) }}</span>
                                </li>
                            @endforeach
                        @endif
                    </ul>
                    
                    @if($plan->is_self_serve)
                        <a href="{{ route('register', ['plan' => $plan->code]) }}" class="btn btn-primary pricing-cta">
                            Mulai Sekarang — Gratis Setup
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    @else
                        <a href="https://wa.me/6281234567890?text={{ urlencode('Halo, saya tertarik dengan paket ' . $plan->name . '. Bisa info lebih lanjut?') }}" 
                           target="_blank" rel="noopener"
                           class="btn btn-outline pricing-cta pricing-cta-sales">
                            <i class="fab fa-whatsapp"></i>
                            Hubungi Sales
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
        
        @if($plans->isEmpty())
            <div style="text-align: center; padding: 48px 0; color: var(--gray);">
                <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                <p>Belum ada paket tersedia saat ini</p>
            </div>
        @endif
    </div>
</section>
<!-- ==================== END PRICING SECTION ==================== -->

<!-- CTA Section -->
@if($ctaSection)
<section class="cta">
    <div class="container">
        <h2>{{ $ctaSection->title }}</h2>
        @if($ctaSection->subtitle)
            <p>{{ $ctaSection->subtitle }}</p>
        @endif
        <div class="cta-buttons">
            @foreach($ctaSection->items as $item)
                @if($item->cta_label && $item->cta_url)
                    <a href="{{ $item->cta_url }}" class="btn btn-white btn-lg">
                        @if($item->icon)
                            <i class="{{ $item->icon }}"></i>
                        @endif
                        {{ $item->cta_label }}
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- FAQ Section -->
@if($faqSection)
<section class="faq" id="faq">
    <div class="container">
        <div class="section-header">
            <span class="section-badge">
                <i class="fas fa-question-circle"></i>
                {{ $faqSection->title }}
            </span>
            @if($faqSection->subtitle)
                <p>{{ $faqSection->subtitle }}</p>
            @endif
        </div>
        
        <div class="faq-list">
            @foreach($faqSection->items as $item)
                <div class="faq-item {{ $loop->first ? 'active' : '' }}">
                    <div class="faq-question">
                        {{ $item->title }}
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        @if($item->description)
                            <p>{{ $item->description }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- Trust Section -->
@if($trustSection)
<section class="trust">
    <div class="container">
        <div class="trust-grid">
            @foreach($trustSection->items as $item)
                <div class="trust-item">
                    <div class="trust-icon">
                        <i class="{{ $item->icon ?? 'fas fa-check-circle' }}"></i>
                    </div>
                    <h4>{{ $item->title }}</h4>
                    @if($item->description)
                        <p>{{ $item->description }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-col">
                <div class="footer-logo" style="display: flex; align-items: center; gap: 10px;">
                    @if($__brandLogoUrl)
                        <img src="{{ $__brandLogoUrl }}" alt="{{ $__brandName }}" style="height: 32px; width: auto; object-fit: contain;">
                    @else
                        <i class="fab fa-whatsapp"></i>
                    @endif
                    <span style="font-weight: 700; font-size: 1.15rem;">{{ $__brandName }}</span>
                </div>
                <p>{{ $__brandTagline }}</p>
            </div>
            
            <div class="footer-col">
                <h4>Produk</h4>
                <ul>
                    <li><a href="#fitur">Fitur</a></li>
                    <li><a href="#paket">Paket</a></li>
                    <li><a href="#">API Documentation</a></li>
                    <li><a href="#">Changelog</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h4>Perusahaan</h4>
                <ul>
                    <li><a href="#">Tentang Kami</a></li>
                    <li><a href="#">Blog</a></li>
                    <li><a href="#">Karir</a></li>
                    <li><a href="{{ route('contact') }}">Kontak</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h4>Legal</h4>
                <ul>
                    <li><a href="#">Syarat & Ketentuan</a></li>
                    <li><a href="#">Kebijakan Privasi</a></li>
                    <li><a href="#">SLA</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; {{ date('Y') }} {{ $__brandName }}. All rights reserved.</p>
            <div class="footer-social">
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-linkedin"></i></a>
                <a href="#"><i class="fab fa-youtube"></i></a>
            </div>
        </div>
    </div>
</footer>

@endsection
