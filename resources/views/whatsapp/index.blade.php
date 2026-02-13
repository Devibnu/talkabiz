@extends('layouts.user_type.auth')

@section('content')
<div class="row">
    <div class="col-12">
        {{-- Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">📱 Nomor WhatsApp</h4>
                <p class="text-sm text-secondary mb-0">
                    Hubungkan nomor WhatsApp Business untuk mulai kirim campaign
                </p>
            </div>
        </div>

        {{-- Anti-Ban Warning Card --}}
        <div class="card mb-4 border-start border-warning border-4">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="icon icon-shape bg-gradient-warning text-center border-radius-md">
                            <i class="fas fa-shield-alt text-lg text-white opacity-10"></i>
                        </div>
                    </div>
                    <div class="col">
                        <h6 class="mb-1">⚠️ Penting: Hindari Banned WhatsApp</h6>
                        <p class="text-sm text-secondary mb-0">
                            Gunakan <strong>nomor khusus bisnis</strong>, jangan nomor pribadi. 
                            Kirim pesan secara <strong>bertahap</strong> dan pastikan kontak sudah <strong>opt-in</strong> 
                            (setuju menerima pesan dari Anda). 
                            <a href="{{ route('panduan') }}" class="text-primary">Baca panduan lengkap →</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Connection Status Card --}}
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">Status Koneksi WhatsApp</h6>
            </div>
            <div class="card-body">
                @if($connectionStatus['connected'])
                    {{-- Connected State --}}
                    <div class="connection-status-connected">
                        <div class="d-flex align-items-center mb-4">
                            <div class="status-icon-lg bg-gradient-success rounded-circle me-3">
                                <i class="fab fa-whatsapp text-white"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 text-success">
                                    <i class="fas fa-check-circle me-2"></i>Terhubung
                                </h5>
                                <p class="text-sm text-secondary mb-0">
                                    Nomor: <strong>{{ $connectionStatus['phone_display'] ?? 'N/A' }}</strong>
                                </p>
                                <p class="text-xs text-muted mb-0">
                                    Terhubung sejak: {{ $connectionStatus['connected_at'] ?? '-' }}
                                </p>
                            </div>
                        </div>

                        <div class="alert alert-soft-success mb-4">
                            <i class="fas fa-rocket me-2"></i>
                            <strong>Siap kirim!</strong> Nomor WhatsApp Anda sudah terhubung dan siap untuk mengirim campaign.
                        </div>

                        <div class="d-flex gap-3">
                            <a href="{{ route('campaign') }}" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i>Buat Campaign
                            </a>
                            <button type="button" class="btn btn-outline-danger" id="disconnectBtn">
                                <i class="fas fa-unlink me-2"></i>Disconnect
                            </button>
                        </div>
                    </div>
                @else
                    {{-- Not Connected State --}}
                    <div class="connection-status-disconnected">
                        <div class="text-center py-4">
                            <div class="status-icon-xl bg-gradient-secondary rounded-circle mx-auto mb-4">
                                <i class="fab fa-whatsapp text-white"></i>
                            </div>
                            <h5 class="text-secondary mb-2">Belum Ada Nomor Terhubung</h5>
                            <p class="text-sm text-muted mb-4">
                                Hubungkan nomor WhatsApp Business Anda untuk mulai kirim campaign
                            </p>

                            <button type="button" class="btn btn-lg btn-success" id="btn-connect-wa">
                                <i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp
                            </button>
                        </div>

                        {{-- QR Code Area (hidden by default) --}}
                        <div id="qrCodeArea" class="d-none mt-4">
                            <hr>
                            <div class="text-center">
                                <h6 class="mb-3">Scan QR Code dengan WhatsApp</h6>
                                
                                <div id="qrCodeContainer" class="qr-code-wrapper mx-auto mb-3">
                                    <div id="qrCodeLoading" class="qr-code-loading">
                                        <div class="spinner-border text-success" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="text-sm text-muted mt-2">Generating QR Code...</p>
                                    </div>
                                    <div id="qrCodeImage" class="d-none">
                                        {{-- QR Code will be rendered here --}}
                                    </div>
                                </div>

                                <div class="qr-instructions mb-3">
                                    <p class="text-sm mb-2"><strong>Cara scan:</strong></p>
                                    <ol class="text-start text-sm text-muted mx-auto" style="max-width: 400px;">
                                        <li>Buka WhatsApp di HP Anda</li>
                                        <li>Ketuk <strong>Menu (⋮)</strong> atau <strong>Settings</strong></li>
                                        <li>Ketuk <strong>Linked Devices</strong></li>
                                        <li>Ketuk <strong>Link a Device</strong></li>
                                        <li>Arahkan kamera ke QR code di atas</li>
                                    </ol>
                                </div>

                                <div id="qrExpiry" class="text-muted text-xs mb-3">
                                    <i class="fas fa-clock me-1"></i>
                                    QR Code expired dalam: <span id="expiryCountdown">5:00</span>
                                </div>

                                <div id="connectionPending" class="d-none">
                                    <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                                    <span class="text-sm">Menunggu koneksi...</span>
                                </div>

                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-refresh-qr">
                                    <i class="fas fa-sync-alt me-1"></i>Generate QR Baru
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Help Section --}}
        <div class="card mt-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-question-circle me-2 text-info"></i>FAQ - Pertanyaan Umum
                </h6>
            </div>
            <div class="card-body">
                <div class="accordion" id="waFaqAccordion">
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent" type="button" 
                                    data-bs-toggle="collapse" data-bs-target="#faq1">
                                Nomor apa yang bisa digunakan?
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#waFaqAccordion">
                            <div class="accordion-body text-sm">
                                Gunakan <strong>nomor WhatsApp Business</strong> yang sudah terdaftar. 
                                Kami sangat menyarankan menggunakan nomor khusus bisnis, 
                                <span class="text-danger">bukan nomor pribadi</span>, untuk menghindari risiko banned.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border-0">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent" type="button" 
                                    data-bs-toggle="collapse" data-bs-target="#faq2">
                                Apakah nomor saya aman?
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#waFaqAccordion">
                            <div class="accordion-body text-sm">
                                Kami <strong>tidak menyimpan</strong> password atau akses penuh ke akun WhatsApp Anda.
                                Koneksi menggunakan <strong>WhatsApp Business API resmi</strong> dari Meta.
                                Anda bisa disconnect kapan saja.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border-0">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent" type="button" 
                                    data-bs-toggle="collapse" data-bs-target="#faq3">
                                Kenapa tidak bisa langsung kirim campaign?
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#waFaqAccordion">
                            <div class="accordion-body text-sm">
                                Untuk <strong>keamanan akun</strong>, Anda harus menghubungkan nomor WhatsApp sendiri 
                                sebelum bisa kirim campaign. Ini memastikan hanya Anda yang mengontrol pengiriman pesan 
                                dan mencegah penyalahgunaan.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item border-0">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent" type="button" 
                                    data-bs-toggle="collapse" data-bs-target="#faq4">
                                Bagaimana cara menghindari banned?
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#waFaqAccordion">
                            <div class="accordion-body text-sm">
                                <ul class="mb-0">
                                    <li>Gunakan <strong>nomor khusus bisnis</strong></li>
                                    <li>Pastikan penerima sudah <strong>opt-in</strong> (setuju menerima pesan)</li>
                                    <li>Jangan kirim <strong>spam</strong> atau pesan tidak diinginkan</li>
                                    <li>Kirim secara <strong>bertahap</strong>, jangan massal sekaligus</li>
                                    <li><a href="{{ route('panduan') }}">Baca panduan lengkap</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Disconnect Confirmation Modal --}}
