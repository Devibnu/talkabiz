<?php

namespace App\Enums;

/**
 * CampaignStatus - Status Campaign WA Blast
 * 
 * Flow:
 * DRAFT → READY → SENDING → COMPLETED
 *                    ↓
 *                 PAUSED → SENDING
 *                    ↓
 *                 FAILED / CANCELLED
 * 
 * @package App\Enums
 */
enum CampaignStatus: string
{
    case DRAFT = 'draft';               // Baru dibuat, belum siap
    case READY = 'ready';               // Siap dikirim, sudah preview
    case SCHEDULED = 'scheduled';       // Dijadwalkan untuk dikirim nanti
    case SENDING = 'sending';           // Sedang proses kirim
    case PAUSED = 'paused';             // Di-pause (quota habis, user action)
    case COMPLETED = 'completed';       // Semua pesan sudah dikirim
    case FAILED = 'failed';             // Gagal (quota exceeded, owner action)
    case CANCELLED = 'cancelled';       // Dibatalkan oleh user

    /**
     * Get status label for UI
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::READY => 'Siap Kirim',
            self::SCHEDULED => 'Terjadwal',
            self::SENDING => 'Sedang Kirim',
            self::PAUSED => 'Dijeda',
            self::COMPLETED => 'Selesai',
            self::FAILED => 'Gagal',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /**
     * Get badge color for UI
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'secondary',
            self::READY => 'info',
            self::SCHEDULED => 'warning',
            self::SENDING => 'primary',
            self::PAUSED => 'warning',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'dark',
        };
    }

    /**
     * Check if campaign can be started
     */
    public function canStart(): bool
    {
        return in_array($this, [self::READY, self::PAUSED, self::SCHEDULED]);
    }

    /**
     * Check if campaign can be paused
     */
    public function canPause(): bool
    {
        return $this === self::SENDING;
    }

    /**
     * Check if campaign can be cancelled
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::DRAFT, self::READY, self::SCHEDULED, self::PAUSED]);
    }

    /**
     * Check if campaign is active (running or paused)
     */
    public function isActive(): bool
    {
        return in_array($this, [self::SENDING, self::PAUSED]);
    }

    /**
     * Check if campaign is terminal (cannot be changed)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED]);
    }
}
