<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="apple-touch-icon" sizes="76x76" href="{{ $__brandFaviconUrl ?? asset('assets/img/apple-icon.png') }}">
    <link rel="icon" type="image/png" href="{{ $__brandFaviconUrl ?? asset('assets/img/favicon.png') }}">
    <title>@yield('title', 'Owner Panel') - {{ $__brandName ?? 'Talkabiz' }}</title>
    
    <!-- Fonts and icons -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="{{ asset('assets/css/nucleo-icons.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/css/nucleo-svg.css') }}" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link id="pagestyle" href="{{ asset('assets/css/soft-ui-dashboard.css?v=1.0.3') }}" rel="stylesheet" />
    
    <style>
        /* ============================================
           OWNER PANEL - STEP 6 COMPLETE REDESIGN
           Responsive, Clean, Production-Ready
        ============================================ */
        
        :root {
            --owner-primary: #7928CA;
            --owner-secondary: #FF0080;
            --owner-dark: #1a1a2e;
            --owner-dark-2: #16213e;
            --sidebar-width: 250px;
            --navbar-height: 70px;
            --banner-height: 36px;
        }
        
        /* ============================================
           OWNER BANNER - FIXED TOP
        ============================================ */
        .owner-banner {
            background: linear-gradient(90deg, var(--owner-primary), var(--owner-secondary));
            color: white;
            padding: 0 16px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1100;
            height: var(--banner-height);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* ============================================
           SIDEBAR - RESPONSIVE
        ============================================ */
        .owner-sidebar {
            position: fixed;
            top: var(--banner-height);
            left: 0;
            width: var(--sidebar-width);
            height: calc(100vh - var(--banner-height));
            background: linear-gradient(180deg, var(--owner-dark) 0%, var(--owner-dark-2) 100%);
            z-index: 1050;
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            border-radius: 0 1rem 1rem 0;
        }
        
        /* Desktop: Sidebar visible */
        @media (min-width: 1200px) {
            .owner-sidebar {
                transform: translateX(0);
            }
            .owner-main {
                margin-left: var(--sidebar-width);
            }
        }
        
        /* Tablet & Mobile: Sidebar hidden by default */
        @media (max-width: 1199.98px) {
            .owner-sidebar {
                transform: translateX(-100%);
                border-radius: 0;
            }
            .owner-sidebar.show {
                transform: translateX(0);
            }
            .owner-main {
                margin-left: 0;
            }
        }
        
        /* Sidebar Overlay */
        .sidebar-overlay {
            position: fixed;
            top: var(--banner-height);
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        /* Sidebar Header */
        .sidebar-header {
            padding: 1.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            text-decoration: none;
        }
        .sidebar-brand-icon {
            width: 36px;
            height: 36px;
            border-radius: 0.5rem;
            background: linear-gradient(145deg, #f5a623, #f7931e);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            box-shadow: 0 4px 6px rgba(245, 166, 35, 0.3);
        }
        .sidebar-brand-icon i {
            color: white;
            font-size: 1rem;
        }
        .sidebar-brand-text {
            color: white;
            font-weight: 700;
            font-size: 1rem;
        }
        .sidebar-close {
            display: none;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .sidebar-close:hover {
            background: rgba(255,255,255,0.2);
        }
        @media (max-width: 1199.98px) {
            .sidebar-close {
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
        
        /* Sidebar Navigation */
        .sidebar-nav {
            padding: 0 0.75rem;
        }
        .sidebar-nav-item {
            margin-bottom: 4px;
        }
        .sidebar-nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        .sidebar-nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .sidebar-nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        .sidebar-nav-icon {
            width: 32px;
            height: 32px;
            border-radius: 0.375rem;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .sidebar-nav-icon i {
            font-size: 0.875rem;
            color: #344767;
        }
        .sidebar-nav-text {
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .sidebar-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            margin: 1rem 0;
        }
        
        .sidebar-section-title {
            padding: 0.75rem 1rem 0.25rem;
        }
        .sidebar-section-title span {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            padding: 1rem;
            margin-top: auto;
        }
        .sidebar-user-card {
            background: rgba(255,255,255,0.08);
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
        }
        .sidebar-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            background: linear-gradient(145deg, #f5a623, #f7931e);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            box-shadow: 0 4px 6px rgba(245, 166, 35, 0.3);
        }
        .sidebar-user-avatar span {
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
        }
        .sidebar-user-name {
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        .sidebar-user-role {
            color: rgba(255,255,255,0.6);
            font-size: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        /* ============================================
           MAIN CONTENT AREA
        ============================================ */
        .owner-main {
            padding-top: calc(var(--banner-height) + var(--navbar-height));
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        /* ============================================
           NAVBAR - FIXED
        ============================================ */
        .owner-navbar {
            position: fixed;
            top: var(--banner-height);
            right: 0;
            left: 0;
            height: var(--navbar-height);
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            z-index: 1030;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: left 0.3s ease;
        }
        @media (min-width: 1200px) {
            .owner-navbar {
                left: var(--sidebar-width);
            }
        }
        
        .navbar-hamburger {
            display: none;
            background: none;
            border: none;
            padding: 0.5rem;
            cursor: pointer;
            margin-right: 1rem;
        }
        @media (max-width: 1199.98px) {
            .navbar-hamburger {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
        }
        .hamburger-line {
            width: 22px;
            height: 2px;
            background: #344767;
            border-radius: 1px;
            transition: all 0.3s ease;
        }
        
        .navbar-title {
            flex: 1;
        }
        .navbar-title h6 {
            margin: 0;
            font-weight: 700;
            color: #344767;
            font-size: 0.875rem;
        }
        .navbar-title small {
            color: #67748e;
            font-size: 0.75rem;
        }
        
        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .navbar-badge {
            background: linear-gradient(145deg, #344767, #1a1a2e);
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }
        .user-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: none;
            border: none;
            cursor: pointer;
            border-radius: 0.5rem;
            transition: background 0.2s ease;
        }
        .user-dropdown-toggle:hover {
            background: rgba(0,0,0,0.05);
        }
        .user-dropdown-avatar {
            width: 36px;
            height: 36px;
            border-radius: 0.5rem;
            background: linear-gradient(145deg, #f5a623, #f7931e);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .user-dropdown-avatar span {
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
        }
        .user-dropdown-name {
            font-weight: 600;
            color: #344767;
            font-size: 0.875rem;
        }
        .user-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 220px;
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 0.5rem;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.2s ease;
            z-index: 1060;
        }
        .user-dropdown.show .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.75rem;
            color: #344767;
            text-decoration: none;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: background 0.2s ease;
        }
        .user-dropdown-item:hover {
            background: #f8f9fa;
            color: #344767;
        }
        .user-dropdown-item.active {
            background: linear-gradient(145deg, var(--owner-primary), var(--owner-secondary));
            color: white;
        }
        .user-dropdown-item.text-danger:hover {
            background: #fff5f5;
        }
        .user-dropdown-divider {
            height: 1px;
            background: #e9ecef;
            margin: 0.5rem 0;
        }
        
        /* ============================================
           RESPONSIVE TABLES
        ============================================ */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 0.5rem;
        }
        .table-responsive > .table {
            min-width: 700px;
            margin-bottom: 0;
        }
        
        /* ============================================
           CARDS
        ============================================ */
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .card.h-100 {
            height: 100% !important;
        }
        
        /* ============================================
           TOAST NOTIFICATIONS
        ============================================ */
        .toast-container {
            position: fixed;
            top: calc(var(--banner-height) + 20px);
            right: 20px;
            z-index: 1200;
        }
        .toast-alert {
            min-width: 300px;
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            margin-bottom: 0.5rem;
            animation: slideInRight 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .toast-alert.success {
            background: #d1fae5;
            color: #065f46;
        }
        .toast-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }
        .toast-alert.warning {
            background: #fef3c7;
            color: #92400e;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* ============================================
           EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        .empty-state-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f0f2f5, #e0e4e8);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .empty-state-icon i {
            font-size: 2rem;
            color: #a0aec0;
        }
        
        /* ============================================
           MOBILE RESPONSIVE
        ============================================ */
        @media (max-width: 767.98px) {
            :root {
                --banner-height: 32px;
                --navbar-height: 60px;
            }
            .owner-banner {
                font-size: 11px;
            }
            .container-fluid {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            .card-body {
                padding: 1rem;
            }
            .navbar-badge {
                display: none;
            }
            .user-dropdown-name {
                display: none;
            }
        }
        
        @media (max-width: 575.98px) {
            .row > [class*="col-sm-6"] {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .table th, .table td {
                font-size: 0.75rem;
                padding: 0.5rem;
            }
            .btn-sm {
                padding: 0.375rem 0.625rem;
                font-size: 0.7rem;
            }
        }
    </style>
    
    @stack('styles')
</head>

<body class="bg-gray-100">
    <!-- Owner Banner -->
    <div class="owner-banner">
        <i class="fas fa-shield-alt me-2"></i>
        <span>OWNER PANEL</span>
        <span class="d-none d-md-inline"> — Anda login sebagai Owner/Super Admin</span>
    </div>

    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="owner-sidebar" id="ownerSidebar">
        <div class="sidebar-header">
            <a href="{{ route('owner.dashboard') }}" class="sidebar-brand">
                <div class="sidebar-brand-icon">
                    <i class="fas fa-crown"></i>
                </div>
                <span class="sidebar-brand-text">Owner Panel</span>
            </a>
            <button type="button" class="sidebar-close" id="sidebarClose">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="sidebar-divider"></div>
        
        <nav class="sidebar-nav">
            {{-- 1. Dashboard Owner --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('owner.dashboard*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <span class="sidebar-nav-text">Dashboard Owner</span>
                </a>
            </div>
            
            {{-- 2. Klien UMKM --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.clients.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.clients*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <span class="sidebar-nav-text">Klien UMKM</span>
                </a>
            </div>

            {{-- === MASTER DATA === --}}
            <div class="sidebar-section-title">
                <span>MASTER DATA</span>
            </div>

            {{-- 2b. Master Tipe Bisnis --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.master.business-types.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.master.business-types*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <span class="sidebar-nav-text">Master Tipe Bisnis</span>
                </a>
            </div>
            
            {{-- 3. WhatsApp Control --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.whatsapp.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.whatsapp*') || request()->routeIs('owner.wa-connections*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fab fa-whatsapp text-success"></i>
                    </div>
                    <span class="sidebar-nav-text">WhatsApp Control</span>
                </a>
            </div>
            
            {{-- 4. Billing & Revenue --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.billing.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.billing*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <span class="sidebar-nav-text">Billing & Revenue</span>
                </a>
            </div>
            
            {{-- 4b. CFO Dashboard --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.cfo.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.cfo*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-chart-line text-primary"></i>
                    </div>
                    <span class="sidebar-nav-text">CFO Dashboard</span>
                </a>
            </div>
            
            {{-- 5. Payment Gateway --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.payment-gateway.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.payment-gateway*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <span class="sidebar-nav-text">Payment Gateway</span>
                </a>
            </div>

            {{-- 5b. Laporan Pajak (PPN) --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.tax-report.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.tax-report*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-file-invoice-dollar text-warning"></i>
                    </div>
                    <span class="sidebar-nav-text">Laporan Pajak</span>
                </a>
            </div>

            {{-- 5c. Monthly Closing --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.closing.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.closing*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-calendar-check text-success"></i>
                    </div>
                    <span class="sidebar-nav-text">Monthly Closing</span>
                </a>
            </div>

            {{-- 5d. Rekonsiliasi Bank & Gateway --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.reconciliation.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.reconciliation*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-balance-scale text-info"></i>
                    </div>
                    <span class="sidebar-nav-text">Rekonsiliasi</span>
                </a>
            </div>
            
            {{-- 6. Plans / Paket (SSOT) --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.plans.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.plans*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <span class="sidebar-nav-text">Paket / Plans</span>
                </a>
            </div>

            {{-- 6b. Landing Page CMS --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.landing.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.landing*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <span class="sidebar-nav-text">Landing Page</span>
                </a>
            </div>

            {{-- 6c. Branding & Logo (SSOT) --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.branding.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.branding*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-paint-brush text-info"></i>
                    </div>
                    <span class="sidebar-nav-text">Branding & Logo</span>
                </a>
            </div>

            {{-- 6d. System Settings (SSOT) --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.settings.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.settings*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <span class="sidebar-nav-text">System Settings</span>
                </a>
            </div>
            
            {{-- 7. Logs & Audit --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.logs.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.logs*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <span class="sidebar-nav-text">Logs & Audit</span>
                </a>
            </div>
            
            {{-- 7b. Audit Trail & Immutable Ledger --}}
            <div class="sidebar-nav-item">
                <a href="{{ route('owner.audit-trail.index') }}" class="sidebar-nav-link {{ request()->routeIs('owner.audit-trail*') ? 'active' : '' }}">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-shield-alt text-danger"></i>
                    </div>
                    <span class="sidebar-nav-text">Audit Trail</span>
                </a>
            </div>
            
            <div class="sidebar-divider"></div>
            
            {{-- Switch to Client View --}}
            <div class="sidebar-nav-item">
                <a href="javascript:;" class="sidebar-nav-link" onclick="showSwitchModal()">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-exchange-alt text-info"></i>
                    </div>
                    <span class="sidebar-nav-text">Switch to Client View</span>
                </a>
            </div>
            
            {{-- Logout --}}
            <div class="sidebar-nav-item">
                <a href="javascript:;" class="sidebar-nav-link" onclick="document.getElementById('logout-form').submit();">
                    <div class="sidebar-nav-icon">
                        <i class="fas fa-sign-out-alt text-danger"></i>
                    </div>
                    <span class="sidebar-nav-text">Logout</span>
                </a>
            </div>
        </nav>
        
        <div class="sidebar-footer">
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar">
                    <span>{{ strtoupper(substr(auth()->user()->name ?? 'O', 0, 1)) }}</span>
                </div>
                <div class="sidebar-user-name">{{ auth()->user()->name ?? 'Owner' }}</div>
                <div class="sidebar-user-role">{{ ucfirst(str_replace('_', ' ', auth()->user()->role ?? 'Owner')) }}</div>
            </div>
        </div>
    </aside>

    <!-- Navbar -->
    <nav class="owner-navbar">
        <button type="button" class="navbar-hamburger" id="navbarHamburger">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>
        
        <div class="navbar-title">
            <h6>@yield('page-title', 'Dashboard')</h6>
            <small>Owner Panel</small>
        </div>
        
        <div class="navbar-actions">
            <div class="navbar-badge d-none d-sm-flex">
                <i class="fas fa-shield-alt"></i>
                <span>OWNER MODE</span>
            </div>
            
            <div class="user-dropdown" id="userDropdown">
                <button type="button" class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                    <div class="user-dropdown-avatar">
                        <span>{{ strtoupper(substr(auth()->user()->name ?? 'O', 0, 1)) }}</span>
                    </div>
                    <span class="user-dropdown-name d-none d-md-inline">{{ auth()->user()->name ?? 'Owner' }}</span>
                    <i class="fas fa-chevron-down text-xs d-none d-md-inline"></i>
                </button>
                
                <div class="user-dropdown-menu">
                    <a href="{{ route('owner.dashboard') }}" class="user-dropdown-item {{ request()->routeIs('owner.dashboard*') ? 'active' : '' }}">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Owner</span>
                    </a>
                    <a href="{{ route('owner.users.index') }}" class="user-dropdown-item">
                        <i class="fas fa-users-cog"></i>
                        <span>User Management</span>
                    </a>
                    <div class="user-dropdown-divider"></div>
                    <a href="javascript:;" class="user-dropdown-item" onclick="showSwitchModal()">
                        <i class="fas fa-exchange-alt text-info"></i>
                        <span>Switch to Client</span>
                    </a>
                    <div class="user-dropdown-divider"></div>
                    <a href="javascript:;" class="user-dropdown-item text-danger" onclick="document.getElementById('logout-form').submit();">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="owner-main">
        <div class="container-fluid py-4">
            @yield('content')
        </div>
        
        <!-- Footer -->
        <footer class="footer py-3">
            <div class="container-fluid">
                <div class="text-center text-sm text-muted">
                    © {{ date('Y') }} {{ $__brandName ?? 'Talkabiz' }} - Owner Panel
                </div>
            </div>
        </footer>
    </main>

    <!-- Switch to Client Modal -->
    <div class="modal fade" id="switchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h6 class="modal-title">
                        <i class="fas fa-exchange-alt text-info me-2"></i>
                        Switch to Client View?
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Anda akan berpindah ke tampilan <strong>Client Dashboard</strong>.</p>
                    <p class="mb-2 text-muted">Mode Owner akan ditinggalkan.</p>
                    <div class="alert alert-info py-2 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Anda dapat kembali ke Owner Panel kapan saja.</small>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Batal
                    </button>
                    <a href="{{ route('dashboard') }}" class="btn btn-info btn-sm">
                        <i class="fas fa-check me-1"></i> Ya, Pindah
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Form -->
    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
        @csrf
    </form>

    <!-- Toast Container -->
    <div class="toast-container">
        @if(session('success'))
        <div class="toast-alert success" id="toast-success">
            <i class="fas fa-check-circle"></i>
            <span>{{ session('success') }}</span>
        </div>
        @endif
        @if(session('error'))
        <div class="toast-alert error" id="toast-error">
            <i class="fas fa-exclamation-circle"></i>
            <span>{{ session('error') }}</span>
        </div>
        @endif
        @if(session('warning'))
        <div class="toast-alert warning" id="toast-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <span>{{ session('warning') }}</span>
        </div>
        @endif
    </div>

    <!-- Core JS -->
    <script src="{{ asset('assets/js/core/popper.min.js') }}"></script>
    <script src="{{ asset('assets/js/core/bootstrap.min.js') }}"></script>
    <script src="{{ asset('assets/js/soft-ui-dashboard.min.js?v=1.0.3') }}"></script>
    
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- OwnerPopup Helper - Standardized Popup for Owner Panel -->
    <script>
        /**
         * OwnerPopup - Enterprise-grade popup helper for Owner Panel
         * Uses SweetAlert2 as the ONLY popup system
         * Konsisten dengan UI Talkabiz Owner Panel
         */
        const OwnerPopup = {
            // Default config for Owner Panel styling
            defaultConfig: {
                customClass: {
                    popup: 'owner-popup',
                    confirmButton: 'btn px-4 py-2',
                    cancelButton: 'btn btn-secondary px-4 py-2',
                    actions: 'gap-2'
                },
                buttonsStyling: false,
                reverseButtons: true,
                focusCancel: true,
                allowOutsideClick: false
            },

            /**
             * Confirm danger action (disconnect, delete, reset, cancel, force)
             * @param {Object} options - { title, text, confirmText, onConfirm, onCancel }
             */
            async confirmDanger(options) {
                const result = await Swal.fire({
                    ...this.defaultConfig,
                    title: options.title || 'Konfirmasi Aksi',
                    html: options.text || 'Anda yakin ingin melanjutkan?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: options.confirmText || 'Ya, Lanjutkan',
                    cancelButtonText: options.cancelText || 'Batal',
                    customClass: {
                        ...this.defaultConfig.customClass,
                        confirmButton: 'btn btn-danger px-4 py-2'
                    }
                });

                if (result.isConfirmed) {
                    if (typeof options.onConfirm === 'function') {
                        return await options.onConfirm();
                    }
                    return true;
                }
                if (typeof options.onCancel === 'function') {
                    options.onCancel();
                }
                return false;
            },

            /**
             * Confirm warning action (less dangerous)
             */
            async confirmWarning(options) {
                const result = await Swal.fire({
                    ...this.defaultConfig,
                    title: options.title || 'Konfirmasi',
                    html: options.text || 'Anda yakin?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: options.confirmText || 'Ya, Lanjutkan',
                    cancelButtonText: options.cancelText || 'Batal',
                    customClass: {
                        ...this.defaultConfig.customClass,
                        confirmButton: 'btn btn-warning text-dark px-4 py-2'
                    }
                });

                if (result.isConfirmed) {
                    if (typeof options.onConfirm === 'function') {
                        return await options.onConfirm();
                    }
                    return true;
                }
                return false;
            },

            /**
             * Show success message (with optional auto-close)
             */
            success(message, title = 'Berhasil!', autoClose = false) {
                return Swal.fire({
                    ...this.defaultConfig,
                    icon: 'success',
                    title: title,
                    html: message,
                    showCancelButton: false,
                    confirmButtonText: 'OK',
                    timer: autoClose ? 1500 : undefined,
                    timerProgressBar: autoClose,
                    showConfirmButton: !autoClose,
                    customClass: {
                        ...this.defaultConfig.customClass,
                        confirmButton: 'btn btn-success px-4 py-2'
                    },
                    allowOutsideClick: true
                });
            },

            /**
             * Show success message with auto-close (1.5 seconds)
             * Use this for quick confirmations
             */
            successAutoClose(message, title = 'Berhasil!') {
                return Swal.fire({
                    icon: 'success',
                    title: title,
                    text: message,
                    timer: 1500,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    allowOutsideClick: true
                });
            },

            /**
             * Show error message
             */
            error(message, title = 'Error!') {
                return Swal.fire({
                    ...this.defaultConfig,
                    icon: 'error',
                    title: title,
                    html: message,
                    showCancelButton: false,
                    confirmButtonText: 'OK',
                    customClass: {
                        ...this.defaultConfig.customClass,
                        confirmButton: 'btn btn-danger px-4 py-2'
                    },
                    allowOutsideClick: true
                });
            },

            /**
             * Show info message
             */
            info(message, title = 'Info') {
                return Swal.fire({
                    ...this.defaultConfig,
                    icon: 'info',
                    title: title,
                    html: message,
                    showCancelButton: false,
                    confirmButtonText: 'OK',
                    customClass: {
                        ...this.defaultConfig.customClass,
                        confirmButton: 'btn btn-primary px-4 py-2'
                    },
                    allowOutsideClick: true
                });
            },

            /**
             * Show loading overlay
             */
            loading(message = 'Memproses...') {
                return Swal.fire({
                    title: message,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            },

            /**
             * Close any open popup
             */
            close() {
                Swal.close();
            },

            /**
             * Toast notification (auto-close 2 seconds)
             */
            toast(message, type = 'success') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer);
                        toast.addEventListener('mouseleave', Swal.resumeTimer);
                    }
                });
                return Toast.fire({
                    icon: type,
                    title: message
                });
            }
        };

        // Make it globally available
        window.OwnerPopup = OwnerPopup;
    </script>
    
    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('ownerSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const navbarHamburger = document.getElementById('navbarHamburger');
        const sidebarClose = document.getElementById('sidebarClose');
        
        function openSidebar() {
            sidebar.classList.add('show');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }
        
        navbarHamburger?.addEventListener('click', openSidebar);
        sidebarClose?.addEventListener('click', closeSidebar);
        sidebarOverlay?.addEventListener('click', closeSidebar);
        
        // Close sidebar when clicking nav link on mobile
        document.querySelectorAll('.sidebar-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 1200) {
                    closeSidebar();
                }
            });
        });
        
        // User Dropdown
        const userDropdown = document.getElementById('userDropdown');
        
        function toggleUserDropdown() {
            userDropdown.classList.toggle('show');
        }
        
        document.addEventListener('click', (e) => {
            if (!userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
        
        // Switch Modal
        function showSwitchModal() {
            const modal = new bootstrap.Modal(document.getElementById('switchModal'));
            modal.show();
        }
        
        // Auto-hide toasts
        setTimeout(() => {
            document.querySelectorAll('.toast-alert').forEach(toast => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            });
        }, 5000);
    </script>
    
    @stack('scripts')
</body>
</html>
