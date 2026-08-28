<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unsigned Transcript — {{ $report['student']['matric_number'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; text-align: center; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .meta { text-align: center; margin-bottom: 14px; color: #333; }
        .banner {
            border: 2px solid #b45309;
            background: #fffbeb;
            color: #92400e;
            padding: 10px 12px;
            margin: 0 0 16px;
            text-align: center;
            font-weight: 600;
        }
        .grid { display: flex; gap: 16px; margin-bottom: 16px; }
        .card { flex: 1; border: 1px solid #ddd; border-radius: 8px; padding: 10px 12px; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
        .value { font-size: 20px; font-weight: 700; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; font-size: 11px; }
        .footer { margin-top: 20px; font-size: 11px; color: #555; text-align: center; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print" style="text-align:right"><button onclick="window.print()">Print</button></p>
    <h1>{{ $report['university'] ?? 'University' }}</h1>
    <div class="meta">
        <div>Unsigned Transcript — Registered Courses</div>
        <div>{{ $report['student']['name'] ?? '' }} · {{ $report['student']['matric_number'] ?? '' }}</div>
        @if(!empty($report['student']['programme']))
            <div>{{ $report['student']['programme'] }}</div>
        @endif
        @if(!empty($report['scope_label']))
            <div>{{ $report['scope_label'] }}</div>
        @endif
        <div>Generated {{ $report['generated_at'] ?? '' }}</div>
    </div>

    <div class="banner">
        UNSIGNED — FOR STUDENT VIEWING ONLY<br>
        This document is not signed and is not valid for official use.
    </div>

    <div class="grid">
        <div class="card">
            <div class="label">GPA (released results)</div>
            <div class="value">{{ isset($report['gpa']) && $report['gpa'] !== null && $report['gpa'] !== '' ? number_format((float) $report['gpa'], 2) : '—' }}</div>
        </div>
        <div class="card">
            <div class="label">Units registered</div>
            <div class="value">{{ $report['units_registered'] ?? '—' }}</div>
        </div>
        <div class="card">
            <div class="label">Units with results</div>
            <div class="value">{{ $report['total_credits'] ?? '—' }}</div>
        </div>
    </div>

    @forelse($report['terms'] ?? [] as $term)
        <h2>{{ $term['session_label'] ?? '' }} · {{ $term['name'] ?? '' }} — GPA {{ isset($term['gpa']) && $term['gpa'] !== null && $term['gpa'] !== '' ? number_format((float) $term['gpa'], 2) : '—' }}</h2>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Units</th>
                    <th>CA</th>
                    <th>Exam</th>
                    <th>Score</th>
                    <th>Grade</th>
                </tr>
            </thead>
            <tbody>
                @foreach($term['rows'] ?? [] as $row)
                    @php
                        $released = ($row['result_status'] ?? '') === 'released';
                    @endphp
                    <tr>
                        <td>{{ $row['course']['code'] ?? '—' }}</td>
                        <td>
                            {{ $row['course']['title'] ?? '—' }}
                            @if(!empty($row['is_carry_over'])) (Carry-over)@endif
                        </td>
                        <td>{{ $row['course']['units'] ?? '—' }}</td>
                        <td>{{ $released ? ($row['ca_score'] ?? '—') : '—' }}</td>
                        <td>{{ $released ? ($row['exam_score'] ?? '—') : '—' }}</td>
                        <td>{{ $released ? ($row['score'] ?? '—') : 'Pending' }}</td>
                        <td>{{ $released ? ($row['letter'] ?? '—') : 'Pending' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>No registered courses for this session/semester.</p>
    @endforelse

    <div class="footer">
        {{ $report['notice'] ?? 'Released scores only. Official signed transcripts are issued by the Registry.' }}
    </div>
</body>
</html>
