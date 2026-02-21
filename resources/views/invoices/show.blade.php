@extends('layouts.user_type.auth')

@section('content')
<style>
.invoice-detail-card { border-radius: 12px; border: none; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
.invoice-detail-header { padding: 24px 28px; border-bottom: 1px solid #f0f0f0; }
.invoice-number { font-size: 1.1rem; font-weight: 700; color: #344767; }
.invoice-meta { font-size: 0.8rem; color: #8898aa; }

.badge-paid { background: #2dce89; color: #fff; }
.badge-pending { background: #fb6340; color: #fff; }
.badge-expired { background: #8898aa; color: #fff; }
.badge-cancelled { background: #f5365c; color: #fff; }

.info-label { font-size: 0.75rem; text-transform: uppercase; color: #8898aa; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 4px; }
.info-value { font-size: 0.9rem; color: #344767; font-weight: 500; }

.items-table th { font-size: 0.75rem; text-transform: uppercase; color: #8392AB; font-weight: 600; background: #f8f9fa; padding: 10px 16px; }
.items-table td { font-size: 0.875rem; color: #344767; padding: 10px 16px; }

.summary-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.875rem; }
.summary-row.total { border-top: 2px solid #344767; padding-top: 12px; margin-top: 8px; font-weight: 700; font-size: 1rem; }
.summary-label { color: #8898aa; }
.summary-value { color: #344767; font-weight: 600; }
</style>

<div class="container-fluid py-4">
    {{-- Breadcrumb --}}
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-0 px-0">
                    <li class="breadcrumb-item"><a href="{{ route('invoices.index') }}" class="text-sm text-primary">Invoice</a></li>
                    <li class="breadcrumb-item active text-sm" aria-current="page">{{ $invoice->invoice_number }}</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row">
        {{-- Main Invoice Card --}}
        <div class="col-lg-8 col-md-12 mb-4">
            <div class="card invoice-detail-card">
                <div class="invoice-detail-header d-flex justify-content-between align-items-center">
                    <div>
                        <span class="invoice-number">{{ $invoice->invoice_number }}</span>
                        <div class="invoice-meta mt-1">
                            Diterbitkan: {{ $invoice->issued_at?->translatedFormat('d F Y, H:i') ?? '-' }}
                        </div>
                    </div>
                    <div>
                        @php
                            $badgeClass = match($invoice->status) {
                                'paid' => 'badge-paid',
                                'pending' => 'badge-pending',
                                'expired' => 'badge-expired',
                                'cancelled' => 'badge-cancelled',
                                default => 'badge-pending',
                            };
                            $statusLabel = match($invoice->status) {
                                'paid' => 'Lunas',
                                'pending' => 'Pending',
                                'expired' => 'Kedaluwarsa',
                                'cancelled' => 'Dibatalkan',
                                'draft' => 'Draft',
                                'refunded' => 'Dikembalikan',
                                default => $invoice->status,
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}" style="font-size: 0.85rem; padding: 6px 14px;">{{ $statusLabel }}</span>
                    </div>
                </div>

                <div class="card-body p-4">
                    {{-- Seller & Buyer Info --}}
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="info-label">Dari</div>
                            <div class="info-value">{{ $company['name'] }}</div>
                            <div class="text-sm text-muted">{{ $company['address'] }}</div>
                            @if(!empty($company['npwp']))
                            <div class="text-sm text-muted">NPWP: {{ $company['npwp'] }}</div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Kepada</div>
                            <div class="info-value">
                                {{ $invoice->klien?->nama_perusahaan ?? $invoice->user?->name ?? '-' }}
                            </div>
                            <div class="text-sm text-muted">{{ $invoice->klien?->email ?? $invoice->user?->email ?? '-' }}</div>
                            @if($invoice->snapshot_npwp)
                            <div class="text-sm text-muted">NPWP: {{ $invoice->snapshot_npwp }}</div>
                            @endif
                        </div>
                    </div>

                    {{-- Invoice Items --}}
                    <div class="table-responsive mb-4">
                        <table class="table items-table mb-0">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Harga</th>
                                    <th class="text-end">Pajak</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($items as $item)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $item->item_name }}</div>
                                        @if($item->item_description)
                                        <div class="text-sm text-muted">{{ $item->item_description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ number_format($item->quantity, 0) }}</td>
                                    <td class="text-end">{{ $item->formatted_unit_price }}</td>
                                    <td class="text-end">Rp {{ number_format($item->tax_amount, 0, ',', '.') }}</td>
                                    <td class="text-end fw-semibold">{{ $item->formatted_total_amount }}</td>
                                </tr>
                                @empty
                                {{-- Fallback to JSON line_items if no InvoiceItem records --}}
                                @foreach($invoice->line_items ?? [] as $lineItem)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $lineItem['name'] ?? $lineItem['description'] ?? '-' }}</div>
                                    </td>
                                    <td class="text-center">{{ $lineItem['qty'] ?? $lineItem['quantity'] ?? 1 }}</td>
                                    <td class="text-end">Rp {{ number_format($lineItem['price'] ?? $lineItem['unit_price'] ?? 0, 0, ',', '.') }}</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end fw-semibold">Rp {{ number_format($lineItem['total'] ?? 0, 0, ',', '.') }}</td>
                                </tr>
                                @endforeach
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Summary --}}
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <div class="summary-row">
                                <span class="summary-label">Subtotal</span>
                                <span class="summary-value">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</span>
                            </div>
                            @if(($invoice->discount ?? 0) > 0)
                            <div class="summary-row">
                                <span class="summary-label">Diskon</span>
                                <span class="summary-value text-danger">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</span>
                            </div>
                            @endif
                            <div class="summary-row">
                                <span class="summary-label">PPN ({{ $invoice->tax_rate ?? config('tax.default_tax_rate', 11) }}%)</span>
                                <span class="summary-value">Rp {{ number_format($invoice->tax_amount ?? $invoice->tax ?? 0, 0, ',', '.') }}</span>
                            </div>
                            <div class="summary-row total">
                                <span>Total</span>
                                <span>Rp {{ number_format($invoice->total, 0, ',', '.') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar Info --}}
        <div class="col-lg-4 col-md-12">
            {{-- Payment Info Card --}}
            <div class="card invoice-detail-card mb-4">
                <div class="card-body p-4">
                    <h6 class="text-uppercase text-xs text-muted fw-bold mb-3">Informasi Pembayaran</h6>

                    <div class="mb-3">
                        <div class="info-label">Metode</div>
                        <div class="info-value">{{ ucfirst($invoice->payment_method ?? '-') }}</div>
                    </div>

                    @if($invoice->paid_at)
                    <div class="mb-3">
                        <div class="info-label">Dibayar Pada</div>
                        <div class="info-value">{{ $invoice->paid_at->translatedFormat('d F Y, H:i') }}</div>
                    </div>
                    @endif

                    @if($invoice->due_at && $invoice->status === 'pending')
                    <div class="mb-3">
                        <div class="info-label">Jatuh Tempo</div>
                        <div class="info-value {{ $invoice->due_at->isPast() ? 'text-danger' : '' }}">
                            {{ $invoice->due_at->translatedFormat('d F Y, H:i') }}
                        </div>
                    </div>
                    @endif

                    <div class="mb-0">
                        <div class="info-label">Mata Uang</div>
                        <div class="info-value">{{ $invoice->currency ?? 'IDR' }}</div>
                    </div>
                </div>
            </div>

            {{-- Actions Card --}}
            @if($invoice->status === 'paid')
            <div class="card invoice-detail-card">
                <div class="card-body p-4">
                    <h6 class="text-uppercase text-xs text-muted fw-bold mb-3">Unduh</h6>
                    <a href="{{ route('invoices.pdf', $invoice->id) }}" target="_blank" 
                       class="btn btn-outline-dark btn-sm w-100 mb-2">
                        <i class="fas fa-eye me-2"></i> Lihat PDF
                    </a>
                    <a href="{{ route('invoices.download', $invoice->id) }}" 
                       class="btn btn-dark btn-sm w-100">
                        <i class="fas fa-download me-2"></i> Unduh PDF
                    </a>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
