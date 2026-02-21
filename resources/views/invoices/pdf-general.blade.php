<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', 'Helvetica', 'Arial', sans-serif; font-size: 12px; color: #333; line-height: 1.5; background: #fff; }

        .invoice-container { max-width: 700px; margin: 0 auto; padding: 30px 40px; }

        /* Header */
        .invoice-header { display: table; width: 100%; margin-bottom: 30px; border-bottom: 3px solid #344767; padding-bottom: 20px; }
        .header-left { display: table-cell; width: 50%; vertical-align: top; }
        .header-right { display: table-cell; width: 50%; vertical-align: top; text-align: right; }
        .company-name { font-size: 22px; font-weight: bold; color: #344767; margin-bottom: 5px; }
        .company-info { font-size: 10px; color: #666; line-height: 1.6; }
        .invoice-title { font-size: 28px; font-weight: bold; color: #344767; margin-bottom: 5px; }
        .invoice-number { font-size: 13px; color: #555; font-weight: 600; }

        /* Status Badge */
        .status-badge { display: inline-block; padding: 4px 14px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-top: 8px; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-pending { background: #fff3e0; color: #e65100; }

        /* Info Section */
        .info-section { display: table; width: 100%; margin-bottom: 25px; }
        .info-block { display: table-cell; width: 50%; vertical-align: top; }
        .info-label { font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-value { font-size: 12px; color: #333; font-weight: 600; }
        .info-row { margin-bottom: 10px; }

        /* Business Info Box */
        .business-info-box { background: #f8f9fa; border: 1px solid #e0e5ec; border-radius: 8px; padding: 15px 20px; margin-bottom: 25px; }
        .business-info-header { font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; font-weight: 600; border-bottom: 1px solid #e0e5ec; padding-bottom: 5px; }
        .business-name { font-size: 15px; font-weight: bold; color: #344767; margin-bottom: 5px; }

        /* Table */
        .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .invoice-table thead th { background: #344767; color: #fff; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; }
        .invoice-table thead th:last-child { text-align: right; }
        .invoice-table thead th.text-center { text-align: center; }
        .invoice-table tbody td { padding: 12px; border-bottom: 1px solid #eee; font-size: 12px; }
        .invoice-table tbody td:last-child { text-align: right; font-weight: 600; }
        .invoice-table tbody td.text-center { text-align: center; }

        /* Totals */
        .totals-section { display: table; width: 100%; margin-bottom: 30px; }
        .totals-spacer { display: table-cell; width: 55%; }
        .totals-box { display: table-cell; width: 45%; }
        .totals-row { display: table; width: 100%; margin-bottom: 6px; }
        .totals-label { display: table-cell; width: 50%; text-align: left; font-size: 11px; color: #666; padding: 4px 0; }
        .totals-value { display: table-cell; width: 50%; text-align: right; font-size: 12px; font-weight: 600; padding: 4px 0; }
        .totals-total { border-top: 2px solid #344767; margin-top: 6px; padding-top: 8px; }
        .totals-total .totals-label, .totals-total .totals-value { font-size: 14px; font-weight: bold; color: #344767; }

        /* Footer */
        .invoice-footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e5ec; }
        .footer-text { font-size: 10px; color: #999; }
        .footer-legal { font-size: 9px; color: #bbb; margin-top: 8px; font-style: italic; }
    </style>
</head>
<body>
<div class="invoice-container">
    {{-- HEADER --}}
    <div class="invoice-header">
        <div class="header-left">
            <div class="company-name">{{ $company['name'] }}</div>
            <div class="company-info">
                {{ $company['address'] }}<br>
                @if(!empty($company['phone']))Tel: {{ $company['phone'] }}<br>@endif
                @if(!empty($company['email'])){{ $company['email'] }}<br>@endif
                @if(!empty($company['npwp']))NPWP: {{ $company['npwp'] }}@endif
            </div>
        </div>
        <div class="header-right">
            <div class="invoice-title">INVOICE</div>
            <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            <div class="status-badge {{ $invoice->status === 'paid' ? 'status-paid' : 'status-pending' }}">
                {{ $invoice->status === 'paid' ? 'LUNAS' : strtoupper($invoice->status) }}
            </div>
        </div>
    </div>

    {{-- INVOICE DATES --}}
    <div class="info-section">
        <div class="info-block">
            <div class="info-row">
                <div class="info-label">Tanggal Terbit</div>
                <div class="info-value">{{ $formatted['issued_at'] }}</div>
            </div>
            @if($invoice->due_at)
            <div class="info-row">
                <div class="info-label">Jatuh Tempo</div>
                <div class="info-value">{{ $formatted['due_at'] }}</div>
            </div>
            @endif
        </div>
        <div class="info-block">
            @if($invoice->paid_at)
            <div class="info-row">
                <div class="info-label">Tanggal Pembayaran</div>
                <div class="info-value">{{ $formatted['paid_at'] }}</div>
            </div>
            @endif
            <div class="info-row">
                <div class="info-label">Metode Pembayaran</div>
                <div class="info-value">{{ ucfirst($invoice->payment_method ?? '-') }}</div>
            </div>
        </div>
    </div>

    {{-- BUYER INFO --}}
    <div class="business-info-box">
        <div class="business-info-header">Ditagihkan Kepada</div>
        <div class="business-name">{{ $klien?->nama_perusahaan ?? $user?->name ?? '-' }}</div>
        <div class="company-info">
            {{ $klien?->email ?? $user?->email ?? '-' }}<br>
            @if($klien?->no_telepon)Tel: {{ $klien->no_telepon }}<br>@endif
            @if($klien?->alamat){{ $klien->alamat }}@if($klien?->kota), {{ $klien->kota }}@endif @if($klien?->provinsi), {{ $klien->provinsi }}@endif<br>@endif
            @if($invoice->snapshot_npwp)NPWP: {{ $invoice->snapshot_npwp }}@endif
        </div>
    </div>

    {{-- ITEMS TABLE --}}
    <table class="invoice-table">
        <thead>
            <tr>
                <th>Deskripsi</th>
                <th class="text-center">Qty</th>
                <th style="text-align:right;">Harga Satuan</th>
                <th style="text-align:right;">Pajak</th>
                <th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>
                    <strong>{{ $item->item_name }}</strong>
                    @if($item->item_description)
                    <br><span style="font-size:10px; color:#666;">{{ $item->item_description }}</span>
                    @endif
                </td>
                <td class="text-center">{{ number_format($item->quantity, 0) }} {{ $item->unit ?? '' }}</td>
                <td style="text-align:right;">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td style="text-align:right;">Rp {{ number_format($item->tax_amount, 0, ',', '.') }}</td>
                <td style="text-align:right;">Rp {{ number_format($item->total_amount, 0, ',', '.') }}</td>
            </tr>
            @empty
            {{-- Fallback to JSON line_items --}}
            @foreach($invoice->line_items ?? [] as $lineItem)
            <tr>
                <td>{{ $lineItem['name'] ?? $lineItem['description'] ?? '-' }}</td>
                <td class="text-center">{{ $lineItem['qty'] ?? $lineItem['quantity'] ?? 1 }}</td>
                <td style="text-align:right;">Rp {{ number_format($lineItem['price'] ?? $lineItem['unit_price'] ?? 0, 0, ',', '.') }}</td>
                <td style="text-align:right;">-</td>
                <td style="text-align:right;">Rp {{ number_format($lineItem['total'] ?? 0, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            @endforelse
        </tbody>
    </table>

    {{-- TOTALS --}}
    <div class="totals-section">
        <div class="totals-spacer"></div>
        <div class="totals-box">
            <div class="totals-row">
                <div class="totals-label">Subtotal (DPP)</div>
                <div class="totals-value">{{ $formatted['subtotal'] }}</div>
            </div>
            @if(($invoice->discount ?? 0) > 0)
            <div class="totals-row">
                <div class="totals-label">Diskon</div>
                <div class="totals-value" style="color:#c62828;">- {{ $formatted['discount'] }}</div>
            </div>
            @endif
            <div class="totals-row">
                <div class="totals-label">PPN ({{ $invoice->tax_rate ?? config('tax.default_tax_rate', 11) }}%)</div>
                <div class="totals-value">{{ $formatted['tax'] }}</div>
            </div>
            <div class="totals-row totals-total">
                <div class="totals-label">TOTAL</div>
                <div class="totals-value">{{ $formatted['total'] }}</div>
            </div>
        </div>
    </div>

    {{-- FOOTER --}}
    <div class="invoice-footer">
        <div class="footer-text">
            Terima kasih atas kepercayaan Anda menggunakan <strong>{{ $company['name'] }}</strong>.<br>
            Invoice ini dibuat secara otomatis dan sah tanpa tanda tangan.
        </div>
        <div class="footer-legal">
            Dokumen ini merupakan bukti pembayaran yang sah. Dicetak pada {{ now()->translatedFormat('d F Y, H:i') }} WIB.
        </div>
    </div>
</div>
</body>
</html>
