<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 1.2cm 1cm; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9pt;
            color: #1e293b;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0c4a6e;
            padding-bottom: 10pt;
            margin-bottom: 12pt;
        }
        .header img {
            width: 52pt;
            height: 52pt;
            object-fit: contain;
            margin: 0 auto 6pt;
            display: block;
        }
        .header h1 {
            margin: 0;
            font-size: 16pt;
            color: #0c4a6e;
        }
        .header .motto {
            margin: 3pt 0 0;
            font-size: 9pt;
            font-style: italic;
            color: #64748b;
        }
        .header .address {
            margin: 4pt 0 0;
            font-size: 8pt;
            color: #64748b;
        }
        .report-title {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin: 0 0 4pt;
            color: #0f172a;
        }
        .meta {
            text-align: center;
            font-size: 8pt;
            color: #64748b;
            margin-bottom: 10pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 4pt 5pt;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #0c4a6e;
            color: #ffffff;
            font-weight: bold;
        }
        tr:nth-child(even) td { background: #f8fafc; }
        .footer {
            margin-top: 10pt;
            font-size: 8pt;
            color: #64748b;
            text-align: right;
        }
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
        @if (!empty($institution['address']) || !empty($institution['contact']))
            <div class="address">
                {{ collect([$institution['address'], $institution['contact']])->filter()->implode(' · ') }}
            </div>
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
                <th style="width: 5%;">S/N</th>
                <th style="width: 16%;">Student</th>
                <th style="width: 16%;">Email</th>
                <th style="width: 12%;">Matric no.</th>
                @if ($showEntryMode)
                    <th style="width: 10%;">Entry mode</th>
                @endif
                <th style="width: {{ $showEntryMode ? '18%' : '26%' }};">Programme</th>
                <th style="width: 12%;">Session</th>
                <th style="width: 9%;">Tuition</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $row['student'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td>{{ $row['matric'] }}</td>
                    @if ($showEntryMode)
                        <td>{{ $row['entry_mode'] }}</td>
                    @endif
                    <td>{{ $row['programme'] }}</td>
                    <td>{{ $row['session'] }}</td>
                    <td>{{ $row['tuition'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showEntryMode ? 8 : 7 }}" style="text-align:center; color:#64748b;">No registrations match the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ $institution['name'] }} · Registrations report</div>
</body>
</html>
