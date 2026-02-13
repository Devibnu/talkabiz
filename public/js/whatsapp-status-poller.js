/**
 * WhatsApp Connection Status Poller
 * 
 * Auto-update status badge tanpa refresh manual.
 * Polling setiap 5 detik jika status pending, 30 detik jika connected.
 * 
 * Usage:
 * <div id="wa-status-badge" 
 *      data-status="{{ $connection->status }}"
 *      data-poll-url="{{ route('api.whatsapp.connection.status') }}">
 * </div>
 * 
 * <script src="{{ asset('js/whatsapp-status-poller.js') }}"></script>
 * <script>
 *   WhatsAppStatusPoller.init('#wa-status-badge');
 * </script>
 */

const WhatsAppStatusPoller = (function() {
    'use strict';

    // Configuration
    const CONFIG = {
        POLL_INTERVAL_PENDING: 5000,    // 5 detik saat pending
        POLL_INTERVAL_CONNECTED: 30000, // 30 detik saat connected
        POLL_INTERVAL_FAILED: 60000,    // 60 detik saat failed
        MAX_RETRIES: 3,
        RETRY_DELAY: 2000,
    };

    // Status color mapping
    const STATUS_COLORS = {
        'disconnected': 'secondary',
        'pending': 'warning',
        'connected': 'success',
        'restricted': 'danger',
        'failed': 'danger',
        'suspended': 'dark',
        'expired': 'secondary',
    };

    // Status label mapping (Indonesian)
    const STATUS_LABELS = {
        'disconnected': 'Belum Terhubung',
        'pending': 'Menunggu Verifikasi',
        'connected': 'Terhubung',
        'restricted': 'Dibatasi',
        'failed': 'Gagal',
        'suspended': 'Ditangguhkan',
        'expired': 'Kedaluwarsa',
    };

    let pollInterval = null;
    let retryCount = 0;
    let currentStatus = null;
    let targetElement = null;
    let pollUrl = null;

    /**
     * Initialize the status poller
     * @param {string} selector - CSS selector for the status badge container
     */
    function init(selector) {
        targetElement = document.querySelector(selector);
        
        if (!targetElement) {
            console.warn('WhatsAppStatusPoller: Target element not found:', selector);
            return;
        }

        currentStatus = targetElement.dataset.status || 'disconnected';
        pollUrl = targetElement.dataset.pollUrl || '/api/whatsapp/connection/status';

        // Initial render
        renderBadge(currentStatus, STATUS_LABELS[currentStatus]);

        // Start polling
        startPolling();

        console.log('WhatsAppStatusPoller: Initialized with status:', currentStatus);
    }

    /**
     * Start the polling interval
     */
    function startPolling() {
        stopPolling();
        
        const interval = getPollingInterval();
        pollInterval = setInterval(fetchStatus, interval);
        
        console.log('WhatsAppStatusPoller: Polling every', interval/1000, 'seconds');
    }

    /**
     * Stop the polling interval
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    /**
     * Get polling interval based on current status
     */
    function getPollingInterval() {
        switch (currentStatus) {
            case 'pending':
                return CONFIG.POLL_INTERVAL_PENDING;
            case 'connected':
                return CONFIG.POLL_INTERVAL_CONNECTED;
            case 'failed':
            case 'restricted':
            case 'suspended':
                return CONFIG.POLL_INTERVAL_FAILED;
            default:
                return CONFIG.POLL_INTERVAL_PENDING;
        }
    }

    /**
     * Fetch status from API
     */
    async function fetchStatus() {
        try {
            const response = await fetch(pollUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            retryCount = 0;

            handleStatusUpdate(data);

        } catch (error) {
            console.error('WhatsAppStatusPoller: Fetch error:', error);
            handleFetchError();
        }
    }

    /**
     * Handle status update from API
     */
    function handleStatusUpdate(data) {
        const newStatus = data.status;
        const statusLabel = data.status_label || STATUS_LABELS[newStatus];
        const statusColor = data.status_color || STATUS_COLORS[newStatus];

        // Check if status changed
        if (newStatus !== currentStatus) {
            console.log('WhatsAppStatusPoller: Status changed from', currentStatus, 'to', newStatus);
            
            currentStatus = newStatus;
            
            // Update UI with animation
            renderBadge(newStatus, statusLabel, statusColor, true);
            
            // Show notification if became connected
            if (newStatus === 'connected') {
                showNotification('WhatsApp Terhubung!', 'Nomor WhatsApp Anda sudah aktif dan siap digunakan.', 'success');
            } else if (newStatus === 'failed') {
                showNotification('Koneksi Gagal', data.error_reason || 'Terjadi kesalahan saat menghubungkan WhatsApp.', 'error');
            }

            // Adjust polling interval based on new status
            startPolling();
        }

        // Update additional info
        if (data.connected_at && currentStatus === 'connected') {
            updateConnectedInfo(data);
        }
    }

    /**
     * Handle fetch errors with retry
     */
    function handleFetchError() {
        retryCount++;
        
        if (retryCount >= CONFIG.MAX_RETRIES) {
            console.warn('WhatsAppStatusPoller: Max retries reached, slowing down polling');
            // Slow down polling on persistent errors
            stopPolling();
            setTimeout(() => {
                retryCount = 0;
                startPolling();
            }, 60000);
        }
    }

    /**
     * Render the status badge
     */
    function renderBadge(status, label, color = null, animate = false) {
        if (!targetElement) return;

        color = color || STATUS_COLORS[status] || 'secondary';
        label = label || STATUS_LABELS[status] || status;

        const badgeHtml = `
            <span class="badge bg-${color} ${animate ? 'animate-pulse' : ''}" id="wa-status-badge-inner">
                ${getStatusIcon(status)} ${label}
            </span>
        `;

        targetElement.innerHTML = badgeHtml;

        // Remove animation class after animation completes
        if (animate) {
            setTimeout(() => {
                const badge = document.getElementById('wa-status-badge-inner');
                if (badge) {
                    badge.classList.remove('animate-pulse');
                }
            }, 1000);
        }
    }

    /**
     * Get icon for status
     */
    function getStatusIcon(status) {
        const icons = {
            'disconnected': '<i class="fas fa-times-circle me-1"></i>',
            'pending': '<i class="fas fa-clock me-1"></i>',
            'connected': '<i class="fas fa-check-circle me-1"></i>',
            'restricted': '<i class="fas fa-exclamation-triangle me-1"></i>',
            'failed': '<i class="fas fa-times-circle me-1"></i>',
            'suspended': '<i class="fas fa-ban me-1"></i>',
            'expired': '<i class="fas fa-clock me-1"></i>',
        };
        return icons[status] || '';
    }

    /**
     * Update connected info display
     */
    function updateConnectedInfo(data) {
        const infoElement = document.getElementById('wa-connected-info');
        if (!infoElement) return;

        let html = '';
        
        if (data.phone_number) {
            html += `<div class="text-sm text-muted"><i class="fas fa-phone me-1"></i>${formatPhone(data.phone_number)}</div>`;
        }
        
        if (data.display_name) {
            html += `<div class="text-sm text-muted"><i class="fas fa-building me-1"></i>${data.display_name}</div>`;
        }
        
        if (data.quality_rating) {
            const qualityColor = data.quality_rating === 'GREEN' ? 'success' : 
                                data.quality_rating === 'YELLOW' ? 'warning' : 'danger';
            html += `<div class="text-sm"><span class="badge bg-${qualityColor}">Quality: ${data.quality_rating}</span></div>`;
        }

        infoElement.innerHTML = html;
    }

    /**
     * Format phone number for display
     */
    function formatPhone(phone) {
        if (!phone) return '';
        // Format: +62 812-3456-7890
        if (phone.startsWith('62')) {
            phone = '+' + phone;
        }
        return phone.replace(/(\+62)(\d{3})(\d{4})(\d+)/, '$1 $2-$3-$4');
    }

    /**
     * Show browser notification
     */
    function showNotification(title, message, type = 'info') {
        // Try browser notification first
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, {
                body: message,
                icon: '/assets/img/whatsapp-icon.png',
            });
        }

        // Also show toast if available
        if (typeof Swal !== 'undefined') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true,
            });
            Toast.fire({
                icon: type,
                title: title,
                text: message,
            });
        } else if (typeof toastr !== 'undefined') {
            toastr[type](message, title);
        }
    }

    /**
     * Request notification permission
     */
    function requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    /**
     * Destroy the poller
     */
    function destroy() {
        stopPolling();
        targetElement = null;
        currentStatus = null;
        pollUrl = null;
        retryCount = 0;
    }

    /**
     * Force refresh status
     */
    function refresh() {
        fetchStatus();
    }

    // Request notification permission on load
    requestNotificationPermission();

    // Public API
    return {
        init,
        refresh,
        destroy,
        getCurrentStatus: () => currentStatus,
    };

})();

// Add CSS for pulse animation
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse-badge {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.8; transform: scale(1.05); }
    }
    .animate-pulse {
        animation: pulse-badge 0.5s ease-in-out 2;
    }
`;
document.head.appendChild(style);

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WhatsAppStatusPoller;
}
