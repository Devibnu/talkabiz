<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Monthly Closing — {{ $periodLabel }}</title>
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

        /* Period Box */
        .period-box {
            background: #f0f2f5;
            border-left: 4px solid #2dce89;
            padding: 10px 15px;
            margin-bottom: 20px;
        }
        .period-box .label { font-size: 10px; color: #888; text-transform: uppercase; }
        .period-box .value { font-size: 16px; font-weight: bold; color: #344767; }

        /* Status badges */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-closed { background: #2dce89; color: #fff; }
        .badge-draft { background: #11cdef; color: #fff; }
        .badge-failed { background: #f5365c; color: #fff; }
        .badge-match { background: #2dce89; color: #fff; }
        .badge-mismatch { background: #f5365c; color: #fff; }

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
        .summary-table .highlight { color: #2dce89; font-size: 13px; }
        .summary-table .warning-value { color: #fb6340; }
        .summary-table .danger-value { color: #f5365c; }

        /* Detail Table */
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10px;
        }
        .detail-table th {
            background: #f0f2f5;
            border-bottom: 2px solid #344767;
            padding: 6px 8px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #555;
        }
        .detail-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }
        .detail-table .text-right { text-align: right; }
        .detail-table tfoot td {
            font-weight: bold;
            border-top: 2px solid #344767;
            background: #f8f9fa;
            padding: 8px;
        }

        /* Section heading */
        .section-heading {
            font-size: 12px;
            color: #344767;
            margin-bottom: 8px;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }

        /* Recon box */
        .recon-box {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        .recon-box.match { border-left: 4px solid #2dce89; }
        .recon-box.mismatch { border-left: 4px solid #f5365c; }

        /* Digital Signature */
        .signature-box {
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .signature-box .sig-label {
            font-size: 10px;
            color: #888;
            margin-bottom: 40px;
        }
        .signature-box .sig-name {
            font-size: 11px;
            font-weight: bold;
            border-top: 1px solid #333;
            padding-top: 5px;
            display: inline-block;
            min-width: 200px;
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
    </style>
</head>
<body>
<div class="container">

    {{-- ============ HEADER ============ --}}
    <div class="header">
        <div class="header-title">Laporan Monthly Closing Keuangan</div>
        <div class="header-subtitle">Closing & Rekonsiliasi Bulanan — {{ $periodLabel }}</div>
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
        <div class="label">Periode Closing</div>
        <div class="value">{{ $periodLabel }}</div>
    </div>

    {{-- ============ STATUS ============ --}}
    <table class="summary-table">
        <thead>
            <tr><th colspan="2">Status Closing</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="label-cell">Status Finance</td>
                <td class="value-cell">
                    @if($closing->finance_status === 'CLOSED')
                        <span class="badge badge-closed">CLOSED</span>
                    @elseif($closing->finance_status === 'DRAFT')
                        <span class="badge badge-draft">DRAFT</span>
                    @else
                        <span class="badge badge-failed">FAILED</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label-cell">Status Rekonsiliasi</td>
                <td class="value-cell">
                    @if($closing->recon_status === 'MATCH')
                        <span class="badge badge-match">MATCH</span>
                    @else
                        <span class="badge badge-mismatch">MISMATCH</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label-cell">Tanggal Closing</td>
                <td class="value-cell">{{ $closing->finance_closed_at ? $closing->finance_closed_at->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            <tr>
                <td class="label-cell">Ditutup Oleh</td>
                <td class="value-cell">{{ $closing->financeClosedBy?->name ?? '-' }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ============ REVENUE SUMMARY ============ --}}
    <table class="summary-table">
        <thead>
            <tr><th colspan="2">Ringkasan Revenue (Invoice = SSOT)</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="label-cell">Jumlah Invoice (PAID)</td>
                <td class="value-cell">{{ number_format($closing->invoice_count ?? 0) }}</td>
            </tr>
            <tr>
                <td class="label-cell">Revenue Langganan (Subscription)</td>
                <td class="value-cell">Rp {{ number_format((float) ($closing->invoice_subscription_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label-cell">Revenue Topup Saldo</td>
                <td class="value-cell">Rp {{ number_format((float) ($closing->invoice_topup_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label-cell">Revenue Lain-lain (Addon, dll)</td>
                <td class="value-cell">Rp {{ number_format((float) ($closing->invoice_other_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="label-cell" style="font-weight: bold;">Gross Revenue (Total DPP)</td>
                <td class="value-cell highlight">Rp {{ number_format((float) ($closing->invoice_gross_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label-cell">Total PPN</td>
                <td class="value-cell warning-value">Rp {{ number_format((float) ($closing->invoice_total_ppn ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="label-cell" style="font-weight: bold;">Net Revenue</td>
                <td class="value-cell" style="font-size: 13px;">Rp {{ number_format((float) ($closing->invoice_net_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- ============ BREAKDOWN PER TIPE ============ --}}
    @if($closing->finance_revenue_snapshot)
        <h3 class="section-heading">Breakdown Revenue per Tipe Invoice</h3>
        <table class="detail-table">
            <thead>
                <tr>
                    <th>Tipe Invoice</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">DPP (Rp)</th>
                    <th class="text-right">PPN (Rp)</th>
                    <th class="text-right">Bruto (Rp)</th>
                </tr>
            </thead>
            <tbody>
                @php $totQty = 0; $totDpp = 0; $totPpn = 0; $totBruto = 0; @endphp
                @foreach($closing->finance_revenue_snapshot as $type => $data)
                    @php
                        $totQty += $data['count'] ?? 0;
                        $totDpp += $data['dpp'] ?? 0;
                        $totPpn += $data['ppn'] ?? 0;
                        $totBruto += $data['bruto'] ?? 0;
                    @endphp
                    <tr>
                        <td>{{ ucfirst(str_replace('_', ' ', $type)) }}</td>
                        <td class="text-right">{{ number_format($data['count'] ?? 0) }}</td>
                        <td class="text-right">{{ number_format($data['dpp'] ?? 0, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($data['ppn'] ?? 0, 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($data['bruto'] ?? 0, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL</td>
                    <td class="text-right">{{ number_format($totQty) }}</td>
                    <td class="text-right">{{ number_format($totDpp, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($totPpn, 0, ',', '.') }}</td>
                    <td class="text-right">{{ number_format($totBruto, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- ============ REKONSILIASI ============ --}}
    <h3 class="section-heading">Rekonsiliasi Keuangan (Invoice vs Wallet)</h3>
    <div class="recon-box {{ ($closing->recon_status ?? '') === 'MATCH' ? 'match' : 'mismatch' }}">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; padding: 4px 0; color: #666; font-size: 11px;">Topup Invoice (DPP)</td>
                <td style="text-align: right; font-weight: bold; font-size: 11px;">Rp {{ number_format((float) ($closing->invoice_topup_revenue ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #666; font-size: 11px;">Topup Wallet (completed)</td>
                <td style="text-align: right; font-weight: bold; font-size: 11px;">Rp {{ number_format((float) ($closing->recon_wallet_topup ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr style="border-top: 1px solid #ddd;">
                <td style="padding: 4px 0; color: #666; font-size: 11px;">Selisih Topup</td>
                <td style="text-align: right; font-weight: bold; font-size: 11px; color: {{ ($closing->recon_topup_discrepancy ?? 0) > 0 ? '#f5365c' : '#2dce89' }};">
                    Rp {{ number_format((float) ($closing->recon_topup_discrepancy ?? 0), 0, ',', '.') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #666; font-size: 11px;">Wallet Usage (periode)</td>
                <td style="text-align: right; font-size: 11px;">Rp {{ number_format((float) ($closing->recon_wallet_usage ?? 0), 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td style="padding: 4px 0; color: #666; font-size: 11px;">Saldo Negatif</td>
                <td style="text-align: right; font-size: 11px;">
                    {{ $closing->recon_has_negative_balance ? 'YA ⚠️' : 'Tidak ✓' }}
                </td>
            </tr>
        </table>

        @if($closing->finance_discrepancy_notes)
            <div style="margin-top: 10px; padding: 8px 12px; background: #fff3cd; border-radius: 4px; font-size: 10px; color: #856404;">
                <strong>Catatan:</strong> {{ $closing->finance_discrepancy_notes }}
            </div>
        @endif
    </div>

    {{-- ============ DIGITAL SIGNATURE ============ --}}
    <div class="signature-box">
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 30px;">
                    <div class="sig-label">Disiapkan oleh Sistem,</div>
                    <div class="sig-name">
                        {{ $companyInfo['name'] }}<br>
                        <span style="font-weight: normal; font-size: 9px; color: #888;">Automated Finance Closing System</span>
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <div class="sig-label">Disetujui oleh,</div>
                    <div class="sig-name">
                        {{ $closing->financeClosedBy?->name ?? '___________________' }}<br>
                        <span style="font-weight: normal; font-size: 9px; color: #888;">Owner / Authorized Person</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============ FOOTER ============ --}}
    <div class="footer">
        <p class="legal">
            Dokumen ini digenerate secara otomatis oleh sistem {{ $companyInfo['name'] }}.
            Laporan Monthly Closing ini merupakan rekapitulasi pendapatan (Invoice = SSOT)
            dan rekonsiliasi cross-check terhadap wallet pada periode {{ $periodLabel }}.
            Dokumen yang berstatus CLOSED bersifat final dan tidak dapat diubah.
        </p>
        <p>
            Dicetak: {{ now()->format('d/m/Y H:i:s') }} WIB
            &nbsp;|&nbsp;
            NPWP: {{ $companyInfo['npwp'] }}
            &nbsp;|&nbsp;
            Status: {{ $closing->finance_status ?? '-' }}
            &nbsp;|&nbsp;
            Rekon: {{ $closing->recon_status ?? '-' }}
        </p>
        <p class="hash">
            Integrity Hash (SHA-256): {{ $closing->finance_closing_hash ?? '-' }}
        </p>
    </div>

</div>
</body>
</html>
