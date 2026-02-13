  <!-- -------- START FOOTER 3 w/ COMPANY DESCRIPTION WITH LINKS & SOCIAL ICONS & COPYRIGHT ------- -->
  <footer class="footer py-5">
    <div class="container">
      <div class="row">
      <div class="col-lg-8 mb-4 mx-auto text-center">
          <a href="{{ url('/') }}" class="text-secondary me-xl-5 me-3 mb-sm-0 mb-2">
              Beranda
          </a>
          <a href="{{ url('/register') }}" class="text-secondary me-xl-5 me-3 mb-sm-0 mb-2">
              Daftar
          </a>
          <a href="{{ url('/login') }}" class="text-secondary me-xl-5 me-3 mb-sm-0 mb-2">
              Masuk
          </a>
      </div>

      </div>
      @if (!auth()->user() || \Request::is('static-sign-up')) 
        <div class="row">
          <div class="col-8 mx-auto text-center mt-1">
            <p class="mb-0 text-secondary">
              Copyright © <script>
                document.write(new Date().getFullYear())
              </script>
              <a style="color: #252f40;" href="{{ url('/') }}" class="font-weight-bold ml-1">{{ $__brandName ?? 'Talkabiz' }}</a>.
              {{ $__brandTagline ?? 'Platform WhatsApp Marketing untuk Bisnis Indonesia' }}.
            </p>
          </div>
        </div>
      @endif
    </div>
  </footer>
  <!-- -------- END FOOTER 3 w/ COMPANY DESCRIPTION WITH LINKS & SOCIAL ICONS & COPYRIGHT ------- -->
