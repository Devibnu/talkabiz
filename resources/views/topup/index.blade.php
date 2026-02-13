@extends('layouts.user_type.auth')

@section('content')
<style>
.topup-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
}
.preset-card {
    cursor: pointer;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}
.preset-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.preset-card.selected {
    border-color: #2dce89;
    box-shadow: 0 8px 25px rgba(45,206,137,0.2);
}
</style>

{{-- Hero Header --}}
<div class="row">
  <div class="col-12">
    <div class="topup-hero">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h3><i class="fas fa-wallet me-2"></i>Topup Saldo WhatsApp</h3>
          <p>Tambahkan saldo untuk pengiriman pesan WhatsApp</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-light btn-sm">
          <i class="fas fa-arrow-left me-1"></i>Kembali
        </a>
      </div>
    </div>
  </div>
</div>

{{-- Current Balance Display --}}
<div class="row mb-4">
  <div class="col-12">
    <div class="card bg-gradient-info">
      <div class="card-body p-4 text-white">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h4 class="text-white mb-1">Saldo Saat Ini</h4>
            <h2 class="font-weight-bolder text-white mb-2">
              Rp {{ number_format($dompet->saldo_tersedia, 0, ',', '.') }}
            </h2>
            <p class="text-white mb-0 opacity-9">
              ≈ {{ number_format(floor($dompet->saldo_tersedia / $pricePerMessage), 0, ',', '.') }} pesan tersisa
            </p>
          </div>
          <div class="col-md-4 text-end">
            <div class="icon icon-shape bg-white shadow text-center border-radius-lg" style="width: 80px; height: 80px;">
              @if($dompet->status_saldo === 'habis')
                <i class="fas fa-exclamation-triangle text-danger" style="font-size: 2rem; line-height: 80px;"></i>
              @elseif($dompet->status_saldo === 'kritis')
                <i class="fas fa-exclamation text-warning" style="font-size: 2rem; line-height: 80px;"></i>
              @else
                <i class="fas fa-check text-success" style="font-size: 2rem; line-height: 80px;"></i>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Preset Nominal Selection --}}
<div class="row mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header pb-0">
        <h5 class="font-weight-bolder">Pilih Nominal Topup</h5>
        <p class="text-sm text-secondary mb-0">Pilih nominal yang sesuai kebutuhan atau masukkan nominal custom</p>
      </div>
      <div class="card-body pt-3">
        <div class="row">
          @foreach($presetNominals as $index => $preset)
            <div class="col-md-6 col-lg-4 mb-3">
              <div class="card preset-card h-100" data-nominal="{{ $preset['nominal'] }}">
                <div class="card-body text-center p-3">
                  <h5 class="font-weight-bolder mb-1 text-dark">{{ $preset['formatted'] }}</h5>
                  <p class="text-sm text-success mb-2">{{ $preset['description'] }}</p>
                  <small class="text-xs text-secondary">Hemat & praktis</small>
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Custom Amount Section --}}
<div class="row mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header pb-0">
        <h5 class="font-weight-bolder">Nominal Custom</h5>
        <p class="text-sm text-secondary mb-0">Masukkan nominal sesuai kebutuhan spesifik Anda</p>
      </div>
      <div class="card-body pt-3">
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label class="form-control-label">Nominal (Rp)</label>
              <div class="input-group">
                <span class="input-group-text bg-gradient-light">Rp</span>
                <input type="number" 
                       class="form-control" 
                       id="customAmount"
                       placeholder="1.000"
                       min="1000" 
                       max="10000000"
                       oninput="updateCustomEstimate()">
              </div>
              <div id="customEstimate" class="text-sm text-success mt-2" style="display: none;"></div>
              <div class="form-text text-xs">
                Minimal Rp 1.000 - Maksimal Rp 10.000.000
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Process Section --}}
<div class="row">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body text-center p-4">
        <h5 class="font-weight-bolder mb-3">Siap untuk Topup?</h5>
        <p class="text-secondary mb-4">
          Pembayaran aman menggunakan Midtrans Payment Gateway. 
          Saldo akan otomatis bertambah setelah pembayaran berhasil.
        </p>
        
        <button type="button" 
                class="btn bg-gradient-success btn-lg px-5" 
                id="processTopupBtn" 
                disabled>
          <i class="fas fa-credit-card me-2"></i>Pilih Nominal Terlebih Dahulu
        </button>
        
        <div class="mt-3">
          <small class="text-xs text-secondary">
            <i class="fas fa-shield-alt me-1"></i>
            SSL Encrypted • Secure Payment • No Hidden Fees
          </small>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Pricing Information --}}
