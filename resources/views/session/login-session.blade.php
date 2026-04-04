@extends('layouts.user_type.guest')

@section('content')

  <section class="min-vh-100 mb-8">
    <div class="page-header align-items-start min-vh-50 pt-5 pb-11 mx-3 border-radius-lg" style="background-image: url('../assets/img/curved-images/curved14.jpg');">
      <span class="mask bg-gradient-dark opacity-6"></span>
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-lg-5 text-center mx-auto">
            <h1 class="text-white mb-2 mt-5">Selamat Datang Kembali!</h1>
            <p class="text-lead text-white">Masuk ke akun {{ $__brandName ?? 'Talkabiz' }} Anda untuk melanjutkan mengelola WhatsApp Campaign.</p>
          </div>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="row mt-lg-n10 mt-md-n11 mt-n10">
        <div class="col-xl-4 col-lg-5 col-md-7 mx-auto">
          <div class="card z-index-0">
            <div class="card-header text-center pt-4">
              <h5>Masuk ke {{ $__brandName ?? 'Talkabiz' }}</h5>
            </div>

            {{-- ==================== SUCCESS MESSAGE ==================== --}}
            @if(session('success'))
              <div class="mx-4 mb-0">
                <div class="alert alert-success text-white text-sm py-2 px-3 mb-0" role="alert">
                  <i class="fas fa-check-circle me-1"></i> {{ session('success') }}
                </div>
              </div>
            @endif

            @if(session('info'))
              <div class="mx-4 mb-0">
                <div class="alert alert-info text-white text-sm py-2 px-3 mb-0" role="alert">
                  <i class="fas fa-info-circle me-1"></i> {{ session('info') }}
                </div>
              </div>
            @endif

            {{-- ==================== ACCOUNT LOCKED PANEL ==================== --}}
            @if(session('account_locked'))
              <div class="mx-4 mt-3 mb-0" id="lockPanel">
                <div class="alert alert-danger py-3 px-3 mb-0" role="alert" style="background: linear-gradient(135deg, #f5365c 0%, #f56036 100%); border: none;">
                  <div class="text-center text-white">
                    <i class="fas fa-lock fa-2x mb-2"></i>
                    <h6 class="text-white mb-1">Akun Terkunci</h6>
                    <p class="text-white text-xs mb-2">
                      Akun Anda dikunci hingga <strong>{{ session('locked_until') }}</strong>
                    </p>
                    <div class="d-flex align-items-center justify-content-center mb-2">
                      <div class="bg-white rounded-3 px-3 py-2 text-center" style="min-width: 100px;">
                        <span id="countdownTimer" class="text-danger fw-bold fs-5" data-seconds="{{ session('seconds_remaining', 0) }}">
                          {{ gmdate('i:s', session('seconds_remaining', 0)) }}
                        </span>
                        <br>
                        <small class="text-muted text-xxs">tersisa</small>
                      </div>
                    </div>
                    <p class="text-white text-xs mb-2">Tunggu sampai timer habis, atau buka kunci via email:</p>
                    <form method="POST" action="{{ url('/account/unlock/request') }}" id="unlockForm">
                      @csrf
                      <input type="hidden" name="email" value="{{ session('locked_email', old('email')) }}">
                      <button type="submit" class="btn btn-sm btn-white text-danger fw-bold px-4" id="btnUnlock">
                        <i class="fas fa-envelope me-1"></i> Buka Sekarang
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            @endif

            {{-- ==================== UNLOCK EMAIL SENT ==================== --}}
            @if(session('unlock_sent'))
              <div class="mx-4 mt-3 mb-0">
                <div class="alert alert-success text-white text-sm py-2 px-3 mb-0" role="alert">
                  <i class="fas fa-envelope-open me-1"></i> Link buka kunci telah dikirim ke email Anda. Cek inbox (dan folder spam).
                </div>
              </div>
            @endif

            {{-- ==================== CAPTCHA WARNING ==================== --}}
            @if(session('show_captcha'))
              <div class="mx-4 mt-3 mb-0">
                <div class="alert alert-warning text-dark text-sm py-2 px-3 mb-0" role="alert">
                  <i class="fas fa-exclamation-triangle me-1"></i>
                  Terlalu banyak percobaan gagal ({{ session('failed_attempts', '?') }}x).
                  Periksa kembali email dan password Anda.
                </div>
              </div>
            @endif

            <div class="row px-xl-5 px-sm-4 px-3">
              <div class="col-xl-8 col-12 mx-auto px-1">
                <a class="btn border border-secondary w-100 d-flex align-items-center justify-content-center gap-2 shadow-none" href="{{ route('auth.google') }}" style="background:#fff;">
                  <svg width="20px" height="20px" viewBox="0 0 64 64" version="1.1" xmlns="http://www.w3.org/2000/svg">
                    <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                      <g transform="translate(3.000000, 2.000000)" fill-rule="nonzero">
                        <path d="M57.8123233,30.1515267 C57.8123233,27.7263183 57.6155321,25.9565533 57.1896408,24.1212666 L29.4960833,24.1212666 L29.4960833,35.0674653 L45.7515771,35.0674653 C45.4239683,37.7877475 43.6542033,41.8844383 39.7213169,44.6372555 L39.6661883,45.0037254 L48.4223791,51.7870338 L49.0290201,51.8475849 C54.6004021,46.7020943 57.8123233,39.1313952 57.8123233,30.1515267" fill="#4285F4"></path>
                        <path d="M29.4960833,58.9921667 C37.4599129,58.9921667 44.1456164,56.3701671 49.0290201,51.8475849 L39.7213169,44.6372555 C37.2305867,46.3742596 33.887622,47.5868638 29.4960833,47.5868638 C21.6960582,47.5868638 15.0758763,42.4415991 12.7159637,35.3297782 L12.3700541,35.3591501 L3.26524241,42.4054492 L3.14617358,42.736447 C7.9965904,52.3717589 17.959737,58.9921667 29.4960833,58.9921667" fill="#34A853"></path>
                        <path d="M12.7159637,35.3297782 C12.0932812,33.4944915 11.7329116,31.5279353 11.7329116,29.4960833 C11.7329116,27.4640054 12.0932812,25.4976752 12.6832029,23.6623884 L12.6667095,23.2715173 L3.44779955,16.1120237 L3.14617358,16.2554937 C1.14708246,20.2539019 0,24.7439491 0,29.4960833 C0,34.2482175 1.14708246,38.7380388 3.14617358,42.736447 L12.7159637,35.3297782" fill="#FBBC05"></path>
                        <path d="M29.4960833,11.4050769 C35.0347044,11.4050769 38.7707997,13.7975244 40.9011602,15.7968415 L49.2255853,7.66898166 C44.1130815,2.91684746 37.4599129,0 29.4960833,0 C17.959737,0 7.9965904,6.62018183 3.14617358,16.2554937 L12.6832029,23.6623884 C15.0758763,16.5505675 21.6960582,11.4050769 29.4960833,11.4050769" fill="#EB4335"></path>
                      </g>
                    </g>
                  </svg>
                  <span class="text-sm text-dark">Masuk dengan Google</span>
                </a>
              </div>
              <div class="mt-2 position-relative text-center">
                <p class="text-sm font-weight-bold mb-2 text-secondary text-border d-inline z-index-2 bg-white px-3">
                  atau
                </p>
              </div>
            </div>
            <div class="card-body">
              <form role="form" method="POST" action="{{ route('login.store') }}" id="loginForm">
                @csrf
                <div class="mb-3">
                  <input type="email" class="form-control @error('email') is-invalid @enderror" placeholder="Email Bisnis" name="email" id="email" aria-label="Email" aria-describedby="email-addon" value="{{ old('email') }}" required autofocus>
                  @error('email')
                    <p class="text-danger text-xs mt-2">{{ $message }}</p>
                  @enderror
                </div>
                <div class="mb-3">
                  <input type="password" class="form-control @error('password') is-invalid @enderror" placeholder="Password" name="password" id="password" aria-label="Password" aria-describedby="password-addon" required>
                  @error('password')
                    <p class="text-danger text-xs mt-2">{{ $message }}</p>
                  @enderror
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="remember" id="rememberMe">
                  <label class="form-check-label" for="rememberMe">Ingat saya</label>
                </div>
                <div class="text-center">
                  <button type="submit" class="btn bg-gradient-dark w-100 my-4 mb-2" id="btnLogin"
                    @if(session('account_locked')) disabled @endif>
                    Masuk
                  </button>
                </div>
                <p class="text-sm mt-3 mb-0 text-center">Lupa password? <a href="/login/forgot-password" class="text-dark font-weight-bolder">Reset di sini</a></p>
                <p class="text-sm mt-3 mb-0 text-center">Belum punya akun? <a href="/register" class="text-dark font-weight-bolder">Daftar</a></p>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
  // ==========================================
  // COUNTDOWN TIMER (when account is locked)
  // ==========================================
  const timerEl = document.getElementById('countdownTimer');
  if (timerEl) {
    let seconds = parseInt(timerEl.dataset.seconds) || 0;
    const btnLogin = document.getElementById('btnLogin');
    const lockPanel = document.getElementById('lockPanel');

    function updateTimer() {
      if (seconds <= 0) {
        // Timer expired → auto-unlock: enable login, hide lock panel
        if (btnLogin) btnLogin.disabled = false;
        if (lockPanel) {
          lockPanel.innerHTML = '<div class="alert alert-success text-white text-sm py-2 px-3 mb-0">' +
            '<i class="fas fa-lock-open me-1"></i> Kunci sudah habis! Silakan login kembali.</div>';
        }
        return;
      }

      const mins = Math.floor(seconds / 60);
      const secs = seconds % 60;
      timerEl.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
      seconds--;
      setTimeout(updateTimer, 1000);
    }

    updateTimer();
  }

  // ==========================================
  // UNLOCK BUTTON — prevent double-submit
  // ==========================================
  const unlockForm = document.getElementById('unlockForm');
  if (unlockForm) {
    unlockForm.addEventListener('submit', function() {
      const btn = document.getElementById('btnUnlock');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Mengirim...';
      }
    });
  }
});
</script>
@endpush
