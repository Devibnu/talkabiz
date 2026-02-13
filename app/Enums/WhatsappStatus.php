<?php

namespace App\Enums;

/**
 * WhatsappStatus Enum
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
enum WhatsappStatus: string
{
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

    /**
     * Check if this status can transition to the target status
     * 
     * FORBIDDEN:
     * - Any → CONNECTED (except via webhook)
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
     * Get all status values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
