@extends('layouts.user_type.auth')

@section('content')
<style>
/* Plan Page Styles */
.plan-header {
    margin-bottom: 2rem;
}
.plan-header h4 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}
.plan-header p {
    font-size: 0.95rem;
    color: #67748e;
    margin: 0.5rem 0 0 0;
}

/* Active Plan Card */
.active-plan-card {
    background: linear-gradient(135deg, #7928ca 0%, #ff0080 100%);
    border-radius: 16px;
    padding: 24px;
    color: white;
    margin-bottom: 2rem;
}
.active-plan-card .plan-name {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.active-plan-card .plan-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}
.quota-progress {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    height: 10px;
    margin: 1rem 0;
}
.quota-progress-bar {
    background: white;
    border-radius: 10px;
    height: 100%;
    transition: width 0.5s ease;
}
.quota-stats {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
}
.expires-info {
    margin-top: 1rem;
    font-size: 0.85rem;
    opacity: 0.9;
}

/* No Plan Card */
.no-plan-card {
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 16px;
    padding: 32px;
    text-align: center;
    margin-bottom: 2rem;
}
.no-plan-card i {
    font-size: 3rem;
    color: #6c757d;
    margin-bottom: 1rem;
}
.no-plan-card h5 {
    color: #344767;
    margin-bottom: 0.5rem;
}
.no-plan-card p {
    color: #67748e;
    margin-bottom: 0;
}

/* Plan Cards */
.plan-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}
.plan-card {
    background: white;
    border-radius: 16px;
    border: 2px solid #e9ecef;
    padding: 24px;
    position: relative;
    transition: all 0.3s ease;
}
.plan-card:hover {
    border-color: #cb0c9f;
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
}
.plan-card.highlighted {
    border-color: #cb0c9f;
    background: linear-gradient(135deg, rgba(203, 12, 159, 0.03) 0%, rgba(121, 40, 202, 0.03) 100%);
}
.plan-card .plan-badge {
    position: absolute;
    top: -10px;
    right: 16px;
    background: linear-gradient(135deg, #cb0c9f 0%, #7928ca 100%);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}
.plan-card .plan-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 0.5rem;
}
.plan-card .plan-price {
    font-size: 1.75rem;
    font-weight: 700;
    color: #cb0c9f;
    margin-bottom: 0.25rem;
}
.plan-card .plan-price small {
    font-size: 0.875rem;
    font-weight: 400;
    color: #67748e;
}
.plan-card .original-price {
    text-decoration: line-through;
    color: #adb5bd;
    font-size: 0.875rem;
}
.plan-card .plan-duration {
    font-size: 0.85rem;
    color: #67748e;
    margin-bottom: 1rem;
}
.plan-card .plan-quota {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    margin-bottom: 1rem;
}
.plan-card .plan-quota .quota-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: #344767;
}
.plan-card .plan-quota .quota-label {
    font-size: 0.75rem;
    color: #67748e;
    text-transform: uppercase;
}
.plan-features {
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
}
.plan-features li {
    padding: 0.5rem 0;
    font-size: 0.875rem;
    color: #67748e;
    display: flex;
    align-items: center;
}
.plan-features li i {
    color: #2dce89;
    margin-right: 0.5rem;
}
.btn-select-plan {
    width: 100%;
    padding: 12px;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
}
.btn-select-plan.primary {
    background: linear-gradient(135deg, #cb0c9f 0%, #7928ca 100%);
    border: none;
    color: white;
}
.btn-select-plan.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(203, 12, 159, 0.4);
}
.btn-select-plan.secondary {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    color: #344767;
}
.btn-select-plan.secondary:hover {
    border-color: #cb0c9f;
    color: #cb0c9f;
}

/* Pending Transactions */
.pending-card {
    background: #fff8e1;
    border: 1px solid #ffc107;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 1rem;
}
.pending-card .pending-title {
    font-weight: 600;
    color: #856404;
    margin-bottom: 0.5rem;
}
.pending-card .pending-amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
}

