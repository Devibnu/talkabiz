@extends('layouts.landing')

@section('content')

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
                <li><a href="{{ url('/#fitur') }}">Fitur</a></li>
                <li><a href="{{ url('/#faq') }}">FAQ</a></li>
                <li><a href="{{ route('contact') }}" style="color: var(--primary);">Kontak</a></li>
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
        <li><a href="{{ url('/#fitur') }}">Fitur</a></li>
        <li><a href="{{ url('/#faq') }}">FAQ</a></li>
        <li><a href="{{ route('contact') }}">Kontak</a></li>
    </ul>
    <a href="{{ route('enter') }}" class="btn btn-outline">Masuk</a>
    <a href="{{ route('register') }}" class="btn btn-primary">Mulai Sekarang</a>
</div>

<style>
    /* ==================== CONTACT PAGE STYLES ==================== */
    .contact-hero {
        padding: 160px 0 60px;
        text-align: center;
        background: linear-gradient(180deg, #F8FAFC 0%, #FFFFFF 100%);
    }

    .contact-hero h1 {
        font-size: 42px;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 16px;
        line-height: 1.2;
    }

    .contact-hero p {
        font-size: 18px;
        color: var(--gray);
        max-width: 520px;
        margin: 0 auto;
        line-height: 1.7;
    }

    /* Contact Info Cards */
    .contact-info {
        padding: 40px 0 80px;
    }

    .contact-info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 0;
    }

    .contact-info-card {
        background: var(--white);
        border: 1px solid rgba(0, 0, 0, 0.06);
        border-radius: 16px;
        padding: 36px 28px;
        text-align: center;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
    }

    .contact-info-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .contact-info-icon {
        width: 56px;
        height: 56px;
        background: rgba(79, 70, 229, 0.08);
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--primary);
        font-size: 22px;
        margin-bottom: 20px;
    }

    .contact-info-card h3 {
        font-size: 16px;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 8px;
    }

    .contact-info-card p {
        font-size: 15px;
        color: var(--gray);
        line-height: 1.6;
        margin: 0;
    }

    .contact-info-card a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
        font-size: 15px;
        transition: color 0.2s;
    }

    .contact-info-card a:hover {
        color: var(--primary-dark);
    }

    /* Contact Form Section */
    .contact-form-section {
        padding: 80px 0 100px;
        background: var(--light-gray);
    }

    .contact-form-wrapper {
        max-width: 600px;
        margin: 0 auto;
    }

    .contact-form-header {
        text-align: center;
        margin-bottom: 40px;
    }

    .contact-form-header h2 {
        font-size: 28px;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 8px;
    }

    .contact-form-header p {
        font-size: 16px;
        color: var(--gray);
    }

    .contact-form {
        background: var(--white);
        border-radius: 20px;
        padding: 40px;
        box-shadow: var(--shadow);
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 6px;
    }

    .form-group label .optional {
        font-weight: 400;
        color: var(--gray);
        font-size: 13px;
    }

    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #E5E7EB;
        border-radius: 10px;
        font-family: inherit;
        font-size: 15px;
        color: var(--dark);
        background: var(--white);
        transition: border-color 0.2s, box-shadow 0.2s;
        outline: none;
    }

    .form-group input:focus,
    .form-group textarea:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: #9CA3AF;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 130px;
    }

    .form-error {
        font-size: 13px;
        color: #EF4444;
        margin-top: 4px;
    }

    .form-submit-btn {
        width: 100%;
        padding: 14px 28px;
        background: var(--gradient);
        color: white;
        border: none;
        border-radius: 10px;
        font-family: inherit;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 14px 0 rgba(79, 70, 229, 0.35);
        margin-top: 8px;
    }

    .form-submit-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px 0 rgba(79, 70, 229, 0.45);
    }

    .form-submit-btn:active {
        transform: translateY(0);
    }

    /* Success Alert */
    .contact-alert {
        display: flex;
        align-items: center;
        gap: 12px;
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.2);
        color: #065F46;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 15px;
        font-weight: 500;
    }

    .contact-alert i {
        font-size: 20px;
        color: var(--secondary);
        flex-shrink: 0;
    }

    /* Map Section */
    .contact-map {
        padding: 0 0 80px;
    }

    .contact-map-header {
        text-align: center;
        margin-bottom: 32px;
    }

    .contact-map-header h2 {
        font-size: 28px;
        font-weight: 800;
        color: var(--dark);
        margin-bottom: 8px;
    }

    .contact-map-header p {
        font-size: 16px;
        color: var(--gray);
    }

    .map-wrapper {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0, 0, 0, 0.06);
    }

    .map-wrapper iframe {
        display: block;
        width: 100%;
        height: 400px;
        border: 0;
    }

    .map-open-link {
        display: flex;
        justify-content: center;
        padding: 16px 0;
        background: var(--light-gray);
        border-top: 1px solid rgba(0, 0, 0, 0.05);
    }

    .map-open-link a {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 24px;
        background: var(--gradient);
        color: white;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
    }

    .map-open-link a:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.4);
    }

    .map-open-link a svg {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .contact-hero {
            padding: 120px 0 40px;
        }

        .contact-hero h1 {
            font-size: 30px;
        }

        .contact-hero p {
            font-size: 16px;
        }

        .contact-info-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .contact-form-section {
            padding: 48px 0 64px;
        }

        .contact-form {
            padding: 28px 20px;
        }

        .contact-form-header h2 {
            font-size: 24px;
        }

        .contact-map {
            padding: 0 0 48px;
        }

        .map-wrapper iframe {
            height: 300px;
        }

        .contact-map-header h2 {
            font-size: 24px;
        }
    }
