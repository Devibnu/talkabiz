<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan PPN — {{ $periodLabel }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.5;
        }
        .container { padding: 20px 30px; }

        /* Header */
        .header {
            border-bottom: 3px solid #344767;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header-title {
            font-size: 18px;
            font-weight: bold;
            color: #344767;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header-subtitle {
            font-size: 13px;
            color: #666;
            margin-top: 2px;
        }
        .company-info {
            margin-top: 10px;
            font-size: 10px;
            color: #555;
        }
        .company-info strong { color: #333; }

        /* Period Label */
        .period-box {
            background: #f0f2f5;
            border-left: 4px solid #fb6340;
            padding: 10px 15px;
            margin-bottom: 20px;
        }
        .period-box .label { font-size: 10px; color: #888; text-transform: uppercase; }
        .period-box .value { font-size: 16px; font-weight: bold; color: #344767; }

        /* Summary Table */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .summary-table th {
            background: #344767;
            color: #fff;
            text-align: left;
            padding: 8px 12px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .summary-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 11px;
        }
        .summary-table .label-cell { color: #666; width: 50%; }
        .summary-table .value-cell { text-align: right; font-weight: bold; }
        .summary-table .total-row { background: #f8f9fa; }
        .summary-table .ppn-value { color: #fb6340; font-size: 13px; }

        /* Detail Table */
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9px;
        }
        .detail-table th {
            background: #f0f2f5;
            border-bottom: 2px solid #344767;
            padding: 6px 8px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
        }
        .detail-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }
        .detail-table .text-right { text-align: right; }
        .detail-table .text-center { text-align: center; }
        .detail-table tfoot td {
            font-weight: bold;
            border-top: 2px solid #344767;
            background: #f8f9fa;
            padding: 8px;
        }

        /* Footer */
        .footer {
            border-top: 2px solid #344767;
            padding-top: 12px;
            margin-top: 25px;
            font-size: 9px;
            color: #888;
        }
        .footer .legal {
            font-style: italic;
            margin-bottom: 5px;
        }
        .footer .hash {
            font-family: 'Courier New', monospace;
            font-size: 8px;
            color: #aaa;
        }

        /* Page break helper */
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
<div class="container">

    {{-- ============ HEADER ============ --}}
    <div class="header">
        <div class="header-title">Laporan Pajak Pertambahan Nilai (PPN)</div>
        <div class="header-subtitle">Rekapitulasi Bulanan — {{ $periodLabel }}</div>
        <div class="company-info">
            <strong>{{ $companyInfo['name'] }}</strong><br>
            NPWP: {{ $companyInfo['npwp'] }}<br>
            {{ $companyInfo['address'] }}<br>
            @if($companyInfo['phone'] !== '-')Tel: {{ $companyInfo['phone'] }} | @endif
            Email: {{ $companyInfo['email'] }}
        </div>
    </div>

    {{-- ============ PERIOD BOX ============ --}}
    <div class="period-box">
        <div class="label">Masa Pajak</div>
        <div class="value">{{ $periodLabel }}</div>
    </div>

    {{-- ============ RINGKASAN ============ --}}
    <table class="summary-table">
        <thead>
            <tr><th colspan="2">Ringkasan PPN</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="label-cell">Jumlah Faktur / Invoice</td>
                <td class="value-cell">{{ number_format($report->total_invoices) }}</td>
            </tr>
            <tr>
                <td class="label-cell">Tarif PPN</td>
                <td class="value-cell">{{ $report->tax_rate }}%</td>
            </tr>
            <tr>
                <td class="label-cell">Total Dasar Pengenaan Pajak (DPP)</td>
                <td class="value-cell">Rp {{ number_format((float) $report->total_dpp, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="label-cell" style="font-weight: bold;">Total PPN Keluaran</td>
                <td class="value-cell ppn-value">Rp {{ number_format((float) $report->total_ppn, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label-cell">Total Bruto (DPP + PPN)</td>
                <td class="value-cell">Rp {{ number_format((float) $report->total_amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label-cell">Status Laporan</td>
                <td class="value-cell">{{ strtoupper($report->status) }}</td>
            </tr>
            <tr>
                <td class="label-cell">Tanggal Generate</td>
                <td class="value-cell">{{ $report->generated_at ? $report->generated_at->format('d/m/Y H:i') : '-' }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ============ DETAIL INVOICE ============ --}}
    @if($invoices->count() > 0)
    <h3 style="font-size: 12px; color: #344767; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
        Detail Faktur / Invoice
    </h3>
    <table class="detail-table">
        <thead>
            <tr>
                <th>No</th>
                <th>No Invoice</th>
                <th>Tipe</th>
                <th>Tgl Bayar</th>
                <th class="text-right">DPP (Rp)</th>
                <th class="text-center">PPN %</th>
                <th class="text-right">PPN (Rp)</th>
                <th class="text-right">Total (Rp)</th>
                <th>NPWP Pembeli</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoices as $i => $inv)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $inv->formatted_invoice_number ?: $inv->invoice_number }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $inv->type)) }}</td>
                    <td>{{ $inv->paid_at ? $inv->paid_at->format('d/m/Y') : '-' }}</td>
                    <td class="text-right">{{ number_format((float) $inv->subtotal, 0, ',', '.') }}</td>
                    <td class="text-center">{{ $inv->tax_rate }}%</td>
                    <td class="text-right">{{ number_format((float) $inv->tax_amount, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format((float) $inv->total, 0, ',', '.') }}</td>
                    <td>{{ $inv->buyer_npwp ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align: left;">TOTAL</td>
                <td class="text-right">{{ number_format((float) $report->total_dpp, 0, ',', '.') }}</td>
                <td></td>
                <td class="text-right" style="color: #fb6340;">{{ number_format((float) $report->total_ppn, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format((float) $report->total_amount, 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @else
        <p style="text-align: center; color: #999; padding: 30px 0;">
            Tidak ada invoice PPN pada periode ini.
        </p>
    @endif

    {{-- ============ FOOTER ============ --}}
    <div class="footer">
        <p class="legal">
            Dokumen ini digenerate secara otomatis oleh sistem {{ $companyInfo['name'] }}.
            Laporan ini merupakan rekapitulasi PPN Keluaran berdasarkan invoice yang berstatus PAID
            dengan tax_type PPN pada periode {{ $periodLabel }}.
        </p>
        <p>
            Dicetak: {{ now()->format('d/m/Y H:i:s') }} WIB
            &nbsp;|&nbsp;
            NPWP: {{ $companyInfo['npwp'] }}
            &nbsp;|&nbsp;
            Status: {{ strtoupper($report->status) }}
        </p>
        <p class="hash">
            Integrity Hash: {{ $report->report_hash }}
        </p>
    </div>

</div>
</body>
</html>
