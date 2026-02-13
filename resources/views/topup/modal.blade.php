{{-- Topup Modal - Quick & Clean UX --}}
<div class="modal fade" id="topupModal" tabindex="-1" aria-labelledby="topupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-2">
        <h5 class="modal-title font-weight-bolder" id="topupModalLabel">
          <i class="fas fa-wallet text-success me-2"></i>Topup Saldo WhatsApp
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <div class="modal-body pt-0">
        {{-- Current Balance Display --}}
        <div class="row mb-4">
          <div class="col-12">
            <div class="card bg-gradient-info">
              <div class="card-body p-3 text-white">
                <div class="row align-items-center">
                  <div class="col-8">
                    <div class="numbers">
                      <p class="text-white mb-0 text-sm font-weight-bold">Saldo Saat Ini</p>
                      <h4 class="font-weight-bolder text-white mb-0">
                        Rp {{ number_format($dompet->saldo_tersedia, 0, ',', '.') }}
                      </h4>
                      <p class="text-white mb-0 text-xs opacity-9">
                        ≈ {{ number_format(floor($dompet->saldo_tersedia / $pricePerMessage), 0, ',', '.') }} pesan tersisa
                      </p>
                    </div>
                  </div>
                  <div class="col-4 text-end">
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                      @if($dompet->status_saldo === 'habis')
                        <i class="fas fa-exclamation-triangle text-danger text-lg"></i>
                      @elseif($dompet->status_saldo === 'kritis')
                        <i class="fas fa-exclamation text-warning text-lg"></i>
                      @else
                        <i class="fas fa-check text-success text-lg"></i>
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
            <h6 class="font-weight-bolder mb-3">Pilih Nominal Topup</h6>
            <div class="row">
              @foreach($presetNominals as $index => $preset)
                <div class="col-md-6 col-lg-4 mb-3">
                  <div class="card preset-card h-100" style="cursor: pointer; border: 2px solid #e9ecef; transition: all 0.3s ease;">
                    <div class="card-body text-center p-3" data-nominal="{{ $preset['nominal'] }}">
                      <h6 class="font-weight-bolder mb-1 text-dark">{{ $preset['formatted'] }}</h6>
                      <p class="text-sm text-success mb-0">{{ $preset['description'] }}</p>
                    </div>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        </div>

        {{-- Custom Amount Input --}}
        <div class="row mb-4">
          <div class="col-12">
            <h6 class="font-weight-bolder mb-3">Atau Masukkan Nominal Custom</h6>
            <div class="form-group">
              <div class="input-group">
                <span class="input-group-text bg-gradient-light">Rp</span>
                <input type="number" 
                       class="form-control" 
                       id="customAmount"
                       placeholder="Masukkan nominal (min: 1.000)"
                       min="1000" 
                       max="10000000"
                       oninput="updateCustomEstimate()">
              </div>
              <div id="customEstimate" class="text-sm text-success mt-1" style="display: none;"></div>
              <div class="form-text text-xs">
                Minimal Rp 1.000 - Maksimal Rp 10.000.000
              </div>
            </div>
          </div>
        </div>

        {{-- Topup Notice --}}
        <div class="alert alert-info border-light">
          <div class="d-flex align-items-start">
            <i class="fas fa-info-circle text-info me-3 mt-1"></i>
            <div>
              <h6 class="font-weight-bolder text-dark mb-1">Informasi Penting</h6>
              <p class="text-sm text-dark mb-2">
                • Saldo akan digunakan untuk pengiriman pesan WhatsApp<br>
                • Tarif <strong>Rp {{ number_format($pricePerMessage, 0, ',', '.') }} per pesan</strong> sesuai Meta WhatsApp Business API<br>
                • Saldo akan otomatis bertambah setelah pembayaran berhasil
              </p>
              <p class="text-xs text-secondary mb-0">
                <i class="fas fa-shield-alt me-1"></i>
                Pembayaran aman menggunakan Midtrans Payment Gateway
              </p>
            </div>
          </div>
        </div>
      </div>
      
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn bg-gradient-success" id="processTopupBtn" disabled>
          <i class="fas fa-credit-card me-1"></i>Bayar & Tambah Saldo
        </button>
      </div>
    </div>
  </div>
</div>

{{-- Topup Modal JavaScript --}}
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
                c.style.borderColor = '#e9ecef';
                c.classList.remove('border-success');
            });
            
            // Select current card
            this.style.borderColor = '#2dce89';
            this.classList.add('border-success');
            
            // Clear custom input
            customAmountInput.value = '';
            customEstimate.style.display = 'none';
            
            // Set selected nominal
            selectedNominal = parseInt(this.querySelector('[data-nominal]').dataset.nominal);
            processBtn.disabled = false;
            
            updateProcessButton();
        });
    });
    
    // Handle custom amount input
    customAmountInput.addEventListener('input', function() {
        // Clear preset selection
        presetCards.forEach(c => {
            c.style.borderColor = '#e9ecef';
            c.classList.remove('border-success');
        });
        
        const value = parseInt(this.value);
        
        if (value >= 1000 && value <= 10000000) {
            selectedNominal = value;
            processBtn.disabled = false;
            updateProcessButton();
            updateCustomEstimate();
        } else {
            selectedNominal = 0;
            processBtn.disabled = true;
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
            const formatted = 'Rp ' + selectedNominal.toLocaleString('id-ID');
            processBtn.innerHTML = `<i class="fas fa-credit-card me-1"></i>Bayar ${formatted}`;
        }
    }
    
    // Process topup
    processBtn.addEventListener('click', function() {
        if (selectedNominal <= 0) return;
        
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Memproses...';
        
        // Get redirect URL if set
        const redirectAfter = document.getElementById('topupModal').dataset.redirectAfter || '';
        
        fetch('{{ route("topup.process") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                nominal: selectedNominal,
                redirect_after: redirectAfter
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

// Global function to update custom estimate (called from inline oninput)
function updateCustomEstimate() {
    const value = parseInt(document.getElementById('customAmount').value);
    const estimate = document.getElementById('customEstimate');
    
    if (value >= 1000) {
        const messageCount = Math.floor(value / {{ $pricePerMessage }});
        estimate.innerHTML = `≈ ${messageCount.toLocaleString('id-ID')} pesan`;
        estimate.style.display = 'block';
    } else {
        estimate.style.display = 'none';
    }
}
</script>