<?php

namespace App\Exceptions;

use Exception;

class InvoiceImmutableException extends Exception
{
    protected $invoiceId;

    public function __construct(string $message, int $invoiceId = null, int $code = 0, \Throwable $previous = null)
    {
        $this->invoiceId = $invoiceId;
        parent::__construct($message, $code, $previous);
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function toApiResponse(): array
    {
        return [
            'error' => 'invoice_immutable',
            'message' => $this->getMessage(),
            'invoice_id' => $this->invoiceId,
            'action' => [
                'type' => 'contact_support',
                'message' => 'Invoice sudah tidak dapat diubah. Hubungi support untuk assistance.'
            ]
        ];
    }
}