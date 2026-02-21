@extends('layouts.user_type.auth')

@section('content')
<style>
.invoice-header { margin-bottom: 1.5rem; }
.invoice-header h4 { font-size: 1.5rem; font-weight: 700; color: #344767; margin: 0; }
.invoice-header p { font-size: 0.875rem; color: #67748e; margin: 0.25rem 0 0 0; }

.invoice-table th { font-size: 0.75rem; text-transform: uppercase; color: #8392AB; font-weight: 600; padding: 12px 16px; background: #f8f9fa; }
.invoice-table td { font-size: 0.875rem; color: #344767; padding: 12px 16px; vertical-align: middle; }

.badge-paid { background: #2dce89; color: #fff; }
.badge-pending { background: #fb6340; color: #fff; }
.badge-expired { background: #8898aa; color: #fff; }
.badge-cancelled { background: #f5365c; color: #fff; }
.badge-draft { background: #adb5bd; color: #fff; }
.badge-refunded { background: #11cdef; color: #fff; }

.type-tag { font-size: 0.7rem; padding: 3px 8px; border-radius: 4px; font-weight: 600; }
.type-subscription { background: #e8f5e9; color: #2e7d32; }
.type-topup { background: #e3f2fd; color: #1565c0; }
.type-subscription_renewal { background: #fff3e0; color: #e65100; }
.type-subscription_upgrade { background: #f3e5f5; color: #7b1fa2; }

.filter-pills .btn { font-size: 0.8rem; padding: 6px 14px; border-radius: 20px; }
.filter-pills .btn.active { box-shadow: 0 3px 5px -1px rgba(0,0,0,.09), 0 2px 3px -1px rgba(0,0,0,.07); }

.empty-state { text-align: center; padding: 60px 20px; }
.empty-state i { font-size: 3rem; color: #dee2e6; margin-bottom: 16px; }
.empty-state h6 { color: #8898aa; font-weight: 600; }
.empty-state p { color: #adb5bd; font-size: 0.875rem; }
</style>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="invoice-header d-flex justify-content-between align-items-center">
                <div>
                    <h4>Invoice</h4>
                    <p>Riwayat semua invoice transaksi Anda</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="filter-pills d-flex gap-2 flex-wrap">
                <a href="{{ route('invoices.index') }}" 
                   class="btn btn-sm {{ !request('type') && !request('status') ? 'btn-primary active' : 'btn-outline-primary' }}">
                    Semua
                </a>
                <a href="{{ route('invoices.index', ['type' => 'subscription']) }}" 
                   class="btn btn-sm {{ request('type') === 'subscription' ? 'btn-primary active' : 'btn-outline-primary' }}">
                    Langganan
                </a>
                <a href="{{ route('invoices.index', ['type' => 'topup']) }}" 
                   class="btn btn-sm {{ request('type') === 'topup' ? 'btn-primary active' : 'btn-outline-primary' }}">
                    Topup
                </a>
                <a href="{{ route('invoices.index', ['type' => 'subscription_renewal']) }}" 
                   class="btn btn-sm {{ request('type') === 'subscription_renewal' ? 'btn-primary active' : 'btn-outline-primary' }}">
                    Perpanjangan
                </a>
                <span class="mx-2 border-start"></span>
                <a href="{{ route('invoices.index', ['status' => 'paid']) }}" 
                   class="btn btn-sm {{ request('status') === 'paid' ? 'btn-success active' : 'btn-outline-success' }}">
                    Lunas
                </a>
                <a href="{{ route('invoices.index', ['status' => 'pending']) }}" 
                   class="btn btn-sm {{ request('status') === 'pending' ? 'btn-warning active' : 'btn-outline-warning' }}">
                    Pending
                </a>
            </div>
        </div>
    </div>

    {{-- Invoice Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body px-0 pt-0 pb-2">
                    @if($invoices->count() > 0)
                    <div class="table-responsive">
                        <table class="table invoice-table mb-0">
                            <thead>
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Tipe</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Tanggal</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoices as $invoice)
                                <tr>
                                    <td>
                                        <a href="{{ route('invoices.show', $invoice->id) }}" class="text-decoration-none fw-semibold">
                                            {{ $invoice->invoice_number }}
                                        </a>
                                    </td>
                                    <td>
                                        @php
                                            $typeClass = match($invoice->type) {
                                                'subscription' => 'subscription',
                                                'topup' => 'topup',
                                                'subscription_renewal' => 'subscription_renewal',
                                                'subscription_upgrade' => 'subscription_upgrade',
                                                default => 'subscription',
                                            };
                                            $typeLabel = match($invoice->type) {
                                                'subscription' => 'Langganan',
                                                'subscription_upgrade' => 'Upgrade',
                                                'subscription_renewal' => 'Perpanjangan',
                                                'topup' => 'Topup',
                                                'addon' => 'Add-on',
                                                default => 'Lainnya',
                                            };
                                        @endphp
                                        <span class="type-tag type-{{ $typeClass }}">{{ $typeLabel }}</span>
                                    </td>
                                    <td class="fw-semibold">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
                                    <td>
                                        @php
                                            $badgeClass = match($invoice->status) {
                                                'paid' => 'badge-paid',
                                                'pending' => 'badge-pending',
                                                'expired' => 'badge-expired',
                                                'cancelled' => 'badge-cancelled',
                                                'draft' => 'badge-draft',
                                                'refunded' => 'badge-refunded',
                                                default => 'badge-draft',
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
                                        <span class="badge {{ $badgeClass }}" style="font-size: 0.75rem;">{{ $statusLabel }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $invoice->issued_at?->format('d M Y') ?? $invoice->created_at->format('d M Y') }}</span>
                                        <br>
                                        <span class="text-xs text-muted">{{ $invoice->issued_at?->format('H:i') ?? $invoice->created_at->format('H:i') }}</span>
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('invoices.show', $invoice->id) }}" class="btn btn-sm btn-outline-primary px-3" title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if($invoice->status === 'paid')
                                        <a href="{{ route('invoices.pdf', $invoice->id) }}" target="_blank" class="btn btn-sm btn-outline-dark px-3" title="PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    <div class="d-flex justify-content-center mt-3">
                        {{ $invoices->withQueryString()->links() }}
                    </div>
                    @else
                    <div class="empty-state">
                        <i class="fas fa-file-invoice"></i>
                        <h6>Belum Ada Invoice</h6>
                        <p>Invoice akan muncul di sini setelah Anda melakukan transaksi.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
