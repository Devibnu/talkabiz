@extends('layouts.user_type.auth')

@section('content')
<style>
/* Billing Page - Professional UI */
.billing-header {
    margin-bottom: 1.5rem;
}
.billing-header h4 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}
.billing-header p {
    font-size: 0.875rem;
    color: #67748e;
    margin: 0.25rem 0 0 0;
}

.saldo-card {
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.2s ease;
}
.saldo-card:hover {
    transform: translateY(-2px);
}
.saldo-card .card-body {
    padding: 24px;
}

.bank-option {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
}
.bank-option:hover, .bank-option.active {
    border-color: #cb0c9f;
    background: rgba(203, 12, 159, 0.05);
}
.bank-option input {
    display: none;
}

.nominal-btn {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 12px 20px;
    background: #fff;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 600;
    text-align: center;
}
.nominal-btn:hover, .nominal-btn.active {
    border-color: #cb0c9f;
    background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%);
    color: #fff;
}
.nominal-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.transaksi-item {
    padding: 12px 0;
    border-bottom: 1px solid #e9ecef;
}
.transaksi-item:last-child {
    border-bottom: none;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 600;
}
.status-pending { background: #fef4e5; color: #f5a623; }
.status-success, .status-paid { background: #e6f9f0; color: #2dce89; }
.status-failed, .status-expired { background: #fde8e8; color: #f5365c; }

.gateway-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
}
.gateway-badge.production {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}
.gateway-badge.sandbox {
    background: linear-gradient(310deg, #f5a623 0%, #f56036 100%);
    color: #fff;
}
.gateway-badge.inactive {
    background: #e9ecef;
    color: #67748e;
}

.empty-state {
    padding: 40px 20px;
    text-align: center;
}
.empty-icon {
    opacity: 0.5;
}

.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    background: #5e72e4;
    color: #fff;
}
</style>

{{-- Page Header --}}
<div class="billing-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <h4><i class="ni ni-credit-card me-2"></i>Billing & Saldo</h4>
            <p>Kelola saldo dan riwayat transaksi WhatsApp</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            {{-- Role Badge --}}
            <span class="role-badge">
                <i class="ni ni-single-02 me-1"></i>
                {{ ucfirst(auth()->user()->role ?? 'user') }}
            </span>
            {{-- Gateway Status Badge --}}
            @if(isset($activeGateway) && $activeGateway)
                <span class="gateway-badge {{ $activeGateway->isProduction() ? 'production' : 'sandbox' }}">
                    <i class="ni ni-{{ $activeGateway->isMidtrans() ? 'credit-card' : 'money-coins' }}"></i>
                    {{ $activeGateway->display_name }} ({{ $activeGateway->getEnvironmentLabel() }})
                </span>
            @else
                <span class="gateway-badge inactive">
                    <i class="ni ni-fat-remove"></i>
                    Gateway Tidak Aktif
                </span>
            @endif
        </div>
    </div>
</div>

{{-- Usage Summary Card (Limit Warnings) --}}
@if(isset($limitData) && is_array($limitData))
    @include('components.usage-summary-card', ['saldo' => $saldo ?? null, 'limitData' => $limitData])
    
    {{-- Limit Warnings --}}
    @include('components.limit-warning', [
        'daily' => $limitData['daily'] ?? [],
        'monthly' => $limitData['monthly'] ?? []
    ])
@endif

<div class="row">
    <div class="col-lg-8">
        <!-- Saldo Cards -->
        <div class="row mb-4">
            <div class="col-md-6 mb-3 mb-md-0">
                <div class="card saldo-card bg-gradient-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-white text-sm mb-1">Saldo WhatsApp</p>
                                {{-- HARDENING: Show "-" if saldo is null, not "Rp 0" --}}
                                <h3 class="text-white font-weight-bolder mb-0">
                                    @if(isset($saldo) && $saldo !== null)
                                        Rp {{ number_format($saldo, 0, ',', '.') }}
                                    @else
                                        <span class="opacity-8">-</span>
                                    @endif
                                </h3>
                                <p class="text-white text-xs opacity-8 mt-2 mb-0">
                                    @if(isset($dompet) && $dompet)
                                        Status: <strong>{{ ucfirst($dompet->status_saldo ?? 'normal') }}</strong>
                                    @else
                                        <i class="ni ni-time-alarm me-1"></i>Wallet tidak tersedia
                                    @endif
                                </p>
                            </div>
                            <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                                <i class="ni ni-credit-card text-primary text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card saldo-card bg-gradient-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="text-white text-sm mb-1">Pemakaian Bulan Ini</p>
                                {{-- HARDENING: Show "-" if pemakaian is null --}}
                                <h3 class="text-white font-weight-bolder mb-0">
                                    @if(isset($pemakaianBulanIni) && $pemakaianBulanIni !== null)
                                        Rp {{ number_format($pemakaianBulanIni, 0, ',', '.') }}
                                    @else
                                        <span class="opacity-8">-</span>
                                    @endif
                                </h3>
                                <p class="text-white text-xs opacity-8 mt-2 mb-0">
                                    @php
                                        $estimasiPesan = isset($pemakaianBulanIni) && $pemakaianBulanIni !== null 
                                            ? ($pemakaianBulanIni / $hargaPerPesan) 
                                            : null;
                                    @endphp
                                    @if($estimasiPesan !== null)
                                        ≈ {{ number_format($estimasiPesan, 0) }} pesan @ Rp {{ number_format($hargaPerPesan, 0, ',', '.') }}/pesan
                                    @else
                                        Data tidak tersedia
                                    @endif
                                </p>
                            </div>
                            <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                                <i class="ni ni-send text-success text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Saldo Info Bar -->
        @if(isset($dompet) && $dompet)
        <div class="card mb-4">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col-md-4 text-center border-end">
                        <p class="text-xs text-secondary mb-0">Total Top Up</p>
                        <p class="font-weight-bold mb-0 text-success">Rp {{ number_format($dompet->total_topup ?? 0, 0, ',', '.') }}</p>
                    </div>
                    <div class="col-md-4 text-center border-end">
                        <p class="text-xs text-secondary mb-0">Total Terpakai</p>
                        <p class="font-weight-bold mb-0 text-danger">Rp {{ number_format($dompet->total_terpakai ?? 0, 0, ',', '.') }}</p>
                    </div>
                    <div class="col-md-4 text-center">
                        <p class="text-xs text-secondary mb-0">Estimasi Pesan</p>
                        <p class="font-weight-bold mb-0 text-primary">
                            @php
                                $estimasiSisa = ($saldo ?? 0) / $hargaPerPesan;
                            @endphp
                            {{ number_format($estimasiSisa, 0) }} pesan tersisa
                        </p>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Role Check: owner, admin, umkm can top up --}}
        @php
            $canTopUp = in_array(auth()->user()->role, ['owner', 'admin', 'umkm']);
        @endphp

        <!-- Top Up Card -->
        <div class="card mb-4">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6>Top Up Saldo</h6>
                        @if(!$canTopUp)
                            <p class="text-sm text-warning mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                Role Anda tidak memiliki akses top up. Hubungi Owner.
                            </p>
                        @elseif(isset($activeGateway) && $activeGateway)
                            <p class="text-sm text-secondary mb-0">
                                Bayar via {{ $activeGateway->display_name }}
                            </p>
                        @else
                            <p class="text-sm text-danger mb-0">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Payment gateway belum dikonfigurasi
                            </p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="card-body">
                {{-- Gateway Warning - Only show if no active gateway --}}
                @if(!isset($activeGateway) || !$activeGateway || (isset($gatewayReady) && !$gatewayReady))
                <div class="alert alert-warning mb-4">
                    <div class="d-flex align-items-start">
                        <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md me-3" style="width:42px;height:42px;min-width:42px;">
                            <i class="ni ni-bell-55 text-white" style="font-size:1rem;line-height:42px;"></i>
                        </div>
                        <div>
                            <h6 class="text-dark mb-1">Payment Gateway Tidak Aktif</h6>
                            <p class="text-sm mb-2">
                                @if(isset($gatewayValidation) && isset($gatewayValidation['message']))
                                    {{ $gatewayValidation['message'] }}
                                @else
                                    Tidak ada payment gateway yang aktif. Hubungi administrator.
                                @endif
                            </p>
                            @if(auth()->user()->role === 'super_admin')
                            <a href="{{ url('settings/payment-gateway') }}" class="btn btn-sm bg-gradient-primary mb-0">
                                <i class="ni ni-settings-gear-65 me-1"></i> Aktifkan Gateway
                            </a>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
                
                <form id="formTopUp">
                    @csrf
                    <!-- Nominal Selection -->
                    <div class="mb-4">
                        <label class="form-label font-weight-bold">Pilih Nominal Top Up</label>
                        <div class="row g-2">
                            @php
                                $nominals = [50000, 100000, 250000, 500000, 1000000, 2500000];
                                $disabledClass = (!$canTopUp || !isset($gatewayReady) || !$gatewayReady) ? 'disabled' : '';
                            @endphp
                            @foreach($nominals as $nominal)
                            <div class="col-4 col-md-3">
                                <div class="nominal-btn {{ $disabledClass }}" onclick="pilihNominal(this, {{ $nominal }})">
                                    Rp {{ number_format($nominal, 0, ',', '.') }}
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <input type="hidden" name="nominal" id="nominalInput" value="">
                    </div>

                    <!-- Custom Nominal -->
                    <div class="mb-4">
                        <label class="form-label font-weight-bold">Atau Masukkan Nominal Lain</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number" class="form-control {{ $disabledClass }}" id="customNominal" placeholder="Min. 10.000" min="10000" max="100000000" onchange="pilihNominalCustom(this)" {{ (!$canTopUp || !isset($gatewayReady) || !$gatewayReady) ? 'disabled' : '' }}>
                        </div>
                    </div>

                    <!-- Selected Amount Display -->
                    <div class="alert alert-info mb-4" id="selectedAmountDisplay" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Nominal yang dipilih:</span>
                            <strong class="text-lg" id="displaySelectedAmount">Rp 0</strong>
                        </div>
                    </div>

                    <!-- Payment Methods Info -->
                    <div class="mb-4 p-3 bg-gray-100 border-radius-lg">
                        <p class="text-sm font-weight-bold mb-2">Metode Pembayaran Tersedia:</p>
                        <div class="d-flex flex-wrap gap-2">
                            @if(isset($activeGateway) && $activeGateway && $activeGateway->isMidtrans())
                                <span class="badge bg-light text-dark">💳 Kartu Kredit</span>
                                <span class="badge bg-light text-dark">🏦 Transfer Bank</span>
                                <span class="badge bg-light text-dark">📱 GoPay</span>
                                <span class="badge bg-light text-dark">🛒 ShopeePay</span>
                                <span class="badge bg-light text-dark">📲 QRIS</span>
                            @elseif(isset($activeGateway) && $activeGateway && $activeGateway->isXendit())
                                <span class="badge bg-light text-dark">🏦 Virtual Account</span>
                                <span class="badge bg-light text-dark">💳 Kartu Kredit</span>
                                <span class="badge bg-light text-dark">📱 OVO</span>
                                <span class="badge bg-light text-dark">🛒 DANA</span>
                                <span class="badge bg-light text-dark">📲 QRIS</span>
                            @else
                                <span class="badge bg-secondary text-white">Tidak ada metode tersedia</span>
                            @endif
                        </div>
                        <p class="text-xs text-secondary mt-2 mb-0">Pilih metode pembayaran setelah klik tombol di bawah</p>
                    </div>

                    {{-- Button based on role and gateway status --}}
                    @if(!$canTopUp)
                    <button type="button" class="btn bg-gradient-warning w-100 py-3" disabled>
                        <i class="fas fa-lock me-2"></i>Tidak Diizinkan Top Up
                    </button>
                    <p class="text-xs text-center text-warning mt-2">Role Anda tidak memiliki akses top up. Hubungi Owner.</p>
                    @elseif(isset($gatewayReady) && $gatewayReady)
                    <button type="submit" class="btn bg-gradient-primary w-100 py-3" id="btnTopUp" disabled>
                        <i class="ni ni-credit-card me-2"></i>Bayar Sekarang
                    </button>
                    @else
                    <button type="button" class="btn bg-gradient-secondary w-100 py-3" disabled>
                        <i class="fas fa-ban me-2"></i>Gateway Tidak Aktif
                    </button>
                    @endif
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Riwayat Transaksi -->
        <div class="card">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Riwayat Transaksi</h6>
                    @if(isset($transaksi) && $transaksi->count() > 0)
                        <span class="badge bg-light text-dark">{{ $transaksi->count() }} transaksi</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @if(isset($transaksi) && $transaksi->count() > 0)
                    @foreach($transaksi as $trx)
                    <div class="transaksi-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="font-weight-bold text-sm mb-1">{{ $trx->keterangan }}</p>
                                <p class="text-xs text-secondary mb-0">{{ $trx->created_at->format('d M Y H:i') }}</p>
                            </div>
                            <div class="text-end">
                                <p class="font-weight-bold text-sm mb-1 {{ $trx->jenis == 'topup' ? 'text-success' : 'text-danger' }}">
                                    {{ $trx->jenis == 'topup' ? '+' : '' }}Rp {{ number_format($trx->nominal, 0, ',', '.') }}
                                </p>
                                @php
                                    $status = $trx->status_topup ?? ($trx->jenis === 'debit' ? 'success' : 'pending');
                                @endphp
                                <span class="status-badge status-{{ $status }}">{{ ucfirst($status) }}</span>
                            </div>
                        </div>
                    </div>
                    @endforeach
                @else
                <div class="empty-state text-center py-5">
                    <div class="empty-icon mb-3">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" fill="#e0e0e0"/>
                        </svg>
                    </div>
                    <h6 class="text-secondary mb-2">Belum Ada Transaksi</h6>
                    <p class="text-xs text-muted mb-0">Mulai dengan melakukan top up saldo pertama Anda</p>
                </div>
                @endif
            </div>
        </div>
        
        <!-- Info Card -->
        <div class="card mt-3">
            <div class="card-body py-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md me-3" style="width:42px;height:42px;">
                        <i class="ni ni-bell-55 text-white" style="font-size:1rem;line-height:42px;"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary mb-0">Harga per pesan</p>
                        <p class="font-weight-bold text-sm mb-0">Rp {{ number_format($hargaPerPesan, 0, ',', '.') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Hasil Pembayaran -->
<div class="modal fade" id="paymentResultModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div id="paymentResultIcon"></div>
                <h4 class="mt-3" id="paymentResultTitle"></h4>
                <p class="text-secondary" id="paymentResultMessage"></p>
                <button type="button" class="btn bg-gradient-primary mt-3" onclick="location.reload()">OK</button>
            </div>
        </div>
    </div>
</div>

{{-- Load Midtrans Snap JS only if Midtrans is active --}}
@if(isset($activeGateway) && $activeGateway && $activeGateway->isMidtrans() && $midtransClientKey)
<script src="{{ $midtransSnapUrl ?? 'https://app.sandbox.midtrans.com/snap/snap.js' }}" data-client-key="{{ $midtransClientKey }}"></script>
@endif

<script>
let selectedNominal = 0;

function formatRupiah(num) {
    return 'Rp ' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function pilihNominal(el, nominal) {
    document.querySelectorAll('.nominal-btn').forEach(btn => btn.classList.remove('active'));
    el.classList.add('active');
    selectedNominal = nominal;
    document.getElementById('nominalInput').value = nominal;
    document.getElementById('customNominal').value = '';
    updateSelectedDisplay();
    updateButtonState();
}

function pilihNominalCustom(el) {
    const nominal = parseInt(el.value) || 0;
    if (nominal >= 10000) {
        document.querySelectorAll('.nominal-btn').forEach(btn => btn.classList.remove('active'));
        selectedNominal = nominal;
        document.getElementById('nominalInput').value = nominal;
        updateSelectedDisplay();
        updateButtonState();
    }
}

function updateSelectedDisplay() {
    const display = document.getElementById('selectedAmountDisplay');
    const amountText = document.getElementById('displaySelectedAmount');
    if (selectedNominal > 0) {
        display.style.display = 'block';
        amountText.textContent = formatRupiah(selectedNominal);
    } else {
        display.style.display = 'none';
    }
}

function updateButtonState() {
    const btn = document.getElementById('btnTopUp');
    if (btn) {
        btn.disabled = !(selectedNominal >= 10000);
    }
}

function showPaymentResult(type, title, message) {
    const iconEl = document.getElementById('paymentResultIcon');
    const titleEl = document.getElementById('paymentResultTitle');
    const messageEl = document.getElementById('paymentResultMessage');
    
    if (type === 'success') {
        iconEl.innerHTML = '<div class="icon icon-shape bg-gradient-success shadow mx-auto" style="width:80px;height:80px;border-radius:50%;"><i class="ni ni-check-bold text-white" style="font-size:2rem;line-height:80px;"></i></div>';
    } else if (type === 'pending') {
        iconEl.innerHTML = '<div class="icon icon-shape bg-gradient-warning shadow mx-auto" style="width:80px;height:80px;border-radius:50%;"><i class="ni ni-time-alarm text-white" style="font-size:2rem;line-height:80px;"></i></div>';
    } else {
        iconEl.innerHTML = '<div class="icon icon-shape bg-gradient-danger shadow mx-auto" style="width:80px;height:80px;border-radius:50%;"><i class="ni ni-fat-remove text-white" style="font-size:2rem;line-height:80px;"></i></div>';
    }
    
    titleEl.textContent = title;
    messageEl.textContent = message;
    
    new bootstrap.Modal(document.getElementById('paymentResultModal')).show();
}

// Gateway configuration from server
const activeGateway = @json($gatewayName ?? null);
const gatewayReady = @json($gatewayReady ?? false);
const canTopUp = @json($canTopUp ?? false);

document.getElementById('formTopUp')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Role check
    if (!canTopUp) {
        showPaymentResult('error', 'Akses Ditolak', 'Anda tidak memiliki izin untuk melakukan top up. Hanya Owner atau Admin yang diizinkan.');
        return;
    }
    
    if (!gatewayReady) {
        showPaymentResult('error', 'Gateway Tidak Aktif', 'Payment gateway tidak aktif. Hubungi administrator.');
        return;
    }
    
    if (selectedNominal < 10000) {
        showPaymentResult('error', 'Nominal Tidak Valid', 'Minimal top up Rp 10.000');
        return;
    }
    
    const btn = document.getElementById('btnTopUp');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyiapkan pembayaran...';
    
    // Use unified top up endpoint (auto-routes to active gateway)
    fetch('/api/billing/topup', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            nominal: selectedNominal
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Check which gateway was used
            if (data.data.snap_token) {
                // Midtrans Snap flow
                handleMidtransPayment(data.data.snap_token, btn);
            } else if (data.data.invoice_url) {
                // Xendit redirect flow
                handleXenditPayment(data.data.invoice_url, btn);
            } else {
                showPaymentResult('error', 'Respons Gateway Error', 'Respons payment gateway tidak valid. Silakan coba lagi.');
                resetButton(btn);
            }
        } else {
            // HARDENING: Handle specific error codes with proper error toast
            let errorTitle = 'Pembayaran Gagal';
            let errorMsg = data.message || 'Terjadi kesalahan. Silakan coba lagi.';
            
            if (data.code === 'no_gateway') {
                errorTitle = 'Gateway Tidak Aktif';
                errorMsg = 'Tidak ada payment gateway aktif. Hubungi administrator.';
            } else if (data.code === 'payment_error') {
                errorTitle = 'Error Payment Gateway';
                errorMsg = 'Gagal menghubungi payment gateway. Silakan coba lagi.';
            } else if (data.errors) {
                errorTitle = 'Data Tidak Valid';
                errorMsg = Object.values(data.errors).flat().join(', ');
            }
            
            showPaymentResult('error', errorTitle, errorMsg);
            resetButton(btn);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showPaymentResult('error', 'Kesalahan Jaringan', 'Gagal menghubungi server. Periksa koneksi internet Anda.');
        resetButton(btn);
    });
});

