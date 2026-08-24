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
    </style>
</head>
<body>
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
                <th>S/N</th>
                <th>Student</th>
                <th>Matric</th>
                <th>Programme</th>
                <th>College</th>
                <th>Level</th>
                <th class="num">Wallet</th>
                <th class="num">Billed</th>
                <th class="num">Paid</th>
                <th class="num">Outstanding</th>
                <th>Clearance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['matric'] }}</td>
                    <td>{{ $row['programme'] }}</td>
                    <td>{{ $row['college'] }}</td>
                    <td>{{ $row['level'] }}</td>
                    <td class="num">{{ $row['wallet'] }}</td>
                    <td class="num">{{ $row['billed'] }}</td>
                    <td class="num">{{ $row['paid'] }}</td>
                    <td class="num">{{ $row['outstanding'] }}</td>
                    <td>{{ $row['clearance'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align:center; color:#64748b;">No students match the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="footer">{{ $institution['name'] }} · Students Financial Status</div>
</body>
</html>
