<!DOCTYPE html>

@if (\Request::is('rtl'))
  <html dir="rtl" lang="ar">
@else
  <html lang="en">
@endif

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <link rel="apple-touch-icon" sizes="76x76" href="{{ $__brandFaviconUrl ?? asset('assets/img/apple-icon.png') }}">
  <link rel="icon" type="image/png" href="{{ $__brandFaviconUrl ?? asset('assets/img/favicon.png') }}">
  <title>{{ $__brandName ?? 'Talkabiz' }}</title>
  
  <!-- Fonts and icons -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="{{ asset('assets/css/nucleo-icons.css') }}" rel="stylesheet" />
  <link href="{{ asset('assets/css/nucleo-svg.css') }}" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <!-- CSS Files -->
  <link id="pagestyle" href="{{ asset('assets/css/soft-ui-dashboard.css') }}?v=1.0.3" rel="stylesheet" />
  <!-- Talkabiz Custom Styles -->
  <link href="{{ asset('assets/css/talkabiz-custom.css') }}?v=1.0.0" rel="stylesheet" />
  
  @stack('styles')
</head>

<body class="g-sidenav-show  bg-gray-100 {{ (\Request::is('rtl') ? 'rtl' : (Request::is('virtual-reality') ? 'virtual-reality' : '')) }} ">
  @auth
    {{-- ============ IMPERSONATION BANNER ============ --}}
    {{-- Shows when Owner is viewing as a Client --}}
    @if(auth()->user()->isImpersonating())
      @php
        $impersonationMeta = app(\App\Services\ClientContextService::class)->getImpersonationMeta();
      @endphp
      <div id="impersonation-banner" 
           style="position: fixed; top: 0; left: 0; right: 0; z-index: 9999; 
                  background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); 
                  color: white; padding: 8px 20px; text-align: center; 
                  font-size: 14px; font-weight: 600; box-shadow: 0 2px 10px rgba(0,0,0,0.2);">
        <div style="display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;">
          <span>
            <i class="fas fa-eye" style="margin-right: 6px;"></i>
            Mode Impersonasi — Melihat sebagai: 
            <strong>{{ $impersonationMeta['klien_nama'] ?? 'Unknown' }}</strong>
            (Klien #{{ $impersonationMeta['klien_id'] ?? '-' }})
          </span>
          <form action="{{ route('owner.impersonate.stop') }}" method="POST" style="display: inline;">
            @csrf
            <button type="submit" 
                    style="background: rgba(255,255,255,0.25); border: 2px solid white; color: white; 
                           padding: 4px 16px; border-radius: 20px; cursor: pointer; font-weight: 600;
                           font-size: 13px; transition: all 0.2s;">
              <i class="fas fa-times-circle" style="margin-right: 4px;"></i>
              Kembali ke Owner
            </button>
          </form>
        </div>
      </div>
      {{-- Push body down to account for fixed banner --}}
      <div style="height: 44px;"></div>
    @endif
    {{-- ============ END IMPERSONATION BANNER ============ --}}

    @yield('auth')
  @endauth
  @guest
    @yield('guest')
  @endguest

  @if(session()->has('success'))
    <div x-data="{ show: true}"
        x-init="setTimeout(() => show = false, 4000)"
        x-show="show"
        class="position-fixed bg-success rounded right-3 text-sm py-2 px-4">
      <p class="m-0">{{ session('success')}}</p>
    </div>
  @endif
  
  <!-- SweetAlert2 for Client Popups -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <!-- ClientPopup Helper - Friendly popups for Client View -->
  <script>
    /**
     * ClientPopup - User-friendly popup helper for Client View
     * Bahasa ramah, non-teknis, tidak menakutkan
     */
    const ClientPopup = {
        // Soft styling for client-friendly popups
        defaultConfig: {
            customClass: {
                popup: 'client-popup',
                confirmButton: 'btn btn-primary px-4 py-2',
                cancelButton: 'btn btn-outline-secondary px-4 py-2',
                actions: 'gap-2'
            },
            buttonsStyling: false,
            allowOutsideClick: true,
            showCloseButton: true
        },

        /**
         * Info popup - untuk informasi umum
         */
        info(message, title = 'Informasi') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                title: title,
                html: message,
                confirmButtonText: 'Mengerti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-info px-4 py-2'
                }
            });
        },

        /**
         * Success popup - dengan auto-close 2 detik
         */
        success(message, title = 'Berhasil!') {
            return Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false,
                allowOutsideClick: true
            });
        },

        /**
         * Warning popup - soft warning, tidak menakutkan
         */
        warning(message, title = 'Perhatian') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'warning',
                iconColor: '#f5a623',
                title: title,
                html: message,
                confirmButtonText: 'Oke, Saya Mengerti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-warning text-dark px-4 py-2'
                }
            });
        },

        /**
         * Error popup - friendly error, tidak teknis
         */
        error(message, title = 'Oops!') {
            // Clean up technical messages
            let cleanMessage = message;
            if (message && (message.includes('Error:') || message.includes('Exception') || message.includes('500'))) {
                cleanMessage = 'Ada kendala saat memproses permintaan Anda. Silakan coba lagi dalam beberapa saat.';
            }
            
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'error',
                iconColor: '#e74c3c',
                title: title,
                html: cleanMessage,
                confirmButtonText: 'Oke',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-danger px-4 py-2'
                }
            });
        },

        /**
         * Confirm popup - untuk konfirmasi aksi (soft, non-destructive)
         */
        async confirm(options) {
            const result = await Swal.fire({
                ...this.defaultConfig,
                icon: options.icon || 'question',
                iconColor: '#6c757d',
                title: options.title || 'Konfirmasi',
                html: options.text || 'Lanjutkan?',
                showCancelButton: true,
                confirmButtonText: options.confirmText || 'Ya, Lanjutkan',
                cancelButtonText: options.cancelText || 'Batal',
                reverseButtons: true,
                focusCancel: false,
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-primary px-4 py-2'
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
         * Loading overlay - untuk proses yang sedang berjalan
         */
        loading(message = 'Mohon tunggu sebentar...') {
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
         * Toast notification (auto-close 2 detik)
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
        },

        /**
         * Owner-only action popup - redirect ke halaman yang sesuai
         */
        ownerOnly(featureName, redirectUrl = null) {
            const config = {
                ...this.defaultConfig,
                icon: 'info',
                title: 'Fitur Khusus Admin',
                html: `<p>Fitur <strong>${featureName}</strong> hanya dapat diakses oleh administrator.</p>
                       <p class="text-muted small mt-2">Hubungi admin jika Anda memerlukan bantuan.</p>`,
                confirmButtonText: redirectUrl ? 'Kembali ke Beranda' : 'Mengerti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-primary px-4 py-2'
                }
            };

            return Swal.fire(config).then(() => {
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                }
            });
        },

        /**
         * Coming soon popup
         */
        comingSoon(featureName = 'Fitur ini') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#17a2b8',
                title: 'Segera Hadir! 🚀',
                html: `<p><strong>${featureName}</strong> sedang dalam pengembangan.</p>
                       <p class="text-muted small mt-2">Kami akan segera memberitahu Anda ketika sudah tersedia.</p>`,
                confirmButtonText: 'Oke, Ditunggu!',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-info px-4 py-2'
                }
            });
        },

        // ========================================
        // STANDARD MESSAGES - Copywriting Templates
        // ========================================

        /**
         * Fitur tidak tersedia di paket saat ini
         */
        featureNotAvailable(featureName = 'Fitur ini', upgradeUrl = '/billing/plan') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#6c63ff',
                title: 'Upgrade untuk Fitur Lebih! ✨',
                html: `
                    <p class="mb-2"><strong>${featureName}</strong> tersedia di paket yang lebih tinggi.</p>
                    <p class="text-muted small">Upgrade paket Anda untuk membuka fitur ini dan nikmati lebih banyak kemudahan.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Lihat Paket Upgrade',
                cancelButtonText: 'Nanti Saja',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-primary px-4 py-2'
                }
            }).then((result) => {
                if (result.isConfirmed && upgradeUrl) {
                    window.location.href = upgradeUrl;
                }
            });
        },

        /**
         * Limit pesan habis
         */
        limitExhausted(upgradeUrl = '/billing/plan') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'warning',
                iconColor: '#f5a623',
                title: 'Kuota Pesan Habis',
                html: `
                    <p class="mb-2">Kuota pesan bulan ini sudah terpakai semua.</p>
                    <p class="text-muted small">Upgrade paket atau tunggu reset kuota di awal bulan depan.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Upgrade Sekarang',
                cancelButtonText: 'Oke, Nanti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-warning text-dark px-4 py-2'
                }
            }).then((result) => {
                if (result.isConfirmed && upgradeUrl) {
                    window.location.href = upgradeUrl;
                }
            });
        },

        /**
         * Limit hampir habis (warning)
         */
        limitAlmostExhausted(remaining, total) {
            const percent = Math.round((remaining / total) * 100);
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#17a2b8',
                title: 'Kuota Hampir Habis 📊',
                html: `
                    <p class="mb-2">Sisa kuota pesan Anda: <strong>${remaining} dari ${total}</strong> (${percent}%)</p>
                    <p class="text-muted small">Pertimbangkan upgrade paket agar tidak kehabisan kuota di saat penting.</p>
                `,
                confirmButtonText: 'Mengerti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-info px-4 py-2'
                }
            });
        },

        /**
         * Aksi khusus owner/admin
         */
        ownerOnlyAction(actionName = 'Aksi ini') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#6c757d',
                title: 'Perlu Bantuan Admin',
                html: `
                    <p class="mb-2"><strong>${actionName}</strong> hanya dapat dilakukan oleh admin.</p>
                    <p class="text-muted small">Silakan hubungi admin Anda untuk bantuan lebih lanjut.</p>
                `,
                confirmButtonText: 'Mengerti',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-secondary px-4 py-2'
                }
            });
        },

        /**
         * Proses berhasil - dengan custom message
         */
        actionSuccess(message = 'Proses berhasil!', title = 'Berhasil! ✅') {
            return Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false,
                allowOutsideClick: true
            });
        },

        /**
         * Proses gagal - generic friendly message
         */
        actionFailed(customMessage = null) {
            const message = customMessage || 'Ada kendala saat memproses permintaan Anda. Silakan coba lagi dalam beberapa saat.';
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'warning',
                iconColor: '#e74c3c',
                title: 'Belum Berhasil',
                html: `
                    <p class="mb-2">${message}</p>
                    <p class="text-muted small">Jika masalah berlanjut, silakan hubungi support kami.</p>
                `,
                confirmButtonText: 'Coba Lagi',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-primary px-4 py-2'
                }
            });
        },

        /**
         * Koneksi terputus / network error
         */
        connectionError() {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'warning',
                iconColor: '#f5a623',
                title: 'Koneksi Terputus',
                html: `
                    <p class="mb-2">Sepertinya ada masalah dengan koneksi internet Anda.</p>
                    <p class="text-muted small">Periksa koneksi internet dan coba lagi.</p>
                `,
                confirmButtonText: 'Coba Lagi',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-primary px-4 py-2'
                }
            });
        },

        /**
         * WhatsApp belum terhubung
         */
        waNotConnected(connectUrl = '/whatsapp') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#25D366',
                title: 'Hubungkan WhatsApp Dulu 📱',
                html: `
                    <p class="mb-2">Untuk menggunakan fitur ini, Anda perlu menghubungkan WhatsApp terlebih dahulu.</p>
                    <p class="text-muted small">Prosesnya cepat dan mudah!</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Hubungkan Sekarang',
                cancelButtonText: 'Nanti Saja',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-success px-4 py-2'
                }
            }).then((result) => {
                if (result.isConfirmed && connectUrl) {
                    window.location.href = connectUrl;
                }
            });
        },

        /**
         * Sedang diproses - untuk background task
         */
        processing(message = 'Sedang diproses...') {
            return Swal.fire({
                ...this.defaultConfig,
                icon: 'info',
                iconColor: '#17a2b8',
                title: 'Sedang Diproses ⏳',
                html: `
                    <p class="mb-2">${message}</p>
                    <p class="text-muted small">Anda akan mendapat notifikasi ketika selesai.</p>
                `,
                confirmButtonText: 'Oke, Saya Tunggu',
                customClass: {
                    ...this.defaultConfig.customClass,
                    confirmButton: 'btn btn-info px-4 py-2'
                }
            });
        }
    };

    // Make globally available
    window.ClientPopup = ClientPopup;
  </script>

  <!-- LimitMonitor - Sistem Limit & Warning -->
  <script>
    /**
     * LimitMonitor - Monitor kuota pesan dan tampilkan warning sesuai threshold
     * 
     * Threshold:
     * - <=30% remaining → soft warning (info)
     * - <=10% remaining → strong warning (warning)
     * - 0% remaining → block action (limit exhausted)
     * 
     * Features:
     * - Cooldown untuk menghindari popup berulang
     * - Auto-fetch quota info dari backend
     * - Ramah dan tidak menakutkan
     */
    const LimitMonitor = {
        // Quota data cache
        quotaCache: null,
        lastFetchTime: 0,
        
        // Cooldown storage (in milliseconds)
        cooldowns: {
            softWarning: 0,     // Last soft warning shown
            strongWarning: 0,   // Last strong warning shown
            exhaustedWarning: 0 // Last exhausted warning shown
        },
        
        // Cooldown durations (avoid repeated popups)
        COOLDOWN_SOFT: 5 * 60 * 1000,      // 5 minutes for soft warning
        COOLDOWN_STRONG: 2 * 60 * 1000,    // 2 minutes for strong warning
        COOLDOWN_EXHAUSTED: 30 * 1000,     // 30 seconds for exhausted (must block)
        CACHE_TTL: 60 * 1000,              // 1 minute cache
        
        // Thresholds (percentage of remaining quota)
        THRESHOLD_SOFT: 30,    // <=30% remaining → soft warning
        THRESHOLD_STRONG: 10,  // <=10% remaining → strong warning
        THRESHOLD_BLOCK: 0,    // 0% remaining → block

        /**
         * Fetch quota info from backend
         * @returns {Promise<object>}
         */
        async fetchQuota() {
            const now = Date.now();
            
            // Use cache if still valid
            if (this.quotaCache && (now - this.lastFetchTime) < this.CACHE_TTL) {
                return this.quotaCache;
            }
            
            try {
                const response = await fetch('/api/billing/quota', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    }
                });
                
                if (!response.ok) {
                    throw new Error('Failed to fetch quota');
                }
                
                const data = await response.json();
                this.quotaCache = data;
                this.lastFetchTime = now;
                return data;
            } catch (error) {
                console.warn('LimitMonitor: Could not fetch quota', error);
                return null;
            }
        },

        /**
         * Check if cooldown is active for a warning type
         * @param {string} type - 'softWarning', 'strongWarning', 'exhaustedWarning'
         * @param {number} cooldownDuration - Cooldown duration in ms
         * @returns {boolean}
         */
        isCooldownActive(type, cooldownDuration) {
            const now = Date.now();
            return (now - this.cooldowns[type]) < cooldownDuration;
        },

        /**
         * Set cooldown for a warning type
         * @param {string} type 
         */
        setCooldown(type) {
            this.cooldowns[type] = Date.now();
        },

        /**
         * Calculate remaining percentage
         * @param {number} used 
         * @param {number} limit 
         * @returns {number}
         */
        getRemainingPercent(used, limit) {
            if (!limit || limit === 0 || limit === 'Unlimited') return 100;
            const remaining = Math.max(0, limit - used);
            return Math.round((remaining / limit) * 100);
        },

        /**
         * Check quota and show warning if needed
         * Returns: { canProceed: boolean, quota: object }
         * 
         * IMPORTANT: Warning does NOT block, only 0% blocks
         * 
         * @param {number} requestedAmount - Number of messages to send
         * @returns {Promise<{canProceed: boolean, quota: object|null}>}
         */
        async checkAndWarn(requestedAmount = 1) {
            const quota = await this.fetchQuota();
            
            if (!quota || !quota.has_plan) {
                // No plan - block action
                ClientPopup.warning(
                    'Anda belum memiliki paket aktif. Silakan pilih paket terlebih dahulu.',
                    'Paket Diperlukan'
                );
                return { canProceed: false, quota: null };
            }
            
            const monthly = quota.monthly || {};
            const used = monthly.used || 0;
            const limit = monthly.limit;
            const remaining = monthly.remaining || 0;
            
            // Unlimited plan
            if (limit === 'Unlimited' || limit === 0) {
                return { canProceed: true, quota };
            }
            
            const remainingPercent = this.getRemainingPercent(used, limit);
            
            // === BLOCK: 0% remaining or not enough for request ===
            if (remaining <= 0 || remaining < requestedAmount) {
                if (!this.isCooldownActive('exhaustedWarning', this.COOLDOWN_EXHAUSTED)) {
                    this.setCooldown('exhaustedWarning');
                    ClientPopup.limitExhausted('/billing/plan');
                }
                return { canProceed: false, quota };
            }
            
            // === STRONG WARNING: <=10% remaining ===
            if (remainingPercent <= this.THRESHOLD_STRONG) {
                if (!this.isCooldownActive('strongWarning', this.COOLDOWN_STRONG)) {
                    this.setCooldown('strongWarning');
                    await this.showStrongWarning(remaining, limit, remainingPercent);
                }
                // Warning shown but action proceeds
                return { canProceed: true, quota };
            }
            
            // === SOFT WARNING: <=30% remaining ===
            if (remainingPercent <= this.THRESHOLD_SOFT) {
                if (!this.isCooldownActive('softWarning', this.COOLDOWN_SOFT)) {
                    this.setCooldown('softWarning');
                    await this.showSoftWarning(remaining, limit, remainingPercent);
                }
                // Warning shown but action proceeds
                return { canProceed: true, quota };
            }
            
            // All good - no warning needed
            return { canProceed: true, quota };
        },

        /**
         * Show soft warning (<=30% remaining)
         * Non-blocking, just informational
         */
        async showSoftWarning(remaining, limit, percent) {
            return Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: `Kuota tersisa ${remaining} dari ${limit} pesan (${percent}%)`,
                text: 'Pertimbangkan upgrade paket',
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true
            });
        },

        /**
         * Show strong warning (<=10% remaining)
         * More prominent but still non-blocking
         */
        async showStrongWarning(remaining, limit, percent) {
            return Swal.fire({
                icon: 'warning',
                iconColor: '#f5a623',
                title: 'Kuota Hampir Habis ⚠️',
                html: `
                    <p class="mb-2">Sisa kuota pesan Anda: <strong>${remaining} dari ${limit}</strong> (${percent}%)</p>
                    <p class="text-muted small">Upgrade paket agar tidak terhenti di saat penting.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Upgrade Sekarang',
                cancelButtonText: 'Lanjutkan',
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn btn-warning text-dark px-4 py-2',
                    cancelButton: 'btn btn-outline-secondary px-4 py-2'
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '/billing/plan';
                }
            });
        },

        /**
         * Pre-check before batch send (e.g., campaign)
         * Use this before sending multiple messages
         * 
         * @param {number} messageCount - Number of messages to send
         * @returns {Promise<{canProceed: boolean, message: string|null}>}
         */
        async preCheckBatch(messageCount) {
            const quota = await this.fetchQuota();
            
            if (!quota || !quota.has_plan) {
                return {
                    canProceed: false,
                    message: 'Anda belum memiliki paket aktif.'
                };
            }
            
            const monthly = quota.monthly || {};
            const remaining = monthly.remaining || 0;
            const limit = monthly.limit;
            
            // Unlimited
            if (limit === 'Unlimited' || limit === 0) {
                return { canProceed: true, message: null };
            }
            
            // Not enough quota
            if (remaining < messageCount) {
                ClientPopup.warning(
                    `Kuota tidak mencukupi untuk mengirim ${messageCount} pesan. Sisa kuota: ${remaining} pesan.`,
                    'Kuota Tidak Cukup'
                );
                return {
                    canProceed: false,
                    message: `Kuota tidak mencukupi (tersedia: ${remaining}, dibutuhkan: ${messageCount})`
                };
            }
            
            // Check percentage for warnings
            const remainingPercent = this.getRemainingPercent(monthly.used, limit);
            const afterSendRemaining = remaining - messageCount;
            const afterSendPercent = Math.round((afterSendRemaining / limit) * 100);
            
            // Will drop to <=10% after send
            if (afterSendPercent <= this.THRESHOLD_STRONG && remainingPercent > this.THRESHOLD_STRONG) {
                const confirmed = await ClientPopup.confirm({
                    icon: 'warning',
                    title: 'Kuota Akan Menipis',
                    text: `Setelah mengirim ${messageCount} pesan, sisa kuota Anda akan menjadi ${afterSendRemaining} pesan (${afterSendPercent}%). Lanjutkan?`,
                    confirmText: 'Ya, Lanjutkan',
                    cancelText: 'Batal'
                });
                
                return { canProceed: confirmed, message: null };
            }
            
            return { canProceed: true, message: null };
        },

        /**
         * Get current quota info without warnings
         * @returns {Promise<object|null>}
         */
        async getQuota() {
            return await this.fetchQuota();
        },

        /**
         * Force refresh quota cache
         */
        async refreshQuota() {
            this.quotaCache = null;
            this.lastFetchTime = 0;
            return await this.fetchQuota();
        },

        /**
         * Clear cooldowns (for testing)
         */
        clearCooldowns() {
            this.cooldowns = {
                softWarning: 0,
                strongWarning: 0,
                exhaustedWarning: 0
            };
        }
    };

    // Make globally available
    window.LimitMonitor = LimitMonitor;
  </script>
  
    <!--   Core JS Files   -->
  <script src="{{ asset('assets/js/core/popper.min.js') }}"></script>
  <script src="{{ asset('assets/js/core/bootstrap.min.js') }}"></script>
  <script src="{{ asset('assets/js/plugins/perfect-scrollbar.min.js') }}"></script>
  <script src="{{ asset('assets/js/plugins/smooth-scrollbar.min.js') }}"></script>
  <script src="{{ asset('assets/js/plugins/fullcalendar.min.js') }}"></script>
  <script src="{{ asset('assets/js/plugins/chartjs.min.js') }}"></script>
  @stack('rtl')
  @stack('dashboard')
  <script>
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }
  </script>

  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="{{ asset('assets/js/soft-ui-dashboard.min.js') }}?v=1.0.3"></script>
  
  {{-- Page-specific scripts --}}
  @stack('scripts')
</body>

</html>
