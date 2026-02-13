<?php

namespace App\Events;

use App\Models\TemplatePesan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Template Disetujui oleh Meta
 * 
 * Difire ketika template berhasil diapprove oleh Meta/WhatsApp.
 */
class TemplateDisetujuiEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public TemplatePesan $template;
    public ?string $providerTemplateId;

    public function __construct(TemplatePesan $template, ?string $providerTemplateId = null)
    {
        $this->template = $template;
        $this->providerTemplateId = $providerTemplateId;
    }
}
