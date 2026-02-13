<?php

namespace App\Enums;

/**
 * MessageStatus - Status Pesan WA Blast
 * 
 * Flow:
 * PENDING → QUEUED → SENT → DELIVERED → READ
 *              ↓         ↓
 *           SKIPPED    FAILED
 * 
 * @package App\Enums
 */
enum MessageStatus: string
{
    case PENDING = 'pending';       // Belum diproses
    case QUEUED = 'queued';         // Dalam antrian
    case SENT = 'sent';             // Terkirim ke Gupshup
    case DELIVERED = 'delivered';   // Sampai ke HP penerima
    case READ = 'read';             // Dibaca oleh penerima
    case FAILED = 'failed';         // Gagal kirim
    case SKIPPED = 'skipped';       // Dilewati (non opt-in, invalid number)

    /**
     * Get status label for UI
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::QUEUED => 'Antrian',
            self::SENT => 'Terkirim',
            self::DELIVERED => 'Sampai',
            self::READ => 'Dibaca',
            self::FAILED => 'Gagal',
            self::SKIPPED => 'Dilewati',
        };
    }

    /**
     * Get badge color for UI
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'secondary',
            self::QUEUED => 'info',
            self::SENT => 'primary',
            self::DELIVERED => 'success',
            self::READ => 'success',
            self::FAILED => 'danger',
            self::SKIPPED => 'warning',
        };
    }

    /**
     * Check if status is successful
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::SENT, self::DELIVERED, self::READ]);
    }

    /**
     * Check if status is terminal (final)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::READ, self::FAILED, self::SKIPPED]);
    }

    /**
     * Check if message should be counted as sent (for quota)
     */
    public function countsAsQuota(): bool
    {
        return in_array($this, [self::SENT, self::DELIVERED, self::READ]);
    }

    /**
     * Map from Gupshup webhook status
     */
    public static function fromGupshup(string $gupshupStatus): self
    {
        return match (strtolower($gupshupStatus)) {
            'sent', 'enroute' => self::SENT,
            'delivered' => self::DELIVERED,
            'read' => self::READ,
            'failed', 'error' => self::FAILED,
            default => self::PENDING,
        };
    }
}
