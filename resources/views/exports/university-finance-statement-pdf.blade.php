<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 1.2cm 1.1cm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; color: #1e293b; }
        .header { text-align: center; border-bottom: 2px solid #0c4a6e; padding-bottom: 8pt; margin-bottom: 10pt; }
        .header img { width: 46pt; height: 46pt; object-fit: contain; margin: 0 auto 5pt; display: block; }
        .header h1 { margin: 0; font-size: 14pt; color: #0c4a6e; }
        .header .motto { margin: 2pt 0 0; font-size: 8pt; font-style: italic; color: #64748b; }
        .report-title { text-align: center; font-size: 11pt; font-weight: bold; margin: 0 0 3pt; }
        .meta { text-align: center; font-size: 8pt; color: #64748b; margin-bottom: 10pt; }
        h2 { font-size: 10pt; color: #0c4a6e; margin: 10pt 0 4pt; }
        table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-bottom: 8pt; }
        th, td { border: 1px solid #cbd5e1; padding: 4pt 5pt; text-align: left; vertical-align: top; }
        th { background: #0c4a6e; color: #ffffff; font-weight: bold; }
        tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; }
        .total td { font-weight: bold; background: #e0f2fe; }
        .note { font-size: 7.5pt; color: #64748b; margin: 0 0 8pt; }
        .footer { margin-top: 10pt; font-size: 7.5pt; color: #64748b; text-align: right; }
        .cols { width: 100%; }
        .cols td { width: 50%; border: 0; padding: 0 6pt 0 0; background: transparent; vertical-align: top; }
        .cols td + td { padding: 0 0 0 6pt; }
    </style>
</head>
<body>
    @php
        $institution = $statement['institution'];
        $totals = $statement['totals'];
    @endphp
    <div class="header">
        @if (!empty($logo_data_uri))
            <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
        @endif
        <h1>{{ $institution['name'] }}</h1>
        @if (!empty($institution['motto']))
            <div class="motto">{{ $institution['motto'] }}</div>
        @endif
        @if (!empty($institution['address']))
            <div class="motto">{{ $institution['address'] }}</div>
        @endif
    </div>
    <div class="report-title">{{ $title }}</div>
    <div class="meta">Period: {{ $statement['period']['label'] }} · Generated {{ $generatedAt }} · Bursary Department</div>
    <p class="note">Fee collections exclude wallet top-ups. Outstanding receivables and wallet liability are current balances, not limited to the selected period.</p>

    <table class="cols">
        <tr>
            <td>
                <h2>Statement of receipts</h2>
                <table>
                    <tr><td>Fee collections</td><td class="num">{{ $naira($totals['collected']) }}</td></tr>
                    <tr><td>Wallet top-ups</td><td class="num">{{ $naira($totals['wallet_inflows']) }}</td></tr>
                    <tr><td>Cash received (Paystack / Wema / import / bank)</td><td class="num">{{ $naira($totals['cash_received']) }}</td></tr>
                    <tr><td>Wallet applied to invoices</td><td class="num">{{ $naira($totals['wallet_applied']) }}</td></tr>
                    <tr class="total"><td>Total receipts</td><td class="num">{{ $naira($totals['receipts']) }}</td></tr>
                </table>
            </td>
            <td>
                <h2>Financial position</h2>
                <table>
                    <tr><td>Invoices issued (period)</td><td class="num">{{ $naira($totals['invoiced']) }}</td></tr>
                    <tr><td>Rebates granted (period)</td><td class="num">{{ $naira($totals['rebates']) }}</td></tr>
                    <tr><td>Outstanding receivables (now)</td><td class="num">{{ $naira($totals['outstanding']) }}</td></tr>
                    <tr class="total"><td>Student wallet liability (now)</td><td class="num">{{ $naira($totals['wallet_liability']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <h2>Income by fee category</h2>
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Invoiced</th>
                <th class="num">Collected</th>
                <th class="num">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement['by_category'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $naira($row['invoiced']) }}</td>
                    <td class="num">{{ $naira($row['collected']) }}</td>
                    <td class="num">{{ $naira($row['outstanding']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#64748b;">No fee activity in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Income by fee item / levy</h2>
    <p class="note">Component breakdown for remittance reporting (e.g. BUPF, BUSA). Collected amounts allocate each payment across the invoice line items.</p>
    <table>
        <thead>
            <tr>
                <th>Fee item</th>
                <th class="num">Invoiced</th>
                <th class="num">Collected</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement['by_fee_item'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $naira($row['invoiced']) }}</td>
                    <td class="num">{{ $naira($row['collected']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center; color:#64748b;">No fee-item activity in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Receipts by payment method</h2>
    <table>
        <thead>
            <tr>
                <th>Method</th>
                <th class="num">Amount</th>
                <th class="num">Payments</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement['by_method'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ $naira($row['amount']) }}</td>
                    <td class="num">{{ $row['payments'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center; color:#64748b;">No receipts in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ $institution['name'] }} · University financial statement · {{ $statement['period']['label'] }}</div>
</body>
</html>
