<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $__brandName ?? 'Talkabiz' }} - Platform WhatsApp Campaign & Inbox untuk Bisnis. Kirim WhatsApp massal dengan aman, terkontrol, dan siap scale untuk UMKM hingga Corporate.">
    <meta name="keywords" content="whatsapp blast, whatsapp marketing, wa blast, whatsapp campaign, whatsapp business, {{ strtolower($__brandName ?? 'talkabiz') }}">
    <meta name="author" content="{{ $__brandName ?? 'Talkabiz' }}">
    
    <!-- Open Graph -->
    <meta property="og:title" content="{{ $__brandName ?? 'Talkabiz' }} - Platform WhatsApp Campaign untuk Bisnis">
    <meta property="og:description" content="Kirim WhatsApp massal dengan aman, terkontrol, dan siap scale untuk UMKM hingga Corporate.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    
    <link rel="apple-touch-icon" sizes="76x76" href="{{ $__brandFaviconUrl ?? asset('assets/img/apple-icon.png') }}">
    <link rel="icon" type="image/png" href="{{ $__brandFaviconUrl ?? asset('assets/img/favicon.png') }}">
    <title>{{ $__brandName ?? 'Talkabiz' }} - Platform WhatsApp Campaign untuk Bisnis</title>
    
    <!-- Fonts and icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <style>
        /* ===========================================
           HOTFIX: CTA Click Protection (v3)
           Fixed: All navigation uses <a> tags only
           No more <button onclick> for navigation
           =========================================== */
        
        /* Hide any empty buttons that might exist */
        button:empty,
        .nav-cta button:not(.mobile-menu-btn) {
            display: none !important;
            pointer-events: none !important;
        }
        
        /* Force ALL clickable links above all layers */
        .nav-cta a,
        .hero-cta a,
        .cta-primary,
        .cta-secondary,
        a.btn-primary,
        a.btn-outline,
        a.btn[href*="register"],
        a.btn[href*="login"],
        .mobile-menu a.btn,
        .pricing-cta,
        nav a {
            position: relative !important;
            z-index: 9999 !important;
            pointer-events: auto !important;
            cursor: pointer !important;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        
        /* Ensure navbar and its children are clickable */
        .navbar,
        .navbar-inner,
        .nav-cta,
        .hero-cta,
        .pricing-card {
            pointer-events: auto !important;
        }
        
        /* Mobile overlay must not block when hidden */
        .mobile-overlay:not(.active) {
            pointer-events: none !important;
        }
        
        /* END HOTFIX */
        
        :root {
            --primary: #4F46E5;
            --primary-dark: #4338CA;
            --primary-light: #818CF8;
            --secondary: #10B981;
            --secondary-dark: #059669;
            --dark: #1F2937;
            --gray: #6B7280;
            --light-gray: #F3F4F6;
            --white: #FFFFFF;
            --gradient: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Open Sans', sans-serif;
            color: var(--dark);
            line-height: 1.6;
            background: var(--white);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
        }

        .logo {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logo i {
            font-size: 28px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 32px;
            list-style: none;
        }

        .nav-links a {
            color: var(--gray);
            text-decoration: none;
            font-weight: 500;
            font-size: 15px;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-cta {
            display: flex;
            gap: 12px;
            position: relative;
            z-index: 10;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            position: relative;
            z-index: 10;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: 0 4px 14px 0 rgba(79, 70, 229, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px 0 rgba(79, 70, 229, 0.5);
        }

        .btn-outline {
            background: transparent;
            color: var(--dark);
            border: 2px solid var(--light-gray);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--secondary-dark);
        }

        .btn-lg {
            padding: 16px 32px;
            font-size: 16px;
        }

        /* Hero Section */
        .hero {
            padding: 140px 0 80px;
            background: linear-gradient(180deg, #F8FAFC 0%, #FFFFFF 100%);
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 50%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%234F46E5' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
            pointer-events: none;
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            padding: 8px 16px;
            border-radius: 100px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
        }

        .hero h1 {
            font-size: 48px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 24px;
            color: var(--dark);
        }

        .hero h1 span {
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero p {
            font-size: 18px;
            color: var(--gray);
            margin-bottom: 32px;
            line-height: 1.7;
        }

        .hero-cta {
            display: flex;
            gap: 16px;
            margin-bottom: 40px;
        }

        .hero-stats {
            display: flex;
            gap: 48px;
        }

        .hero-stat {
            text-align: left;
        }

        .hero-stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }

        .hero-stat-label {
            font-size: 14px;
            color: var(--gray);
        }

        .hero-visual {
            position: relative;
        }

        .hero-phone {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            position: relative;
        }

        .phone-mockup {
            background: var(--white);
            border-radius: 40px;
            padding: 12px;
            box-shadow: var(--shadow-lg), 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            border: 8px solid var(--dark);
        }

        .phone-screen {
            background: linear-gradient(135deg, #DCF8C6 0%, #E8F5E9 100%);
            border-radius: 28px;
            padding: 20px;
            min-height: 500px;
        }

        .phone-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            margin-bottom: 16px;
        }

        .phone-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .phone-contact {
            flex: 1;
        }

        .phone-contact-name {
            font-weight: 600;
            font-size: 15px;
        }

        .phone-contact-status {
            font-size: 12px;
            color: var(--gray);
        }

        .chat-bubble {
            background: white;
            padding: 12px 16px;
            border-radius: 18px;
            border-bottom-left-radius: 4px;
            margin-bottom: 12px;
            max-width: 85%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .chat-bubble.sent {
            background: var(--primary);
            color: white;
            margin-left: auto;
            border-bottom-left-radius: 18px;
            border-bottom-right-radius: 4px;
        }

        .chat-bubble p {
            font-size: 14px;
            margin: 0;
            color: inherit;
        }

        .chat-time {
            font-size: 11px;
            color: var(--gray);
            text-align: right;
            margin-top: 4px;
        }

        .chat-bubble.sent .chat-time {
            color: rgba(255,255,255,0.7);
        }

        /* Sections */
        section {
            padding: 100px 0;
        }

        .section-header {
            text-align: center;
            max-width: 600px;
            margin: 0 auto 60px;
        }

        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .section-header h2 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
            color: var(--dark);
        }

        .section-header p {
            font-size: 18px;
            color: var(--gray);
        }

        /* Features */
        .features {
            background: var(--light-gray);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
        }

        .feature-card {
            background: white;
            padding: 32px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .feature-icon {
            width: 56px;
            height: 56px;
            background: var(--gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-bottom: 20px;
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--dark);
        }

        .feature-card p {
            color: var(--gray);
            font-size: 15px;
            line-height: 1.6;
        }

        /* Pricing */
        .pricing {
            background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFC 100%);
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .pricing-card {
            background: white;
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: var(--shadow);
            position: relative;
            border: 2px solid transparent;
            transition: all 0.3s;
        }

        .pricing-card:hover {
            border-color: var(--primary-light);
        }

        .pricing-card.popular {
            border-color: var(--primary);
            transform: scale(1.05);
        }

        .pricing-card.popular::before {
            content: 'Paling Populer';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--gradient);
            color: white;
            padding: 6px 20px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
        }

        .pricing-header {
            text-align: center;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--light-gray);
            margin-bottom: 24px;
        }

        .pricing-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .pricing-desc {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 16px;
        }

        .pricing-price {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 4px;
        }

        .pricing-currency {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .pricing-amount {
            font-size: 42px;
            font-weight: 800;
            color: var(--dark);
        }

        .pricing-period {
            font-size: 14px;
            color: var(--gray);
        }

        .pricing-discount {
            text-align: center;
            margin: 16px 0;
        }

        .pricing-discount .original-price {
            font-size: 14px;
            color: var(--gray);
            text-decoration: line-through;
            margin-right: 8px;
        }

        .pricing-discount .discount-badge {
            background: var(--secondary);
            color: white;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
        }

        .pricing-features {
            list-style: none;
            margin-bottom: 32px;
        }

        .pricing-features li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 14px;
            color: var(--dark);
        }

        .pricing-features i {
            color: var(--secondary);
            margin-top: 2px;
        }

        .pricing-features li.disabled {
            color: var(--gray);
        }

        .pricing-features li.disabled i {
            color: var(--gray);
        }

        .pricing-cta {
            width: 100%;
        }

        /* Sales CTA variant (corporate plans) */
        .pricing-cta-sales {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        .pricing-cta-sales:hover {
            background: var(--primary);
            color: #fff;
        }
        .pricing-cta-sales i {
            margin-right: 6px;
        }

        /* Popular badge */
        .pricing-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            z-index: 2;
        }

        /* Trust Section */
        .trust {
            background: var(--dark);
            color: white;
        }

        .trust .section-header h2 {
            color: white;
        }

        .trust .section-header p {
            color: rgba(255,255,255,0.7);
        }

        .trust-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 32px;
        }

        .trust-item {
            text-align: center;
            padding: 24px;
        }

        .trust-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--primary-light);
        }

        .trust-item h4 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .trust-item p {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
        }

        /* FAQ */
        .faq-list {
            max-width: 800px;
            margin: 0 auto;
        }

        .faq-item {
            background: white;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            color: var(--dark);
            transition: background 0.2s;
        }

        .faq-question:hover {
            background: var(--light-gray);
        }

        .faq-question i {
            transition: transform 0.3s;
        }

        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }

        .faq-answer {
            padding: 0 24px;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s;
        }

        .faq-item.active .faq-answer {
            padding: 0 24px 20px;
            max-height: 200px;
        }

        .faq-answer p {
            color: var(--gray);
            font-size: 15px;
            line-height: 1.7;
        }

        /* CTA Section */
        .cta {
            background: var(--gradient);
            text-align: center;
            padding: 80px 0;
        }

        .cta h2 {
            font-size: 36px;
            font-weight: 800;
            color: white;
            margin-bottom: 16px;
        }

        .cta p {
            font-size: 18px;
            color: rgba(255,255,255,0.9);
            margin-bottom: 32px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
        }

        .btn-white {
            background: white;
            color: var(--primary);
        }

        .btn-white:hover {
            background: var(--light-gray);
        }

        /* Footer */
        footer {
            background: var(--dark);
            color: rgba(255,255,255,0.7);
            padding: 60px 0 24px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 48px;
            margin-bottom: 48px;
        }

        .footer-brand p {
            margin-top: 16px;
            font-size: 14px;
            line-height: 1.7;
        }

        .footer-col h4 {
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .footer-col ul {
            list-style: none;
        }

        .footer-col li {
            margin-bottom: 12px;
        }

        .footer-col a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }

        .footer-col a:hover {
            color: white;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .footer-social {
            display: flex;
            gap: 16px;
        }

        .footer-social a {
            color: rgba(255,255,255,0.7);
            font-size: 20px;
            transition: color 0.2s;
        }

        .footer-social a:hover {
            color: white;
        }

        /* Mobile Menu */
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero-cta {
                justify-content: center;
            }

            .hero-stats {
                justify-content: center;
            }

            .hero-visual {
                max-width: 320px;
                margin: 0 auto;
            }

            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .pricing-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
            }

            .pricing-card.popular {
                transform: none;
            }

            .trust-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .nav-links, .nav-cta {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .hero h1 {
                font-size: 32px;
            }

            .hero {
                padding: 120px 0 60px;
            }

            section {
                padding: 60px 0;
            }

            .section-header h2 {
                font-size: 28px;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .footer-grid {
                grid-template-columns: 1fr;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }
        }

        /* Mobile Menu Panel */
        .mobile-menu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 280px;
            height: 100vh;
            background: white;
            z-index: 1001;
            padding: 80px 24px 24px;
            box-shadow: -4px 0 20px rgba(0,0,0,0.1);
            transition: right 0.3s ease;
            pointer-events: none;
        }

        .mobile-menu.active {
            right: 0;
            pointer-events: auto;
        }

        .mobile-menu-close {
            position: absolute;
            top: 24px;
            right: 24px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
        }

        .mobile-menu ul {
            list-style: none;
        }

        .mobile-menu li {
            margin-bottom: 16px;
        }

        .mobile-menu a {
            display: block;
            padding: 12px 0;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            font-size: 16px;
            border-bottom: 1px solid var(--light-gray);
        }

        .mobile-menu .btn {
            width: 100%;
            margin-top: 16px;
        }

        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: all 0.3s;
        }

        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        /* === CLEAN FIX: Pricing CTA Clickability === */
        
        /* Pricing card needs position relative for z-index */
        .pricing-card {
            position: relative;
            z-index: 1;
        }
        
        /* Pricing CTA must be above all pseudo-elements */
        .pricing-cta {
            position: relative !important;
            z-index: 100 !important;
            pointer-events: auto !important;
            display: block !important;
            cursor: pointer !important;
            text-decoration: none !important;
        }
        
        /* Ensure anchor tag works */
        a.pricing-cta {
            display: block !important;
            width: 100% !important;
            text-align: center !important;
            pointer-events: auto !important;
            cursor: pointer !important;
        }
        
        /* Popular badge ::before must not block clicks */
        .pricing-card.popular::before {
            pointer-events: none !important;
        }
        
        /* Pricing features should not block CTA */
        .pricing-features {
            pointer-events: none;
        }
        
        .pricing-features li {
            pointer-events: auto;
        }
        
        /* === END FIX === */
    </style>
