<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Transcript — {{ $report['student']['matric_number'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 28px; }
        h1 { font-size: 18px; margin: 0 0 4px; text-align: center; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .meta { text-align: center; margin-bottom: 16px; color: #333; }
        .grid { display: flex; gap: 16px; margin-bottom: 16px; }
        .card { flex: 1; border: 1px solid #ddd; border-radius: 8px; padding: 10px 12px; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .value { font-size: 20px; font-weight: 700; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; font-size: 11px; }
        .sign { margin-top: 36px; display: flex; justify-content: space-between; gap: 40px; }
        .sign-block { width: 45%; }
        .line { border-top: 1px solid #111; margin-top: 48px; padding-top: 6px; font-size: 11px; }
        .footer { margin-top: 24px; font-size: 10px; color: #555; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ $report['university'] ?? 'University' }}</h1>
    <div class="meta">
        <div><strong>Official Academic Transcript</strong></div>
        <div>{{ $report['student']['name'] ?? '' }} · {{ $report['student']['matric_number'] ?? '' }}</div>
        @if (!empty($report['student']['programme']))
            <div>{{ $report['student']['programme'] }}</div>
        @endif
        <div>Issued {{ $report['generated_at'] ?? '' }}</div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="label">CGPA</div>
            <div class="value">{{ $report['cgpa'] ?? '—' }}</div>
        </div>
        <div class="card">
            <div class="label">Total credits</div>
            <div class="value">{{ $report['total_credits'] ?? '—' }}</div>
        </div>
    </div>

    @forelse($report['terms'] ?? [] as $term)
        <h2>{{ $term['session_label'] ?? '' }} · {{ $term['name'] ?? '' }} — GPA {{ $term['gpa'] ?? '—' }}</h2>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Units</th>
                    <th>Score</th>
                    <th>Grade</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                @foreach($term['rows'] ?? [] as $row)
                    <tr>
                        <td>{{ $row['course']['code'] ?? '—' }}</td>
                        <td>{{ $row['course']['title'] ?? '—' }}</td>
                        <td>{{ $row['course']['units'] ?? '—' }}</td>
                        <td>{{ $row['score'] ?? '—' }}</td>
                        <td>{{ $row['letter'] ?? '—' }}</td>
                        <td>{{ $row['points'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>No released grades are available on this transcript.</p>
    @endforelse

    <div class="sign">
        <div class="sign-block">
            <div class="line">
                Registrar / authorised officer<br>
                {{ $report['signed_by'] ?? '' }}
            </div>
        </div>
        <div class="sign-block">
            <div class="line">
                Date / official stamp<br>
                {{ $report['generated_at'] ?? '' }}
            </div>
        </div>
    </div>

    <div class="footer">
        Official transcript · Request {{ $report['request_token'] ?? '' }}
        @if (!empty($report['copies']))
            · Copies: {{ $report['copies'] }}
        @endif
    </div>
</body>
</html>
