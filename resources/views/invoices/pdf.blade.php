<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice_details['number'] }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 30px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 20px;
        }
        .company-info {
            width: 60%;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #4f46e5;
            margin-bottom: 5px;
        }
        .invoice-title {
            text-align: right;
            width: 40%;
        }
        .invoice-title h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 5px;
        }
        .invoice-number {
            font-size: 14px;
            color: #666;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 11px;
            text-transform: uppercase;
            margin-top: 5px;
        }
        .status-paid {
            background-color: #dcfce7;
            color: #166534;
        }
        .status-pending {
            background-color: #fef9c3;
            color: #854d0e;
        }
        .status-expired {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .parties {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .party-box {
            width: 48%;
            padding: 15px;
            background-color: #f8fafc;
            border-radius: 6px;
        }
        .party-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
            border-bottom: 1px solid #e0e5ec;
            padding-bottom: 5px;
        }
        .party-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
            color: #4f46e5;
        }
        .party-type {
            font-size: 10px;
            color: #666;
            font-style: italic;
            margin-bottom: 8px;
        }
        .party-detail {
            font-size: 11px;
            color: #555;
            margin-bottom: 3px;
            line-height: 1.6;
        }
        .npwp-badge {
            display: inline-block;
            background-color: #e0e7ff;
            color: #4338ca;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-top: 5px;
        }
        .invoice-info {
            margin-bottom: 25px;
        }
        .invoice-info table {
            width: 100%;
        }
        .invoice-info td {
            padding: 5px 10px;
            font-size: 11px;
        }
        .invoice-info .label {
            color: #666;
            width: 120px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .items-table th {
            background-color: #4f46e5;
            color: white;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
        }
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .items-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .tax-breakdown {
            width: 350px;
            margin-left: auto;
            margin-bottom: 30px;
        }
        .tax-breakdown table {
            width: 100%;
            border-collapse: collapse;
        }
        .tax-breakdown td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .tax-breakdown .label {
            color: #555;
        }
        .tax-breakdown .value {
            text-align: right;
            font-weight: 500;
        }
        .tax-breakdown .total-row {
            background-color: #4f46e5;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .tax-breakdown .total-row td {
            border-bottom: none;
            padding: 12px;
        }
        .tax-info {
            background-color: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .tax-info-title {
            color: #0369a1;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 12px;
        }
        .tax-info-detail {
            font-size: 11px;
            color: #444;
            margin-bottom: 3px;
        }
        .efaktur-box {
            background-color: #f0fdf4;
            border: 1px solid #22c55e;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .efaktur-title {
            color: #166534;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #666;
        }
        .footer-note {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="company-info">
            <div class="company-name">{{ $seller['name'] }}</div>
            <div style="font-size: 11px; color: #555;">
                {{ $seller['address'] }}<br>
                @if($seller['phone'])Tel: {{ $seller['phone'] }}<br>@endif
                @if($seller['email'])Email: {{ $seller['email'] }}<br>@endif
                @if($seller['npwp'])
                    <span class="npwp-badge">NPWP: {{ $seller['npwp'] }}</span>
                @endif
            </div>
        </div>
        <div class="invoice-title">
            <h1>INVOICE</h1>
            <div class="invoice-number">{{ $invoice_details['number'] }}</div>
            <span class="status-badge status-{{ $invoice_details['status'] }}">
                {{ strtoupper($invoice_details['status']) }}
            </span>
        </div>
    </div>

    <!-- Parties -->
    <div class="parties">
        <div class="party-box">
            <div class="party-label">Tagihan Kepada (Bill To)</div>
            
            @if(isset($invoice) && $invoice->billing_snapshot)
                {{-- Use immutable snapshot data --}}
                <div class="party-name">{{ $invoice->billing_snapshot['business_name'] ?? ($buyer['name'] ?? '-') }}</div>
                
                @if(isset($invoice->billing_snapshot['business_type_name']))
                    <div class="party-type">{{ $invoice->billing_snapshot['business_type_name'] }}</div>
                @endif
                
                @if(!empty($invoice->billing_snapshot['npwp']))
                    <span class="npwp-badge">NPWP: {{ $invoice->billing_snapshot['npwp'] }}</span>
                    @if(isset($invoice->billing_snapshot['is_pkp']) && $invoice->billing_snapshot['is_pkp'])
                        <span class="npwp-badge" style="background-color: #dbeafe;">PKP</span>
                    @endif
                    <br><br>
                @endif
                
                @if(isset($invoice->billing_snapshot['address']))
                    <div class="party-detail">{{ $invoice->billing_snapshot['address'] }}</div>
                @endif
                @if(isset($invoice->billing_snapshot['city']) || isset($invoice->billing_snapshot['province']))
                    <div class="party-detail">
                        {{ $invoice->billing_snapshot['city'] ?? '' }}
                        @if(isset($invoice->billing_snapshot['province']))
                            {{ isset($invoice->billing_snapshot['city']) ? ',' : '' }} {{ $invoice->billing_snapshot['province'] }}
                        @endif
                        @if(isset($invoice->billing_snapshot['postal_code']))
                            {{ $invoice->billing_snapshot['postal_code'] }}
                        @endif
                    </div>
                @endif
                @if(isset($invoice->billing_snapshot['phone']))
                    <div class="party-detail">Tel: {{ $invoice->billing_snapshot['phone'] }}</div>
                @endif
                @if(isset($invoice->billing_snapshot['email']))
                    <div class="party-detail">Email: {{ $invoice->billing_snapshot['email'] }}</div>
                @endif
            @else
                {{-- Fallback to legacy $buyer array --}}
                <div class="party-name">{{ $buyer['name'] }}</div>
                @if($buyer['address'])
                    <div class="party-detail">{{ $buyer['address'] }}</div>
                @endif
                @if($buyer['npwp'])
                    <span class="npwp-badge">NPWP: {{ $buyer['npwp'] }}</span>
                    @if($buyer['is_pkp'])
                        <span class="npwp-badge" style="background-color: #dbeafe;">PKP</span>
                    @endif
                @endif
            @endif
        </div>
        <div class="party-box">
            <div class="party-label">Detail Invoice</div>
            <table style="width: 100%;">
                <tr>
                    <td class="party-detail" style="width: 100px;">Tanggal</td>
                    <td class="party-detail"><strong>{{ $invoice_details['date'] }}</strong></td>
                </tr>
                <tr>
                    <td class="party-detail">Jatuh Tempo</td>
                    <td class="party-detail"><strong>{{ $invoice_details['due_date'] }}</strong></td>
                </tr>
                @if($invoice_details['paid_date'])
                <tr>
                    <td class="party-detail">Tgl Bayar</td>
                    <td class="party-detail"><strong>{{ $invoice_details['paid_date'] }}</strong></td>
                </tr>
                @endif
                <tr>
                    <td class="party-detail">Tipe</td>
                    <td class="party-detail"><strong>{{ ucfirst(str_replace('_', ' ', $invoice_details['type'])) }}</strong></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Line Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 40%;">Deskripsi</th>
                <th style="width: 15%;">Qty</th>
                <th style="width: 20%;">Harga</th>
                <th style="width: 25%;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
            <tr>
                <td>
                    <strong>{{ $item['name'] ?? '-' }}</strong>
                    @if(isset($item['description']) && $item['description'])
                        <br><small style="color: #666;">{{ $item['description'] }}</small>
                    @endif
                </td>
                <td>{{ $item['qty'] ?? 1 }}</td>
                <td>Rp {{ number_format($item['price'] ?? 0, 0, ',', '.') }}</td>
                <td>Rp {{ number_format($item['total'] ?? 0, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" style="text-align: center; color: #888;">Tidak ada item</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Tax Breakdown -->
    <div class="tax-breakdown">
        <table>
            <tr>
                <td class="label">Subtotal</td>
                <td class="value">Rp {{ number_format($tax['subtotal'], 0, ',', '.') }}</td>
            </tr>
            @if($tax['discount'] > 0)
            <tr>
                <td class="label">Diskon</td>
                <td class="value">- Rp {{ number_format($tax['discount'], 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">DPP (Dasar Pengenaan Pajak)</td>
                <td class="value">Rp {{ number_format($tax['dpp'], 0, ',', '.') }}</td>
            </tr>
            @if($tax['tax_type'] !== 'exempt' && $tax['tax_rate'] > 0)
            <tr>
                <td class="label">
                    {{ strtoupper($tax['tax_type']) }} ({{ $tax['tax_rate'] }}%)
                    <small style="color: #888;">{{ $tax['tax_calculation'] }}</small>
                </td>
                <td class="value">Rp {{ number_format($tax['tax_amount'], 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="value">Rp {{ number_format($tax['total'], 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>

    <!-- Tax Info -->
    @if($tax['buyer_npwp'] || $tax['seller_npwp'])
    <div class="tax-info">
        <div class="tax-info-title">Informasi Perpajakan</div>
        @if($tax['seller_npwp'])
        <div class="tax-info-detail"><strong>Penjual:</strong> {{ $seller['name'] }} (NPWP: {{ $tax['seller_npwp'] }})</div>
        @endif
        @if($tax['buyer_npwp'])
        <div class="tax-info-detail"><strong>Pembeli:</strong> {{ $buyer['name'] }} (NPWP: {{ $tax['buyer_npwp'] }})</div>
        @endif
        @if($tax['is_tax_invoice'])
        <div class="tax-info-detail" style="margin-top: 8px;"><em>Invoice ini merupakan Faktur Pajak</em></div>
        @endif
    </div>
    @endif

    <!-- E-Faktur Info -->
    @if($efaktur)
    <div class="efaktur-box">
        <div class="efaktur-title">e-Faktur</div>
        <div style="font-size: 11px;">
            <strong>Nomor:</strong> {{ $efaktur['number'] }}<br>
            <strong>Tanggal:</strong> {{ $efaktur['date'] }}<br>
            <strong>Status:</strong> {{ ucfirst($efaktur['status']) }}
        </div>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <div class="footer-note">
            <strong>Catatan:</strong> Invoice ini diterbitkan secara elektronik dan sah tanpa tanda tangan.
        </div>
        <div class="footer-note">
            Dokumen ini dibuat pada {{ now()->format('d M Y H:i') }} WIB.
        </div>
        @if($tax['is_tax_invoice'] && $tax['efaktur_number'])
        <div class="footer-note">
            Faktur Pajak: {{ $tax['efaktur_number'] }}
        </div>
        @endif
    </div>
</body>
</html>
