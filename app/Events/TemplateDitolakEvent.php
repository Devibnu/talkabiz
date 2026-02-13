<?php

namespace App\Events;

use App\Models\TemplatePesan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Template Ditolak oleh Meta
 * 
 * Difire ketika template ditolak oleh Meta/WhatsApp.
 */
class TemplateDitolakEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public TemplatePesan $template;
    public ?string $alasan;

    public function __construct(TemplatePesan $template, ?string $alasan = null)
    {
        $this->template = $template;
        $this->alasan = $alasan;
    }
}