<div class="row mt-4">
  <div class="col-12">
    <div class="alert alert-info border-info">
      <div class="d-flex align-items-start">
        <i class="fas fa-info-circle text-info me-3 mt-1"></i>
        <div>
          <h6 class="font-weight-bolder text-dark mb-2">Informasi Tarif Pesan</h6>
          <p class="text-sm text-dark mb-2">
            • Tarif per pesan: <strong>Rp {{ number_format($pricePerMessage, 0, ',', '.') }}</strong><br>
            • Tarif mengikuti harga resmi Meta WhatsApp Business API<br>
            • Tidak ada biaya tersembunyi atau markup tambahan
          </p>
          <p class="text-xs text-secondary mb-0">
            Tarif dapat berubah sewaktu-waktu mengikuti kebijakan Meta. 
            Perubahan akan diinformasikan terlebih dahulu.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const presetCards = document.querySelectorAll('.preset-card');
    const customAmountInput = document.getElementById('customAmount');
    const processBtn = document.getElementById('processTopupBtn');
    const customEstimate = document.getElementById('customEstimate');
    const pricePerMessage = {{ $pricePerMessage }};
    
    let selectedNominal = 0;
    
    // Handle preset card selection
    presetCards.forEach(card => {
        card.addEventListener('click', function() {
            // Remove previous selection
            presetCards.forEach(c => {
                c.classList.remove('selected');
            });
            
            // Select current card
            this.classList.add('selected');
            
            // Clear custom input
            customAmountInput.value = '';
            customEstimate.style.display = 'none';
            
            // Set selected nominal
            selectedNominal = parseInt(this.dataset.nominal);
            updateProcessButton();
        });
    });
    
    // Handle custom amount input
    customAmountInput.addEventListener('input', function() {
        // Clear preset selection
        presetCards.forEach(c => {
            c.classList.remove('selected');
        });
        
        const value = parseInt(this.value);
        
        if (value >= 1000 && value <= 10000000) {
            selectedNominal = value;
            updateProcessButton();
            updateCustomEstimate();
        } else {
            selectedNominal = 0;
            processBtn.disabled = true;
            processBtn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Pilih Nominal Terlebih Dahulu';
            customEstimate.style.display = 'none';
        }
    });
    
    function updateCustomEstimate() {
        const value = parseInt(customAmountInput.value);
        if (value >= 1000) {
            const messageCount = Math.floor(value / pricePerMessage);
            customEstimate.innerHTML = `≈ ${messageCount.toLocaleString('id-ID')} pesan`;
            customEstimate.style.display = 'block';
        } else {
            customEstimate.style.display = 'none';
        }
    }
    
    function updateProcessButton() {
        if (selectedNominal > 0) {
            processBtn.disabled = false;
            const formatted = 'Rp ' + selectedNominal.toLocaleString('id-ID');
            processBtn.innerHTML = `<i class="fas fa-credit-card me-2"></i>Bayar ${formatted}`;
        }
    }
    
    // Process topup
    processBtn.addEventListener('click', function() {
        if (selectedNominal <= 0) return;
        
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';
        
        fetch('{{ route("topup.process") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                nominal: selectedNominal
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Redirect to payment gateway
                window.location.href = data.payment_url;
            } else {
                throw new Error(data.message || 'Gagal memproses topup');
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
            this.disabled = false;
            updateProcessButton();
        });
    });
});
</script>
@endpush