/* Modal */
.checkout-modal .modal-content {
    border-radius: 16px;
    border: none;
}
.checkout-modal .modal-header {
    border-bottom: 1px solid #f0f0f0;
    padding: 20px 24px;
}
.checkout-modal .modal-body {
    padding: 24px;
}
.checkout-summary {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 1.5rem;
}
.checkout-summary .plan-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
}
.checkout-summary .plan-details {
    font-size: 0.875rem;
    color: #67748e;
    margin-top: 0.5rem;
}
.checkout-price-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e9ecef;
}
.checkout-price-row.total {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
    border-bottom: none;
    margin-top: 0.5rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="plan-header">
        <h4><i class="fas fa-rocket me-2"></i>Paket WA Blast</h4>
        <p>Pilih paket yang sesuai dengan kebutuhan bisnis Anda</p>
    </div>

    <!-- Active Plan -->
    @if($activePlan)
    <div class="active-plan-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="plan-name">{{ $activePlan->plan?->name ?? 'Paket Aktif' }}</div>
                <span class="plan-badge">Aktif</span>
            </div>
            <div class="text-end">
                <div class="expires-info">
                    <i class="fas fa-calendar-alt me-1"></i>
                    Berakhir: {{ $activePlan->expires_at?->format('d M Y') ?? '-' }}
                </div>
            </div>
        </div>
        
        <div class="mt-3">
            <div class="quota-stats">
                <span>Kuota Terpakai</span>
                <span>{{ number_format($activePlan->quota_messages_used) }} / {{ number_format($activePlan->quota_messages_initial) }} pesan</span>
            </div>
            <div class="quota-progress">
                @php
                    $usagePercent = $activePlan->quota_messages_initial > 0 
                        ? min(100, ($activePlan->quota_messages_used / $activePlan->quota_messages_initial) * 100)
                        : 0;
                @endphp
                <div class="quota-progress-bar" style="width: {{ $usagePercent }}%"></div>
            </div>
            <div class="quota-stats">
                <span>Sisa: <strong>{{ number_format($activePlan->quota_messages_remaining) }}</strong> pesan</span>
                <span>{{ number_format(max(0, $activePlan->expires_at?->diffInDays(now()) ?? 0)) }} hari lagi</span>
            </div>
        </div>
    </div>
    @else
    <div class="no-plan-card">
        <i class="fas fa-box-open"></i>
        <h5>Belum Ada Paket Aktif</h5>
        <p>Pilih paket di bawah untuk mulai mengirim pesan WhatsApp</p>
    </div>
    @endif

    <!-- Pending Transactions -->
    @if($pendingTransactions->isNotEmpty())
    <div class="mb-4">
        <h6 class="text-muted mb-3"><i class="fas fa-clock me-2"></i>Menunggu Pembayaran</h6>
        @foreach($pendingTransactions as $pending)
        <div class="pending-card d-flex justify-content-between align-items-center">
            <div>
                <div class="pending-title">{{ $pending->plan?->name ?? 'Paket' }}</div>
                <small class="text-muted">{{ $pending->transaction_code }}</small>
            </div>
            <div class="text-end">
                <div class="pending-amount">Rp {{ number_format($pending->final_price, 0, ',', '.') }}</div>
                @if($pending->pg_redirect_url)
                <a href="{{ $pending->pg_redirect_url }}" target="_blank" class="btn btn-sm btn-warning mt-1">
                    Bayar Sekarang
                </a>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <!-- Available Plans -->
    <h6 class="text-muted mb-3"><i class="fas fa-th-large me-2"></i>Pilih Paket</h6>
    <div class="plan-grid">
        @foreach($plans as $plan)
        <div class="plan-card {{ $plan->highlight ? 'highlighted' : '' }}">
            @if($plan->badge)
            <span class="plan-badge">{{ $plan->badge }}</span>
            @endif
            
            <div class="plan-name">{{ $plan->name }}</div>
            
            <div class="plan-price">
                Rp {{ number_format($plan->effective_price, 0, ',', '.') }}
                <small>/ {{ $plan->duration_days }} hari</small>
            </div>
            @if($plan->price != $plan->effective_price)
            <div class="original-price">Rp {{ number_format($plan->price, 0, ',', '.') }}</div>
            @endif
            
            <div class="plan-duration">
                <i class="fas fa-calendar-alt me-1"></i> Masa aktif {{ $plan->duration_days }} hari
            </div>
            
            <div class="plan-quota">
                <div class="quota-number">{{ number_format($plan->quota_messages) }}</div>
                <div class="quota-label">Pesan WhatsApp</div>
            </div>
            
            <ul class="plan-features">
                @if($plan->quota_templates)
                <li><i class="fas fa-check"></i> {{ number_format($plan->quota_templates) }} Template</li>
                @endif
                @if($plan->quota_contacts)
                <li><i class="fas fa-check"></i> {{ number_format($plan->quota_contacts) }} Kontak</li>
                @endif
                @if(is_array($plan->features))
                    @foreach(array_slice($plan->features, 0, 4) as $feature => $enabled)
                        @if($enabled)
                        <li><i class="fas fa-check"></i> {{ ucwords(str_replace('_', ' ', $feature)) }}</li>
                        @endif
                    @endforeach
                @endif
            </ul>
            
            @if($activePlan && $activePlan->plan_id === $plan->id)
            <button class="btn btn-select-plan secondary" disabled>
                <i class="fas fa-check me-2"></i>Paket Aktif
            </button>
            @else
            <button class="btn btn-select-plan {{ $plan->highlight ? 'primary' : 'secondary' }}"
                    onclick="openCheckoutModal('{{ $plan->code }}', '{{ $plan->name }}', {{ $plan->effective_price }}, {{ $plan->duration_days }}, {{ $plan->quota_messages }})">
                Pilih Paket
            </button>
            @endif
        </div>
        @endforeach
    </div>

    <!-- Corporate Info -->
    <div class="card mt-4">
        <div class="card-body text-center">
            <h5 class="mb-2"><i class="fas fa-building me-2"></i>Butuh Paket Enterprise?</h5>
            <p class="text-muted mb-3">Untuk kebutuhan volume besar dan fitur khusus, hubungi tim sales kami.</p>
            @if($__brandWhatsappSalesUrl)
                <a href="{{ $__brandWhatsappSalesUrl . '?text=' . urlencode('Halo, saya tertarik dengan paket Enterprise') }}" target="_blank" class="btn btn-outline-primary">
                    <i class="fab fa-whatsapp me-2"></i>Hubungi Sales
                </a>
            @else
                <button class="btn btn-outline-secondary" disabled title="Nomor WhatsApp Sales belum dikonfigurasi">
                    <i class="fab fa-whatsapp me-2"></i>Hubungi Sales
                </button>
                <p class="text-danger mt-2 mb-0" style="font-size: 0.8rem;">
                    <i class="fas fa-exclamation-circle me-1"></i>Nomor WhatsApp Sales belum dikonfigurasi.
                </p>
            @endif
        </div>
    </div>
</div>

<!-- Checkout Modal -->
<div class="modal fade checkout-modal" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Checkout Paket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="checkout-summary">
                    <div class="plan-name" id="checkout-plan-name">-</div>
                    <div class="plan-details">
                        <i class="fas fa-envelope me-1"></i>
                        <span id="checkout-plan-quota">0</span> pesan | 
                        <i class="fas fa-calendar ms-2 me-1"></i>
                        <span id="checkout-plan-duration">0</span> hari
                    </div>
                </div>
                
                <div class="checkout-price-row">
                    <span>Harga Paket</span>
                    <span id="checkout-price">Rp 0</span>
                </div>
                <div class="checkout-price-row" id="promo-row" style="display: none;">
                    <span class="text-success">Diskon Promo</span>
                    <span class="text-success" id="checkout-promo-discount">- Rp 0</span>
                </div>
                <div class="checkout-price-row total">
                    <span>Total Bayar</span>
                    <span id="checkout-total">Rp 0</span>
                </div>
                
                <div class="mt-4">
                    <label class="form-label small">Kode Promo (Opsional)</label>
                    <input type="text" id="promo-code" class="form-control" placeholder="Masukkan kode promo">
                </div>
                
                <input type="hidden" id="checkout-plan-code" value="">
                
                <div class="d-grid gap-2 mt-4">
                    <button class="btn btn-lg btn-select-plan primary" onclick="processCheckout()" id="btn-checkout">
                        <i class="fas fa-credit-card me-2"></i>Bayar Sekarang
                    </button>
                </div>
                
                <p class="text-center text-muted small mt-3 mb-0">
                    <i class="fas fa-shield-alt me-1"></i>Pembayaran aman melalui Midtrans
                </p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ config('midtrans.snap_url') }}" data-client-key="{{ config('midtrans.client_key') }}"></script>
