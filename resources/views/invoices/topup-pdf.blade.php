<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        /* ==================== RESET & BASE ==================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
            background: #fff;
        }

        /* ==================== LAYOUT ==================== */
        .invoice-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 30px 40px;
        }

        /* ==================== HEADER ==================== */
        .invoice-header {
            display: table;
            width: 100%;
            margin-bottom: 30px;
            border-bottom: 3px solid #344767;
            padding-bottom: 20px;
        }

        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .company-name {
            font-size: 22px;
            font-weight: bold;
            color: #344767;
            margin-bottom: 5px;
        }

        .company-info {
            font-size: 10px;
            color: #666;
            line-height: 1.6;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #344767;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-size: 13px;
            color: #555;
            font-weight: 600;
        }

        /* ==================== STATUS BADGE ==================== */
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 8px;
        }

        .status-paid {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-pending {
            background: #fff3e0;
            color: #e65100;
        }

        .status-failed {
            background: #ffebee;
            color: #c62828;
        }

        /* ==================== INFO SECTION ==================== */
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 25px;
        }

        .info-block {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .info-label {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 12px;
            color: #333;
            font-weight: 600;
        }

        .info-row {
            margin-bottom: 10px;
        }

        /* ==================== BUSINESS INFO BOX ==================== */
        .business-info-box {
            background: #f8f9fa;
            border: 1px solid #e0e5ec;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .business-info-header {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            font-weight: 600;
            border-bottom: 1px solid #e0e5ec;
            padding-bottom: 5px;
        }

        .business-name {
            font-size: 15px;
            font-weight: bold;
            color: #344767;
            margin-bottom: 5px;
        }

        .business-type {
            font-size: 11px;
            color: #666;
            font-style: italic;
            margin-bottom: 8px;
        }

        .business-npwp {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .business-address {
            font-size: 11px;
            color: #555;
            line-height: 1.6;
        }

        /* ==================== TABLE ==================== */
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        .invoice-table thead th {
            background: #344767;
            color: #fff;
            padding: 10px 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .invoice-table thead th:last-child {
            text-align: right;
        }

        .invoice-table tbody td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }

        .invoice-table tbody td:last-child {
            text-align: right;
            font-weight: 600;
        }

        /* ==================== TOTALS ==================== */
        .totals-section {
            display: table;
            width: 100%;
            margin-bottom: 30px;
        }

        .totals-spacer {
            display: table-cell;
            width: 55%;
        }

        .totals-box {
            display: table-cell;
            width: 45%;
        }

        .totals-row {
            display: table;
            width: 100%;
            margin-bottom: 6px;
        }

        .totals-label {
            display: table-cell;
            width: 50%;
            text-align: left;
            font-size: 11px;
            color: #666;
            padding: 4px 0;
        }

        .totals-value {
            display: table-cell;
            width: 50%;
            text-align: right;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 0;
        }

        .totals-total {
            border-top: 2px solid #344767;
            margin-top: 6px;
            padding-top: 8px;
        }

        .totals-total .totals-label,
        .totals-total .totals-value {
            font-size: 14px;
            font-weight: bold;
            color: #344767;
        }

        /* ==================== WALLET INFO ==================== */
        .wallet-info {
            background: #f5f7fa;
            border: 1px solid #e0e5ec;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .wallet-info-title {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .wallet-info-row {
            display: table;
            width: 100%;
            margin-bottom: 4px;
        }

        .wallet-info-label {
            display: table-cell;
            width: 40%;
            font-size: 11px;
            color: #666;
            padding: 3px 0;
        }

        .wallet-info-value {
            display: table-cell;
            width: 60%;
            font-size: 11px;
            color: #333;
            font-weight: 600;
            padding: 3px 0;
        }

        /* ==================== FOOTER ==================== */
        .invoice-footer {
            border-top: 1px solid #ddd;
            padding-top: 15px;
            text-align: center;
            font-size: 10px;
            color: #999;
            line-height: 1.8;
        }

        .footer-note {
            font-style: italic;
            color: #aaa;
            margin-top: 5px;
        }

        /* ==================== WATERMARK ==================== */
        .watermark {
            position: fixed;
            top: 40%;
            left: 20%;
            font-size: 80px;
            color: rgba(46, 125, 50, 0.06);
            transform: rotate(-30deg);
            font-weight: bold;
            z-index: -1;
        }
    </style>
</head>
<body>
    @if($invoice->status === 'paid')
        <div class="watermark">LUNAS</div>
    @endif

    <div class="invoice-container">
        {{-- ==================== HEADER ==================== --}}
        <div class="invoice-header">
            <div class="header-left">
                <div class="company-name">{{ $company['name'] }}</div>
                <div class="company-info">
                    {{ $company['address'] }}<br>
                    {{ $company['phone'] }}<br>
                    {{ $company['email'] }}
                    @if(!empty($company['npwp']) && $company['npwp'] !== '00.000.000.0-000.000')
                        <br>NPWP: {{ $company['npwp'] }}
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#{{ $invoice->invoice_number }}</div>
                <div class="status-badge status-{{ $invoice->status }}">
                    {{ strtoupper($invoice->status) }}
                </div>
            </div>
        </div>

        {{-- ==================== BUSINESS INFO ==================== --}}
        <div class="business-info-box">
            <div class="business-info-header">Tagihan Kepada</div>
            
            @if($invoice->billing_snapshot)
                {{-- Display snapshot data (immutable business info) --}}
                <div class="business-name">{{ $invoice->billing_snapshot['business_name'] ?? '-' }}</div>
                
                @if(isset($invoice->billing_snapshot['business_type_name']))
                    <div class="business-type">{{ $invoice->billing_snapshot['business_type_name'] }}</div>
                @endif
                
                @if(!empty($invoice->billing_snapshot['npwp']))
                    <div class="business-npwp">NPWP: {{ $invoice->billing_snapshot['npwp'] }}</div>
                @endif
                
                <div class="business-address">
                    @if(isset($invoice->billing_snapshot['address']))
                        {{ $invoice->billing_snapshot['address'] }}<br>
                    @endif
                    @if(isset($invoice->billing_snapshot['city']) || isset($invoice->billing_snapshot['province']))
                        {{ $invoice->billing_snapshot['city'] ?? '' }}
                        @if(isset($invoice->billing_snapshot['province']))
                            {{ isset($invoice->billing_snapshot['city']) ? ',' : '' }} {{ $invoice->billing_snapshot['province'] }}
                        @endif
                        @if(isset($invoice->billing_snapshot['postal_code']))
                            {{ $invoice->billing_snapshot['postal_code'] }}
                        @endif
                        <br>
                    @endif
                    @if(isset($invoice->billing_snapshot['phone']))
                        Tel: {{ $invoice->billing_snapshot['phone'] }}<br>
                    @endif
                    @if(isset($invoice->billing_snapshot['email']))
                        Email: {{ $invoice->billing_snapshot['email'] }}
                    @endif
                </div>
            @else
                {{-- Fallback for invoices without snapshot (backward compatibility) --}}
                <div class="business-name">{{ $user->name ?? '-' }}</div>
                <div class="business-address">
                    Email: {{ $user->email ?? '-' }}
                </div>
            @endif
        </div>

        {{-- ==================== INFO ==================== --}}
        <div class="info-section">
            <div class="info-block">
                <div class="info-row">
                    <div class="info-label">Tanggal Invoice</div>
                    <div class="info-value">{{ $formatted['issued_at'] }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Tanggal Bayar</div>
                    <div class="info-value">{{ $formatted['paid_at'] }}</div>
                </div>
            </div>
            <div class="info-block" style="text-align: right;">
                <div class="info-row">
                    <div class="info-label">Metode Pembayaran</div>
                    <div class="info-value">{{ ucfirst($invoice->payment_method ?? '-') }}</div>
                </div>
                @if($invoice->billing_snapshot && isset($invoice->billing_snapshot['is_pkp']) && $invoice->billing_snapshot['is_pkp'])
                    <div class="info-row">
                        <div class="info-label">Status PKP</div>
                        <div class="info-value">
                            <span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 10px;">PKP Terdaftar</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- ==================== ITEMS TABLE ==================== --}}
        <table class="invoice-table">
            <thead>
                <tr>
                    <th style="width: 60%;">Deskripsi</th>
                    <th style="width: 15%; text-align: center;">Qty</th>
                    <th style="width: 25%; text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @if($invoice->line_items && is_array($invoice->line_items))
                    @foreach($invoice->line_items as $item)
                        <tr>
                            <td>{{ $item['description'] ?? 'Topup Saldo' }}</td>
                            <td style="text-align: center;">{{ $item['quantity'] ?? 1 }}</td>
                            <td style="text-align: right;">Rp {{ number_format($item['total'] ?? $invoice->total, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td>Topup Saldo</td>
                        <td style="text-align: center;">1</td>
                        <td style="text-align: right;">{{ $formatted['amount'] }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- ==================== TOTALS ==================== --}}
        <div class="totals-section">
            <div class="totals-spacer"></div>
            <div class="totals-box">
                <div class="totals-row">
                    <div class="totals-label">Subtotal (DPP)</div>
                    <div class="totals-value">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</div>
                </div>
                @if($invoice->discount > 0)
                    <div class="totals-row">
                        <div class="totals-label">Diskon</div>
                        <div class="totals-value">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</div>
                    </div>
                @endif
                @if($invoice->tax_type && $invoice->tax_type !== 'none' && $invoice->tax_amount > 0)
                    <div class="totals-row">
                        <div class="totals-label">{{ $invoice->tax_type }} {{ number_format($invoice->tax_rate, 0) }}%</div>
                        <div class="totals-value">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</div>
                    </div>
                @endif
                <div class="totals-row totals-total">
                    <div class="totals-label">Total Tagihan</div>
                    <div class="totals-value">Rp {{ number_format($invoice->total, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>

        {{-- ==================== WALLET INFO ==================== --}}
        @if($transaction)
            <div class="wallet-info">
                <div class="wallet-info-title">Informasi Wallet</div>
                <div class="wallet-info-row">
                    <div class="wallet-info-label">Transaction ID</div>
                    <div class="wallet-info-value">#{{ $transaction->id }}</div>
                </div>
                <div class="wallet-info-row">
                    <div class="wallet-info-label">Saldo Sebelum</div>
                    <div class="wallet-info-value">Rp {{ number_format($transaction->balance_before ?? 0, 0, ',', '.') }}</div>
                </div>
                <div class="wallet-info-row">
                    <div class="wallet-info-label">Saldo Sesudah</div>
                    <div class="wallet-info-value">Rp {{ number_format($transaction->balance_after ?? 0, 0, ',', '.') }}</div>
                </div>
                @if($transaction->metadata && isset($transaction->metadata['payment_id']))
                    <div class="wallet-info-row">
                        <div class="wallet-info-label">Payment Reference</div>
                        <div class="wallet-info-value">{{ $transaction->metadata['payment_id'] }}</div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ==================== FOOTER ==================== --}}
        <div class="invoice-footer">
            <p>{{ $company['name'] }} &mdash; {{ $company['address'] }}</p>
            <p>{{ $company['email'] }} | {{ $company['phone'] }}</p>
            @if(!empty($company['npwp']) && $company['npwp'] !== '00.000.000.0-000.000')
                <p>NPWP: {{ $company['npwp'] }}</p>
            @endif
            <p class="footer-note">
                Invoice ini sah dan diproses secara elektronik oleh sistem {{ $company['name'] }}.
                <br>Tidak memerlukan tanda tangan basah.
            </p>
            <p style="margin-top: 8px; font-size: 9px; color: #ccc;">
                Generated: {{ now()->format('d M Y H:i:s') }}
            </p>
        </div>
    </div>
</body>
</html>