</head>

<body>
    @yield('content')

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // ============================================
            // PRICING CTA CLICK HANDLER - AGGRESSIVE FIX
            // Handles both data-url and href attributes
            // ============================================
            const pricingButtons = document.querySelectorAll('.pricing-cta');
            console.log('[Talkabiz] Found pricing CTAs:', pricingButtons.length);
            
            pricingButtons.forEach(btn => {
                // Remove any existing onclick to avoid conflicts
                btn.removeAttribute('onclick');
                
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Try data-url first, then href
                    let url = this.getAttribute('data-url') || this.getAttribute('href');
                    
                    // Skip javascript:void(0) or # links
                    if (url && url !== '#' && !url.startsWith('javascript:')) {
                        console.log('[Talkabiz] CTA clicked, navigating to:', url);
                        window.location.href = url;
                    }
                });
            });
            
            // BACKUP: Catch all links with register in href
            document.querySelectorAll('a[href*="register"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    const url = this.getAttribute('href');
                    if (url && !url.startsWith('javascript:')) {
                        e.preventDefault();
                        console.log('[Talkabiz] Register link clicked:', url);
                        window.location.href = url;
                    }
                });
            });

            // FAQ Toggle
            document.querySelectorAll('.faq-question').forEach(question => {
                question.addEventListener('click', () => {
                    const item = question.parentElement;
                item.classList.toggle('active');
            });
        });

        // Mobile Menu
        const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
        const mobileMenu = document.querySelector('.mobile-menu');
        const mobileOverlay = document.querySelector('.mobile-overlay');
        const mobileMenuClose = document.querySelector('.mobile-menu-close');

        function openMobileMenu() {
            mobileMenu.classList.add('active');
            mobileOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileMenu() {
            mobileMenu.classList.remove('active');
            mobileOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileMenuBtn?.addEventListener('click', openMobileMenu);
        mobileMenuClose?.addEventListener('click', closeMobileMenu);
        mobileOverlay?.addEventListener('click', closeMobileMenu);

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    closeMobileMenu();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
            } else {
                navbar.style.boxShadow = 'none';
            }
        });

        }); // End DOMContentLoaded
    </script>
</body>
</html>
