<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Topup - {{ $invoice['number'] }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 20px;
        }
        
        .logo {
            float: left;
            width: 50%;
        }
        
        .logo h1 {
            color: #007bff;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            margin: 2px 0;
        }
        
        .invoice-info {
            float: right;
            width: 45%;
            text-align: right;
        }
        
        .invoice-info h2 {
            color: #007bff;
            font-size: 18px;
            margin-bottom: 10px;
        }
        
        .invoice-info p {
            margin: 2px 0;
        }
        
        .clearfix {
            clear: both;
        }
        
        .billing-info {
            margin: 30px 0;
        }
        
        .billing-info .left {
            float: left;
            width: 48%;
        }
        
        .billing-info .right {
            float: right;
            width: 48%;
        }
        
        .section-title {
            font-weight: bold;
            color: #007bff;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .section-content p {
            margin: 2px 0;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .items-table th {
            background-color: #007bff;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        .total-section {
            float: right;
            width: 300px;
            margin: 20px 0;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            padding: 5px 0;
        }
        
        .total-row.final {
            border-top: 2px solid #007bff;
            font-weight: bold;
            font-size: 14px;
            color: #007bff;
        }
        
        .payment-info {
            margin: 30px 0;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        
        .payment-info .section-title {
            color: #28a745;
        }
        
        .status-paid {
            background-color: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .transaction-details {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <h1>{{ $company['name'] }}</h1>
            <p>{{ $company['address'] }}</p>
            <p>Telp: {{ $company['phone'] }}</p>
            <p>Email: {{ $company['email'] }}</p>
            <p>Website: {{ $company['website'] }}</p>
        </div>
        <div class="invoice-info">
            <h2>INVOICE TOPUP</h2>
            <p><strong>No. Invoice:</strong> {{ $invoice['number'] }}</p>
            <p><strong>Tanggal:</strong> {{ $invoice['date'] }}</p>
            <p><strong>Jatuh Tempo:</strong> {{ $invoice['due_date'] }}</p>
        </div>
        <div class="clearfix"></div>
    </div>

    <!-- Billing Information -->
    <div class="billing-info">
        <div class="left">
            <div class="section-title">TAGIH KEPADA:</div>
            <div class="section-content">
                <p><strong>{{ $customer['name'] }}</strong></p>
                
                @if(isset($customer['business_type']) && $customer['business_type'])
                    <p style="font-style: italic; color: #666; font-size: 11px;">{{ $customer['business_type'] }}</p>
                @endif
                
                @if(isset($customer['npwp']) && $customer['npwp'])
                    <p style="background: #e8f5e9; color: #2e7d32; display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; margin: 5px 0;">
                        NPWP: {{ $customer['npwp'] }}
                    </p>
                    @if(isset($customer['is_pkp']) && $customer['is_pkp'])
                        <span style="background: #e0f2fe; color: #0369a1; display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; margin-left: 5px;">
                            PKP
                        </span>
                    @endif
                    <br>
                @endif
                
                <p>{{ $customer['email'] }}</p>
                @if($customer['phone'] !== '-')
                    <p>{{ $customer['phone'] }}</p>
                @endif
                @if($customer['address'] !== '-')
                    <p>{{ $customer['address'] }}</p>
                @endif
            </div>
        </div>
        <div class="right">
            <div class="section-title">DETAIL PEMBAYARAN:</div>
            <div class="section-content">
                <p><strong>Metode:</strong> {{ $payment['method'] }}</p>
                <p><strong>Referensi:</strong> {{ $payment['reference'] }}</p>
                <p><strong>Status:</strong> <span class="status-paid">{{ $payment['status'] }}</span></p>
                <p><strong>Dibayar:</strong> {{ $payment['paid_at'] }}</p>
            </div>
        </div>
        <div class="clearfix"></div>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 50%">Deskripsi</th>
                <th style="width: 15%" class="text-center">Qty</th>
                <th style="width: 20%" class="text-right">Harga Satuan</th>
                <th style="width: 15%" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td>{{ $item['description'] }}</td>
                <td class="text-center">{{ $item['quantity'] }}</td>
                <td class="text-right">Rp {{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td class="text-right">Rp {{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Total Section -->
    <div class="total-section">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>Rp {{ number_format($totals['subtotal'], 0, ',', '.') }}</span>
        </div>
        @if($totals['tax_rate'] > 0)
        <div class="total-row">
            <span>Pajak ({{ $totals['tax_rate'] }}%):</span>
            <span>Rp {{ number_format($totals['tax_amount'], 0, ',', '.') }}</span>
        </div>
        @endif
        <div class="total-row final">
            <span>TOTAL:</span>
            <span>Rp {{ number_format($totals['total'], 0, ',', '.') }}</span>
        </div>
    </div>
    <div class="clearfix"></div>

    <!-- Payment Information -->
    <div class="payment-info">
        <div class="section-title">INFORMASI TOPUP</div>
        <p><strong>Saldo berhasil ditambahkan ke wallet Anda.</strong></p>
        <p>Transaksi ID: {{ $transaction->id }}</p>
        <p>Jumlah topup: Rp {{ number_format($transaction->amount, 0, ',', '.') }}</p>
        <p>Waktu pemrosesan: {{ $transaction->processed_at?->format('d M Y H:i:s') ?? $transaction->created_at->format('d M Y H:i:s') }}</p>
        
        @if(isset($transaction->metadata['balance_after']))
        <div class="transaction-details">
            <strong>Detail Saldo:</strong><br>
            Saldo sebelum: Rp {{ number_format($transaction->balance_before, 0, ',', '.') }}<br>
            Saldo sesudah: Rp {{ number_format($transaction->balance_after, 0, ',', '.') }}
        </div>
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>Terima kasih telah menggunakan layanan {{ $company['name'] }}.</p>
        <p>Invoice ini dibuat secara otomatis dan sah tanpa tanda tangan.</p>
        <p>Untuk pertanyaan, hubungi {{ $company['email'] }} atau {{ $company['phone'] }}</p>
    </div>
</body>
</html>