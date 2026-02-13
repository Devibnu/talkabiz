<?php

namespace App\Events;

use App\Models\TemplatePesan;
use App\Models\Pengguna;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Template Diajukan ke Provider
 * 
 * Difire ketika template disubmit untuk review Meta.
 */
class TemplateDiajukanEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public TemplatePesan $template;
    public ?int $userId;

    public function __construct(TemplatePesan $template, ?int $userId = null)
    {
        $this->template = $template;
        $this->userId = $userId;
    }
}