function handleMidtransPayment(snapToken, btn) {
    if (typeof window.snap === 'undefined') {
        ClientPopup.actionFailed('Halaman pembayaran sedang disiapkan. Refresh halaman dan coba lagi ya.');
        resetButton(btn);
        return;
    }
    
    window.snap.pay(snapToken, {
        onSuccess: function(result) {
            console.log('Payment success:', result);
            showPaymentResult('success', 'Pembayaran Berhasil!', 'Saldo Anda akan segera bertambah.');
        },
        onPending: function(result) {
            console.log('Payment pending:', result);
            showPaymentResult('pending', 'Menunggu Pembayaran', 'Silakan selesaikan pembayaran Anda sesuai instruksi.');
        },
        onError: function(result) {
            console.log('Payment error:', result);
            showPaymentResult('error', 'Pembayaran Gagal', 'Terjadi kesalahan. Silakan coba lagi.');
        },
        onClose: function() {
            console.log('Snap popup closed');
            resetButton(btn);
        }
    });
}

function handleXenditPayment(invoiceUrl, btn) {
    // Show pending modal first
    showPaymentResult('pending', 'Mengalihkan ke Xendit...', 'Anda akan diarahkan ke halaman pembayaran Xendit.');
    
    // Redirect to Xendit invoice page
    setTimeout(function() {
        window.location.href = invoiceUrl;
    }, 1500);
}

function resetButton(btn) {
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ni ni-credit-card me-2"></i>Bayar Sekarang';
    }
}

// Handle URL params for return from Midtrans redirect
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get('status');
    
    if (status === 'finish') {
        showPaymentResult('success', 'Pembayaran Berhasil!', 'Saldo Anda akan segera bertambah.');
        // Clean URL
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (status === 'unfinish') {
        showPaymentResult('pending', 'Pembayaran Belum Selesai', 'Silakan selesaikan pembayaran Anda.');
        window.history.replaceState({}, document.title, window.location.pathname);
    } else if (status === 'error') {
        showPaymentResult('error', 'Pembayaran Gagal', 'Terjadi kesalahan saat memproses pembayaran.');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});
</script>
@endsection