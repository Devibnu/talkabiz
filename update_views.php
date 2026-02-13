<?php
// Script untuk update views Talkabiz

// 1. Update Sidebar
$sidebar = <<<'BLADE'
<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3" id="sidenav-main">
  <div class="sidenav-header">
    <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
    <a class="align-items-center d-flex m-0 navbar-brand text-wrap" href="{{ route('dashboard') }}">
      <i class="fab fa-whatsapp text-success" style="font-size: 2rem;"></i>
      <span class="ms-3 font-weight-bold">Talkabiz</span>
    </a>
  </div>
  <hr class="horizontal dark mt-0">
  <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link {{ Request::is('dashboard') ? 'active' : '' }}" href="{{ url('dashboard') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-home {{ Request::is('dashboard') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link {{ Request::is('inbox*') ? 'active' : '' }}" href="{{ url('inbox') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-inbox {{ Request::is('inbox*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Inbox</span>
        </a>
      </li>
      @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin']))
      <li class="nav-item">
        <a class="nav-link {{ Request::is('campaign*') ? 'active' : '' }}" href="{{ url('campaign') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bullhorn {{ Request::is('campaign*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Campaign</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link {{ Request::is('template*') ? 'active' : '' }}" href="{{ url('template') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-file-alt {{ Request::is('template*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Template Pesan</span>
        </a>
      </li>
      @endif
      <li class="nav-item">
        <a class="nav-link {{ Request::is('kontak*') ? 'active' : '' }}" href="{{ url('kontak') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-users {{ Request::is('kontak*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Kontak / Audience</span>
        </a>
      </li>
      @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin']))
      <li class="nav-item">
        <a class="nav-link {{ Request::is('billing*') ? 'active' : '' }}" href="{{ url('billing') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-wallet {{ Request::is('billing*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Billing & Saldo</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link {{ Request::is('activity-log*') ? 'active' : '' }}" href="{{ url('activity-log') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-history {{ Request::is('activity-log*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Activity Log</span>
        </a>
      </li>
      @endif
      @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner']))
      <li class="nav-item">
        <a class="nav-link {{ Request::is('settings*') ? 'active' : '' }}" href="{{ url('settings') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-cog {{ Request::is('settings*') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Settings</span>
        </a>
      </li>
      @endif
      <li class="nav-item mt-3"><hr class="horizontal dark"></li>
      <li class="nav-item">
        <a class="nav-link {{ Request::is('user-profile') ? 'active' : '' }}" href="{{ url('user-profile') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-user {{ Request::is('user-profile') ? 'text-white' : 'text-dark' }}"></i>
          </div>
          <span class="nav-link-text ms-1">Profile</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="{{ url('logout') }}">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-sign-out-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Logout</span>
        </a>
      </li>
    </ul>
  </div>
</aside>
BLADE;

file_put_contents('resources/views/layouts/navbars/auth/sidebar.blade.php', $sidebar);
echo "✅ Sidebar updated\n";

// 2. Update Dashboard
$dashboard = <<<'BLADE'
@extends('layouts.user_type.auth')

@section('content')
<div class="row">
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Campaign</p>
              <h5 class="font-weight-bolder mb-0">0</h5>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
              <i class="fas fa-bullhorn text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Pesan Terkirim</p>
              <h5 class="font-weight-bolder mb-0">0</h5>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
              <i class="fas fa-check-circle text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Pesan Gagal</p>
              <h5 class="font-weight-bolder mb-0">0</h5>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
              <i class="fas fa-times-circle text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Saldo WhatsApp</p>
              <h5 class="font-weight-bolder mb-0">Rp 0</h5>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
              <i class="fas fa-wallet text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row mt-4">
  <div class="col-lg-8 mb-lg-0 mb-4">
    <div class="card">
      <div class="card-header pb-0">
        <h6>Selamat Datang di Talkabiz</h6>
      </div>
      <div class="card-body p-3">
        <p class="text-sm mb-0">
          Platform WhatsApp Campaign & Inbox untuk bisnis Anda. Kelola broadcast, template pesan, dan komunikasi pelanggan dalam satu tempat.
        </p>
        <div class="mt-4">
          <a href="{{ url('campaign') }}" class="btn btn-sm btn-primary">
            <i class="fas fa-bullhorn me-2"></i> Buat Campaign
          </a>
          <a href="{{ url('inbox') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-inbox me-2"></i> Buka Inbox
          </a>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header pb-0">
        <h6>Quick Stats</h6>
      </div>
      <div class="card-body p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm me-3 bg-gradient-info shadow text-center">
                <i class="fas fa-users text-white opacity-10"></i>
              </div>
              <span class="text-sm">Total Kontak</span>
            </div>
            <span class="text-sm font-weight-bold">0</span>
          </li>
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm me-3 bg-gradient-primary shadow text-center">
                <i class="fas fa-file-alt text-white opacity-10"></i>
              </div>
              <span class="text-sm">Template Aktif</span>
            </div>
            <span class="text-sm font-weight-bold">0</span>
          </li>
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm me-3 bg-gradient-success shadow text-center">
                <i class="fas fa-inbox text-white opacity-10"></i>
              </div>
              <span class="text-sm">Chat Aktif</span>
            </div>
            <span class="text-sm font-weight-bold">0</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>
@endsection
BLADE;

file_put_contents('resources/views/dashboard.blade.php', $dashboard);
echo "✅ Dashboard updated\n";

// 3. Create placeholder views
$pages = [
    'inbox' => 'Inbox',
    'campaign' => 'Campaign', 
    'template' => 'Template Pesan',
    'kontak' => 'Kontak / Audience',
    'activity-log' => 'Activity Log',
    'settings' => 'Settings'
];

foreach ($pages as $file => $title) {
    $content = <<<BLADE
@extends('layouts.user_type.auth')

@section('content')
<div class="row">
  <div class="col-12">
    <div class="card mb-4">
      <div class="card-header pb-0">
        <h6>$title</h6>
      </div>
      <div class="card-body">
        <div class="text-center py-5">
          <i class="fas fa-tools fa-3x text-secondary mb-3"></i>
          <h5>Halaman $title</h5>
          <p class="text-sm text-secondary">Fitur ini sedang dalam pengembangan.</p>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
BLADE;
    file_put_contents("resources/views/{$file}.blade.php", $content);
    echo "✅ Created {$file}.blade.php\n";
}

// 4. Update Layout Title
$layoutPath = 'resources/views/layouts/app.blade.php';
$layout = file_get_contents($layoutPath);
$layout = str_replace('Soft UI Dashboard by Creative Tim', 'Talkabiz', $layout);
file_put_contents($layoutPath, $layout);
echo "✅ Layout title updated\n";

echo "\n🎉 All views updated successfully!\n";
