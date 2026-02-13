<?php

namespace App\Services;

use App\Models\WalletTransaction;
use App\Models\User;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;

/**
 * TopupInvoiceService - PDF Invoice Generation
 * 
 * Generates PDF invoices for wallet topup transactions.
 */
class TopupInvoiceService
{
    /**
     * Generate topup invoice PDF
     * 
     * @param WalletTransaction $transaction
     * @return string PDF file path
     * @throws Exception
     */
    public function generateInvoice(WalletTransaction $transaction): string
    {
        if ($transaction->type !== WalletTransaction::TYPE_TOPUP) {
            throw new Exception('Invoice can only be generated for topup transactions');
        }

        if ($transaction->status !== WalletTransaction::STATUS_COMPLETED) {
            throw new Exception('Invoice can only be generated for completed transactions');
        }

        $user = $transaction->user;
        if (!$user) {
            throw new Exception('User not found for this transaction');
        }

        // Prepare invoice data
        $invoiceData = $this->prepareInvoiceData($transaction, $user);
        
        // Generate PDF
        $pdf = Pdf::loadView('invoices.topup', $invoiceData);
        $pdf->setPaper('A4');
        
        // Generate unique filename
        $filename = $this->generateFilename($transaction);
        $filepath = "invoices/topup/{$filename}";
        
        // Save PDF to storage
        Storage::put($filepath, $pdf->output());
        
        // Update transaction with invoice path
        $transaction->update([
            'metadata' => array_merge(
                $transaction->metadata ?? [],
                ['invoice_path' => $filepath]
            )
        ]);

        return $filepath;
    }

    /**
     * Prepare data for invoice template
     * 
     * @param WalletTransaction $transaction
     * @param User $user
     * @return array
     */
    protected function prepareInvoiceData(WalletTransaction $transaction, User $user): array
    {
        $company = [
            'name' => config('app.name', 'Talkabiz'),
            'address' => 'Jl. Tech Hub No. 123, Jakarta',
            'phone' => '+62 21 1234 5678',
            'email' => 'support@talkabiz.id',
            'website' => 'https://talkabiz.id',
        ];

        $invoice = [
            'number' => $this->generateInvoiceNumber($transaction),
            'date' => $transaction->created_at->format('d M Y'),
            'due_date' => $transaction->created_at->format('d M Y'), // Immediate for topup
        ];

        // Try to get related Invoice model for snapshot data
        $invoiceModel = Invoice::where('wallet_transaction_id', $transaction->id)->first();
        $snapshot = $invoiceModel?->billing_snapshot;

        // Use snapshot data if available, otherwise fallback to user data
        if ($snapshot) {
            $customer = [
                'name' => $snapshot['business_name'] ?? $user->name,
                'email' => $snapshot['email'] ?? $user->email,
                'phone' => $snapshot['phone'] ?? $user->phone ?? '-',
                'address' => $this->formatSnapshotAddress($snapshot),
                'business_type' => $snapshot['business_type_name'] ?? null,
                'npwp' => $snapshot['npwp'] ?? null,
                'is_pkp' => $snapshot['is_pkp'] ?? false,
            ];
        } else {
            $customer = [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '-',
                'address' => $user->address ?? '-',
                'business_type' => null,
                'npwp' => null,
                'is_pkp' => false,
            ];
        }

        $items = [
            [
                'description' => 'Topup Saldo Wallet',
                'quantity' => 1,
                'unit_price' => $transaction->amount,
                'total' => $transaction->amount,
            ]
        ];

        $totals = [
            'subtotal' => $transaction->amount,
            'tax_rate' => 0, // No tax for topup
            'tax_amount' => 0,
            'total' => $transaction->amount,
        ];

        $payment = [
            'method' => $transaction->metadata['payment_gateway'] ?? 'Payment Gateway',
            'reference' => $transaction->reference_id ?? $transaction->id,
            'status' => 'PAID',
            'paid_at' => $transaction->processed_at?->format('d M Y H:i') ?? $transaction->created_at->format('d M Y H:i'),
        ];

        return compact('company', 'invoice', 'customer', 'items', 'totals', 'payment', 'transaction', 'snapshot');
    }

    /**
     * Format snapshot address into readable string
     * 
     * @param array $snapshot
     * @return string
     */
    protected function formatSnapshotAddress(array $snapshot): string
    {
        $parts = array_filter([
            $snapshot['address'] ?? null,
            $snapshot['city'] ?? null,
            $snapshot['province'] ?? null,
            $snapshot['postal_code'] ?? null,
        ]);

        return !empty($parts) ? implode(', ', $parts) : '-';
    }

    /**
     * Generate invoice number
     * 
     * @param WalletTransaction $transaction
     * @return string
     */
    protected function generateInvoiceNumber(WalletTransaction $transaction): string
    {
        $date = $transaction->created_at->format('Ymd');
        $id = str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
        return "TOPUP-{$date}-{$id}";
    }

    /**
     * Generate PDF filename
     * 
     * @param WalletTransaction $transaction
     * @return string
     */
    protected function generateFilename(WalletTransaction $transaction): string
    {
        $invoiceNumber = $this->generateInvoiceNumber($transaction);
        return "{$invoiceNumber}.pdf";
    }

    /**
     * Get existing invoice or generate new one
     * 
     * @param WalletTransaction $transaction
     * @return string
     */
    public function getOrGenerateInvoice(WalletTransaction $transaction): string
    {
        $existingPath = $transaction->metadata['invoice_path'] ?? null;
        
        if ($existingPath && Storage::exists($existingPath)) {
            return $existingPath;
        }

        return $this->generateInvoice($transaction);
    }

    /**
     * Download invoice PDF
     * 
     * @param WalletTransaction $transaction
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadInvoice(WalletTransaction $transaction)
    {
        $filepath = $this->getOrGenerateInvoice($transaction);
        $filename = basename($filepath);
        
        return Storage::download($filepath, $filename);
    }

    /**
     * Get invoice URL
     * 
     * @param WalletTransaction $transaction
     * @return string
     */
    public function getInvoiceUrl(WalletTransaction $transaction): string
    {
        $filepath = $this->getOrGenerateInvoice($transaction);
        return Storage::url($filepath);
    }

    /**
     * Check if transaction has invoice
     * 
     * @param WalletTransaction $transaction
     * @return bool
     */
    public function hasInvoice(WalletTransaction $transaction): bool
    {
        $path = $transaction->metadata['invoice_path'] ?? null;
        return $path && Storage::exists($path);
    }

    /**
     * Generate invoices for multiple transactions (batch)
     * 
     * @param array $transactionIds
     * @return array
     */
    public function generateBatchInvoices(array $transactionIds): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($transactionIds as $transactionId) {
            try {
                $transaction = WalletTransaction::findOrFail($transactionId);
                $filepath = $this->generateInvoice($transaction);
                
                $results['success'][] = [
                    'transaction_id' => $transactionId,
                    'filepath' => $filepath,
                ];
                
            } catch (Exception $e) {
                $results['failed'][] = [
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}