<script>
let currentPlanPrice = 0;

function openCheckoutModal(code, name, price, duration, quota) {
    document.getElementById('checkout-plan-code').value = code;
    document.getElementById('checkout-plan-name').textContent = name;
    document.getElementById('checkout-plan-quota').textContent = quota.toLocaleString();
    document.getElementById('checkout-plan-duration').textContent = duration;
    document.getElementById('checkout-price').textContent = 'Rp ' + price.toLocaleString();
    document.getElementById('checkout-total').textContent = 'Rp ' + price.toLocaleString();
    currentPlanPrice = price;
    
    // Reset promo
    document.getElementById('promo-code').value = '';
    document.getElementById('promo-row').style.display = 'none';
    
    new bootstrap.Modal(document.getElementById('checkoutModal')).show();
}

async function processCheckout() {
    const btn = document.getElementById('btn-checkout');
    const planCode = document.getElementById('checkout-plan-code').value;
    const promoCode = document.getElementById('promo-code').value;
    
    if (!planCode) {
        ClientPopup.info('Pilih paket terlebih dahulu', 'Pilih salah satu paket yang tersedia untuk melanjutkan.');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';
    
    try {
        const response = await fetch('{{ route("billing.plan.checkout") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                plan_code: planCode,
                promo_code: promoCode || null
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.data.snap_token) {
            // Open Midtrans Snap
            snap.pay(data.data.snap_token, {
                onSuccess: function(result) {
                    window.location.href = '{{ route("billing.plan.finish") }}?order_id=' + result.order_id + '&transaction_status=settlement';
                },
                onPending: function(result) {
                    window.location.href = '{{ route("billing.plan.unfinish") }}?order_id=' + result.order_id;
                },
                onError: function(result) {
                    window.location.href = '{{ route("billing.plan.error") }}?order_id=' + result.order_id;
                },
                onClose: function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
                }
            });
        } else {
            ClientPopup.actionFailed(data.message || 'Pembayaran sedang diproses. Coba lagi dalam beberapa saat.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
        }
    } catch (error) {
        console.error('Checkout error:', error);
        ClientPopup.connectionError();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
    }
}
</script>
@endpush