</style>

<!-- Section 1 — Hero -->
<section class="contact-hero">
    <div class="container">
        <h1>Hubungi Tim {{ $__brandName }}</h1>
        <p>Kami siap membantu kebutuhan WhatsApp Marketing bisnis Anda.</p>
    </div>
</section>

<!-- Section 2 — Contact Info Cards -->
<section class="contact-info">
    <div class="container">
        <div class="contact-info-grid">

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h3>Email</h3>
                <p><a href="mailto:{{ $__contactEmail }}">{{ $__contactEmail }}</a></p>
            </div>

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <i class="fab fa-whatsapp"></i>
                </div>
                <h3>WhatsApp Sales</h3>
                <p><a href="{{ $__contactPhoneUrl }}" target="_blank" rel="noopener">{{ $__contactPhone }}</a></p>
            </div>

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Jam Operasional</h3>
                <p>{{ $__operatingHours }}</p>
            </div>

        </div>
    </div>
</section>

<!-- Section 3 — Map -->
<section class="contact-map">
    <div class="container">
        <div class="contact-map-header">
            <h2>Lokasi Kami</h2>
            <p>Kunjungi kantor kami atau hubungi secara online.</p>
        </div>

        <div class="map-wrapper">
            <iframe
                src="{{ $__mapsEmbedUrl }}"
                allowfullscreen=""
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
            <div class="map-open-link">
                <a href="{{ $__mapsLink }}" target="_blank" rel="noopener">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>
                    Buka di Google Maps
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Section 4 — Contact Form -->
<section class="contact-form-section">
    <div class="container">
        <div class="contact-form-wrapper">

            <div class="contact-form-header">
                <h2>Kirim Pesan</h2>
                <p>Isi form di bawah dan tim kami akan merespons dalam 1×24 jam kerja.</p>
            </div>

            <div class="contact-form">

                @if(session('success'))
                    <div class="contact-alert">
                        <i class="fas fa-check-circle"></i>
                        <span>{{ session('success') }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('contact.send') }}">
                    @csrf

                    <div class="form-group">
                        <label for="name">Nama</label>
                        <input type="text" id="name" name="name" placeholder="Nama lengkap Anda" value="{{ old('name') }}" required>
                        @error('name') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="email@perusahaan.com" value="{{ old('email') }}" required>
                        @error('email') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label for="company">Perusahaan <span class="optional">(opsional)</span></label>
                        <input type="text" id="company" name="company" placeholder="Nama perusahaan atau brand" value="{{ old('company') }}">
                        @error('company') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="form-group">
                        <label for="message">Pesan</label>
                        <textarea id="message" name="message" placeholder="Ceritakan kebutuhan Anda..." required>{{ old('message') }}</textarea>
                        @error('message') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="form-submit-btn">
                        Kirim Pesan
                    </button>
                </form>

            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer>
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <div style="display: flex; align-items: center; gap: 10px;">
                    @if($__brandLogoUrl)
                        <img src="{{ $__brandLogoUrl }}" alt="{{ $__brandName }}" style="height: 32px; width: auto; filter: brightness(0) invert(1);">
                    @else
                        <i class="fab fa-whatsapp" style="font-size: 28px; color: var(--primary-light);"></i>
                    @endif
                    <span style="font-weight: 700; font-size: 1.15rem;">{{ $__brandName }}</span>
                </div>
                <p>{{ $__brandTagline }}</p>
            </div>
            
            <div class="footer-col">
                <h4>Produk</h4>
                <ul>
                    <li><a href="{{ url('/#fitur') }}">Fitur</a></li>
                    <li><a href="{{ url('/#paket') }}">Paket</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h4>Perusahaan</h4>
                <ul>
                    <li><a href="{{ route('contact') }}">Kontak</a></li>
                </ul>
            </div>
            
            <div class="footer-col">
                <h4>Legal</h4>
                <ul>
                    <li><a href="#">Syarat & Ketentuan</a></li>
                    <li><a href="#">Kebijakan Privasi</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; {{ date('Y') }} {{ $__brandName }}. All rights reserved.</p>
            <div class="footer-social">
                <a href="#"><i class="fab fa-instagram"></i></a>
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-linkedin"></i></a>
            </div>
        </div>
    </div>
</footer>

@endsection
