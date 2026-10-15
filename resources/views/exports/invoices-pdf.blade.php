<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 1.1cm 0.8cm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8pt; color: #1e293b; }
        .header { text-align: center; border-bottom: 2px solid #0c4a6e; padding-bottom: 8pt; margin-bottom: 10pt; }
        .header img { width: 46pt; height: 46pt; object-fit: contain; margin: 0 auto 5pt; display: block; }
        .header h1 { margin: 0; font-size: 14pt; color: #0c4a6e; }
        .header .motto { margin: 2pt 0 0; font-size: 8pt; font-style: italic; color: #64748b; }
        .report-title { text-align: center; font-size: 11pt; font-weight: bold; margin: 0 0 3pt; }
        .meta { text-align: center; font-size: 7.5pt; color: #64748b; margin-bottom: 8pt; }
        table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
        th, td { border: 1px solid #cbd5e1; padding: 3pt 4pt; text-align: left; vertical-align: top; }
        th { background: #0c4a6e; color: #ffffff; font-weight: bold; }
        tr:nth-child(even) td { background: #f8fafc; }
        .footer { margin-top: 8pt; font-size: 7.5pt; color: #64748b; text-align: right; }
        .num { text-align: right; }
        .total td { background: #e0f2fe; font-weight: bold; color: #0c4a6e; }
    </style>
</head>
<body>
    @php
        $isPayments = ($variant ?? 'invoices') === 'payments';
        $colSpan = $isPayments ? 12 : 12;
    @endphp
    <div class="header">
        @if (!empty($logo_data_uri))
            <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
        @endif
        <h1>{{ $institution['name'] }}</h1>
        @if (!empty($institution['motto']))
            <div class="motto">{{ $institution['motto'] }}</div>
        @endif
    </div>
    <div class="report-title">{{ $title }}</div>
    <div class="meta">
        Generated {{ $generatedAt }} · {{ $count }} record{{ $count === 1 ? '' : 's' }}
        @if (count($filterSummary))
            · Filters: {{ implode('; ', $filterSummary) }}
        @endif
    </div>
    <table>
        <thead>
            <tr>
                @if ($isPayments)
                    <th>S/N</th>
                    <th>Receipt</th>
                    <th>Reference</th>
                    <th>Invoice</th>
                    <th>Payer</th>
                    <th>Matric</th>
                    <th>Category</th>
                    <th>Programme</th>
                    <th>College</th>
                    <th>Gateway</th>
                    <th class="num">Amount</th>
                    <th>Timestamp</th>
                @else
                    <th>S/N</th>
                    <th>Number</th>
                    <th>Payer</th>
                    <th>Matric</th>
                    <th>Category</th>
                    <th>Programme</th>
                    <th>College</th>
                    <th class="num">Amount</th>
                    <th class="num">Balance</th>
                    <th>Gateway</th>
                    <th>Status</th>
                    <th>Timestamp</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    @if ($isPayments)
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $row['receipt'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['number'] }}</td>
                        <td>{{ $row['payer'] }}</td>
                        <td>{{ $row['matric'] }}</td>
                        <td>{{ $row['category'] }}</td>
                        <td>{{ $row['programme'] }}</td>
                        <td>{{ $row['college'] }}</td>
                        <td>{{ $row['gateway'] }}</td>
                        <td class="num">{{ number_format((float) $row['amount'], 2) }}</td>
                        <td>{{ $row['date'] }}</td>
                    @else
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $row['number'] }}</td>
                        <td>{{ $row['payer'] }}</td>
                        <td>{{ $row['matric'] }}</td>
                        <td>{{ $row['category'] }}</td>
                        <td>{{ $row['programme'] }}</td>
                        <td>{{ $row['college'] }}</td>
                        <td class="num">{{ number_format((float) $row['amount'], 2) }}</td>
                        <td class="num">{{ number_format((float) $row['balance'], 2) }}</td>
                        <td>{{ $row['gateway'] ?? '—' }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['date'] }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $colSpan }}" style="text-align:center; color:#64748b;">No records match the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
        @if (!empty($totals))
            <tfoot>
                <tr class="total">
                    @if ($isPayments)
                        <td>Total</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="num">{{ number_format((float) $totals['amount'], 2) }}</td>
                        <td></td>
                    @else
                        <td>Total</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td class="num">{{ number_format((float) $totals['amount'], 2) }}</td>
                        <td class="num">{{ number_format((float) $totals['balance'], 2) }}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                    @endif
                </tr>
            </tfoot>
        @endif
    </table>
    <div class="footer">{{ $institution['name'] }} · {{ $isPayments ? 'Gateway payments report' : 'Invoices report' }}</div>
</body>
</html>