<div class="modal fade" id="disconnectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Disconnect WhatsApp?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Anda yakin ingin melepas koneksi nomor WhatsApp ini?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>
                        Setelah disconnect, Anda <strong>tidak bisa kirim campaign</strong> 
                        sampai menghubungkan nomor baru.
                    </small>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmDisconnect">
                    <i class="fas fa-unlink me-2"></i>Ya, Disconnect
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
.status-icon-lg {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.status-icon-xl {
    width: 100px;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
}

.qr-code-wrapper {
    width: 280px;
    height: 280px;
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
}

.qr-code-wrapper canvas,
.qr-code-wrapper img {
    max-width: 100%;
    max-height: 100%;
}

.alert-soft-success {
    background-color: rgba(45, 206, 137, 0.1);
    border: 1px solid rgba(45, 206, 137, 0.3);
    color: #1a8754;
}

.accordion-button:not(.collapsed) {
    background-color: transparent;
    color: inherit;
    box-shadow: none;
}

.accordion-button:focus {
    box-shadow: none;
    border-color: rgba(0,0,0,.125);
}

.border-4 {
    border-width: 4px !important;
}
</style>
@endpush

@push('scripts')
{{-- No external QR library needed - QR generated server-side --}}
<script>
(function() {
    'use strict';
    
    console.log('[WA Connect] Script loaded (server-side QR)');

    // Wait for DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[WA Connect] DOM Ready');
        initWhatsAppConnect();
    });

    function initWhatsAppConnect() {
        // Elements
        const connectBtn = document.getElementById('btn-connect-wa');
        const disconnectBtn = document.getElementById('disconnectBtn');
        const confirmDisconnect = document.getElementById('confirmDisconnect');
        const qrCodeArea = document.getElementById('qrCodeArea');
        const qrCodeImage = document.getElementById('qrCodeImage');
        const qrCodeLoading = document.getElementById('qrCodeLoading');
        const refreshQrBtn = document.getElementById('btn-refresh-qr');
        const connectionPending = document.getElementById('connectionPending');
        const expiryCountdown = document.getElementById('expiryCountdown');
        
        // State
        let pollInterval = null;
        let countdownInterval = null;
        let currentSessionId = null;

        // Debug element availability
        console.log('[WA Connect] Elements:', {
            connectBtn: !!connectBtn,
            disconnectBtn: !!disconnectBtn,
            qrCodeArea: !!qrCodeArea,
            qrCodeImage: !!qrCodeImage,
            refreshQrBtn: !!refreshQrBtn
        });

        // Connect button handler
        if (connectBtn) {
            console.log('[WA Connect] Binding click handler to connect button');
            connectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[WA Connect] Connect button clicked!');
                initiateConnection();
            });
            
            // Also add onclick as fallback
            connectBtn.onclick = function(e) {
                e.preventDefault();
                console.log('[WA Connect] Connect button onclick fired!');
                initiateConnection();
            };
        } else {
            console.warn('[WA Connect] Connect button not found - user may already be connected');
        }

        // Refresh QR button handler
        if (refreshQrBtn) {
            refreshQrBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[WA Connect] Refresh QR clicked');
                initiateConnection();
            });
        }

        // Disconnect button handler
        if (disconnectBtn) {
            disconnectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[WA Connect] Disconnect button clicked');
                const modal = new bootstrap.Modal(document.getElementById('disconnectModal'));
                modal.show();
            });
        }

        // Confirm disconnect handler
        if (confirmDisconnect) {
            confirmDisconnect.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[WA Connect] Confirm disconnect clicked');
                performDisconnect();
            });
        }

        /**
         * Initiate WhatsApp connection - generate QR code
         */
        function initiateConnection() {
            console.log('[WA Connect] initiateConnection() called');
            
            if (!qrCodeArea || !connectBtn) {
                console.error('[WA Connect] Required elements not found');
                showError('Terjadi kesalahan. Silakan refresh halaman.');
                return;
            }

            // Show QR area and loading state
            qrCodeArea.classList.remove('d-none');
            if (qrCodeLoading) qrCodeLoading.classList.remove('d-none');
            if (qrCodeImage) {
                qrCodeImage.classList.add('d-none');
                qrCodeImage.innerHTML = '';
            }
            
            // Disable button with loading state
            connectBtn.disabled = true;
            connectBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Connecting...';

            console.log('[WA Connect] Sending request to:', '{{ route("whatsapp.connect") }}');

            fetch('{{ route("whatsapp.connect") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                },
            })
            .then(function(response) {
                console.log('[WA Connect] Response status:', response.status);
                return response.json();
            })
            .then(function(data) {
                console.log('[WA Connect] Response data:', data);
                
                if (data.success) {
                    currentSessionId = data.session_id;
                    renderQrCode(data.qr_code);
                    startCountdown(data.expires_at);
                    startPolling(data.session_id);
                } else {
                    showError(data.message || 'Gagal generate QR code');
                    resetConnectButton();
                }
            })
            .catch(function(error) {
                console.error('[WA Connect] Fetch error:', error);
                showError('Gagal menghubungi server. Silakan coba lagi.');
                resetConnectButton();
            });
        }

        /**
         * Render QR code from base64 data URI
         * Server returns: data:image/svg+xml;base64,PHN2Zy...
         */
        function renderQrCode(qrDataUri) {
            console.log('[WA Connect] Rendering QR code from server');
            
            if (qrCodeLoading) qrCodeLoading.classList.add('d-none');
            if (qrCodeImage) {
                qrCodeImage.classList.remove('d-none');
                qrCodeImage.innerHTML = '';
            }

            // Validate base64 data URI format
            if (!qrDataUri || !qrDataUri.startsWith('data:image/')) {
                console.error('[WA Connect] Invalid QR data URI:', qrDataUri);
                showError('QR Code data tidak valid. Silakan coba lagi.');
                resetConnectButton();
                return;
            }

            // Create image element and display base64 QR directly
            var img = document.createElement('img');
            img.src = qrDataUri;
            img.alt = 'WhatsApp QR Code';
            img.className = 'qr-code-image';
            img.style.width = '250px';
            img.style.height = '250px';
            
            img.onload = function() {
                console.log('[WA Connect] QR code image loaded successfully');
            };
            
            img.onerror = function() {
                console.error('[WA Connect] QR image failed to load');
                showError('Gagal menampilkan QR code. Silakan refresh.');
            };

            if (qrCodeImage) qrCodeImage.appendChild(img);
            if (connectionPending) connectionPending.classList.remove('d-none');
        }

        /**
         * Start countdown timer for QR expiry
         */
        function startCountdown(expiresAt) {
            clearInterval(countdownInterval);
            const expiryDate = new Date(expiresAt);
            console.log('[WA Connect] Starting countdown until:', expiryDate);

            countdownInterval = setInterval(function() {
                const now = new Date();
                const diff = expiryDate - now;

                if (diff <= 0) {
                    clearInterval(countdownInterval);
                    if (expiryCountdown) expiryCountdown.textContent = 'Expired';
                    showExpiredState();
                    return;
                }

                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                if (expiryCountdown) {
                    expiryCountdown.textContent = minutes + ':' + seconds.toString().padStart(2, '0');
                }
            }, 1000);
        }

        /**
         * Start polling for connection status
         * Uses two strategies:
         * 1. Check session status (realtime from gateway)
         * 2. Check klien status (database/cache)
         */
        function startPolling(sessionId) {
            clearInterval(pollInterval);
            console.log('[WA Connect] Starting polling for session:', sessionId);

            var pollCount = 0;
            var maxPolls = 60; // Max 3 minutes of polling (60 * 3s)

            pollInterval = setInterval(function() {
                pollCount++;
                
                if (pollCount > maxPolls) {
                    console.log('[WA Connect] Max polls reached, stopping');
                    clearInterval(pollInterval);
                    showExpiredState();
                    return;
                }

                // Poll session status endpoint (more accurate during connection)
                fetch('{{ route("whatsapp.session.status") }}?session_id=' + encodeURIComponent(sessionId), {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    console.log('[WA Connect] Session poll response:', data);
                    
                    // Update status indicator based on state
                    updateStatusIndicator(data.status, data.message);

                    if (data.connected) {
                        clearInterval(pollInterval);
                        clearInterval(countdownInterval);
                        showSuccess('WhatsApp berhasil terhubung!');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                        return;
                    }

                    // If session expired, show expired state
                    if (data.status === 'expired') {
                        clearInterval(pollInterval);
                        clearInterval(countdownInterval);
                        showExpiredState();
                        return;
                    }

                    // If authenticated, stop countdown (scan successful)
                    if (data.status === 'authenticated') {
                        clearInterval(countdownInterval);
                        if (expiryCountdown) {
                            expiryCountdown.textContent = 'Connecting...';
                        }
                    }
                })
                .catch(function(error) {
                    console.error('[WA Connect] Polling error:', error);
                });
            }, 2000); // Poll every 2 seconds for faster response
        }

        /**
         * Update status indicator during connection
         */
        function updateStatusIndicator(status, message) {
            if (!connectionPending) return;

            var statusHtml = '';
            switch(status) {
                case 'qr_ready':
                    statusHtml = '<span class="text-sm text-muted"><i class="fas fa-qrcode me-2"></i>' + message + '</span>';
                    break;
                case 'authenticated':
                    statusHtml = '<span class="text-sm text-success"><i class="fas fa-check-circle me-2"></i>' + message + '</span>';
                    break;
                case 'connected':
                    statusHtml = '<span class="text-sm text-success"><i class="fas fa-check-double me-2"></i>' + message + '</span>';
                    break;
                default:
                    statusHtml = '<div class="spinner-border spinner-border-sm text-primary me-2"></div><span class="text-sm">' + (message || 'Menunggu...') + '</span>';
            }
            connectionPending.innerHTML = statusHtml;
        }

        /**
         * Show QR expired state
         */
        function showExpiredState() {
            console.log('[WA Connect] QR expired');
            if (qrCodeImage) {
                qrCodeImage.innerHTML = '<div class="text-center text-muted"><i class="fas fa-clock fa-3x mb-2"></i><p>QR Code Expired</p></div>';
            }
            if (connectionPending) connectionPending.classList.add('d-none');
            resetConnectButton();
        }

        /**
         * Perform disconnect
         */
        function performDisconnect() {
            const btn = confirmDisconnect;
            if (!btn) return;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            fetch('{{ route("whatsapp.disconnect") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                },
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    showSuccess(data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showError(data.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-unlink me-2"></i>Ya, Disconnect';
                }
            })
            .catch(function(error) {
                console.error('[WA Connect] Disconnect error:', error);
                showError('Gagal disconnect. Silakan coba lagi.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-unlink me-2"></i>Ya, Disconnect';
            });
        }

        /**
         * Reset connect button to initial state
         */
        function resetConnectButton() {
            if (connectBtn) {
                connectBtn.disabled = false;
                connectBtn.innerHTML = '<i class="fab fa-whatsapp me-2"></i>Hubungkan WhatsApp';
            }
        }

        /**
         * Show success message
         */
        function showSuccess(message) {
            console.log('[WA Connect] Success:', message);
            // Use ClientPopup actionSuccess for friendly messages
            if (typeof ClientPopup !== 'undefined') {
                ClientPopup.actionSuccess(message);
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: message,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }

        /**
         * Show error message
         */
        function showError(message) {
            console.error('[WA Connect] Error:', message);
            // Use ClientPopup actionFailed for friendly error messages
            if (typeof ClientPopup !== 'undefined') {
                ClientPopup.actionFailed('Koneksi WhatsApp belum berhasil. Pastikan QR code sudah di-scan dengan benar.');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum Berhasil',
                    text: 'Koneksi belum berhasil. Silakan coba lagi.'
                });
            }
        }
    }
})();
</script>
@endpush
