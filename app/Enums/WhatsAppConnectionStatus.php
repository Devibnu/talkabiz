<?php

namespace App\Enums;

/**
 * WhatsAppConnectionStatus Enum
 * 
 * Status koneksi WhatsApp yang FINAL dan TERKUNCI.
 * 
 * RULES:
 * 1. CONNECTED hanya boleh di-set oleh webhook (source of truth)
 * 2. DISCONNECTED bisa di-set oleh owner/sistem (force disconnect)
 * 3. PENDING adalah status awal saat registrasi
 * 4. FAILED hanya dari webhook rejection
 * 
 * @package App\Enums
 */
enum WhatsAppConnectionStatus: string
{
    // ==================== FINAL STATUS SET ====================
    
    /**
     * Menunggu verifikasi dari Gupshup/WhatsApp
     * Status awal saat user mendaftarkan nomor
     */
    case PENDING = 'pending';
    
    /**
     * Terhubung dan aktif
     * HANYA BOLEH DI-SET OLEH WEBHOOK
     */
    case CONNECTED = 'connected';
    
    /**
     * Gagal koneksi / ditolak
     * Di-set oleh webhook saat rejection
     */
    case FAILED = 'failed';
    
    /**
     * Terputus / diputus manual
     * Bisa di-set oleh owner (force disconnect) atau webhook
     */
    case DISCONNECTED = 'disconnected';

    // ==================== LABELS ====================
    
    /**
     * Get Indonesian label for UI display
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Menunggu Verifikasi',
            self::CONNECTED => 'Terhubung',
            self::FAILED => 'Gagal',
            self::DISCONNECTED => 'Terputus',
        };
    }

    /**
     * Get Bootstrap badge color
     */
    public function color(): string
    {
        return match($this) {
            self::PENDING => 'warning',
            self::CONNECTED => 'success',
            self::FAILED => 'danger',
            self::DISCONNECTED => 'secondary',
        };
    }

    /**
     * Get icon class (FontAwesome)
     */
    public function icon(): string
    {
        return match($this) {
            self::PENDING => 'fas fa-clock',
            self::CONNECTED => 'fas fa-check-circle',
            self::FAILED => 'fas fa-times-circle',
            self::DISCONNECTED => 'fas fa-unlink',
        };
    }

    // ==================== VALIDATION RULES ====================

    /**
     * Check if this status can transition to the target status
     * 
     * TRANSITION RULES:
     * - PENDING → CONNECTED (webhook only)
     * - PENDING → FAILED (webhook only)
     * - PENDING → DISCONNECTED (owner/system)
     * - CONNECTED → DISCONNECTED (owner/webhook)
     * - CONNECTED → FAILED (webhook only)
     * - FAILED → PENDING (manual retry)
     * - DISCONNECTED → PENDING (manual reconnect)
     * 
     * FORBIDDEN:
     * - Any → CONNECTED (except PENDING via webhook)
     * - CONNECTED → PENDING (downgrade prevention)
     */
    public function canTransitionTo(self $target, bool $isWebhook = false): bool
    {
        // CONNECTED can ONLY be set by webhook
        if ($target === self::CONNECTED && !$isWebhook) {
            return false;
        }

        return match($this) {
            self::PENDING => in_array($target, [
                self::CONNECTED,    // webhook approval
                self::FAILED,       // webhook rejection
                self::DISCONNECTED, // owner cancel
            ]),
            
            self::CONNECTED => in_array($target, [
                self::DISCONNECTED, // owner/webhook disconnect
                self::FAILED,       // webhook ban/suspension
            ]),
            
            self::FAILED => in_array($target, [
                self::PENDING,      // manual retry
                self::DISCONNECTED, // cleanup
            ]),
            
            self::DISCONNECTED => in_array($target, [
                self::PENDING,      // reconnect attempt
            ]),
        };
    }

    /**
     * Check if status allows sending messages
     */
    public function canSendMessages(): bool
    {
        return $this === self::CONNECTED;
    }

    /**
     * Check if status is a terminal/final state
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CONNECTED, self::FAILED, self::DISCONNECTED]);
    }

    /**
     * Check if status requires attention
     */
    public function requiresAttention(): bool
    {
        return in_array($this, [self::FAILED, self::PENDING]);
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get all status values as array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get status labels for dropdown/select
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /**
     * Create from string value (with fallback)
     */
    public static function fromString(?string $value): self
    {
        if (empty($value)) {
            return self::DISCONNECTED;
        }
        
        return self::tryFrom(strtolower($value)) ?? self::DISCONNECTED;
    }

    /**
     * Statuses that can be set only by webhook
     */
    public static function webhookOnlyStatuses(): array
    {
        return [self::CONNECTED];
    }
}
