<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Submission List — {{ $report['scope'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; text-align: center; }
        h2 { font-size: 13px; margin: 12px 0 6px; }
        .meta { text-align: center; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #333; padding: 3px 4px; vertical-align: top; }
        th { background: #f0f0f0; font-size: 10px; }
        .sig { margin-top: 28px; display: flex; justify-content: space-between; }
        .sig div { width: 40%; text-align: center; border-top: 1px solid #333; padding-top: 4px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print" style="text-align:right"><button onclick="window.print()">Print</button></p>
    <h1>{{ $report['university'] ?? '' }}</h1>
    <div class="meta">
        <div>{{ $report['faculty_name'] ?? '' }}@if(!empty($report['department_name'])) — {{ $report['department_name'] }}@endif</div>
        <div>{{ $report['session_label'] ?? '' }} · {{ $report['semester_name'] ?? '' }} · {{ $report['status_label'] ?? '' }}</div>
        <div>Generated {{ $report['generated_at'] ?? '' }}</div>
    </div>

    @if(($report['layout'] ?? '') === 'department_matrix')
        <table>
            <thead>
                <tr>
                    <th>Matric</th>
                    <th>Entry</th>
                    <th>Level</th>
                    @foreach($report['course_headers'] ?? [] as $course)
                        <th>{{ $course['code'] }} ({{ $course['units'] }})</th>
                    @endforeach
                    <th>GPA</th>
                    <th>CGPA</th>
                    <th>TUR</th>
                    <th>TUP</th>
                    <th>WGP</th>
                    <th>Failed</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['students'] ?? [] as $student)
                    <tr>
                        <td>{{ $student['matric_number'] }}</td>
                        <td>{{ $student['mode_of_entry'] }} {{ $student['year_of_entry'] }}</td>
                        <td>{{ $student['level'] }}</td>
                        @foreach($student['courses'] ?? [] as $cell)
                            <td>{{ $cell['letter'] ?: '—' }}</td>
                        @endforeach
                        <td>{{ $student['gpa'] }}</td>
                        <td>{{ $student['cgpa'] }}</td>
                        <td>{{ $student['tur'] }}</td>
                        <td>{{ $student['tup'] }}</td>
                        <td>{{ $student['wgp'] }}</td>
                        <td>{{ $student['courses_failed'] }}</td>
                        <td>{{ $student['remark'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        @foreach($report['sheets'] ?? [] as $sheet)
            <h2>{{ $sheet['department_name'] }}</h2>
            @foreach($sheet['students'] ?? [] as $student)
                <table>
                    <thead>
                        <tr>
                            <th>Matric</th>
                            @if(!empty($report['include_name']))<th>Name</th>@endif
                            <th>Course</th>
                            <th>CA</th>
                            <th>Exam</th>
                            <th>Total</th>
                            <th>Grade</th>
                            <th>Unit</th>
                            <th>GPA</th>
                            <th>CGPA</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($student['courses'] ?? [] as $i => $course)
                            <tr>
                                @if($i === 0)
                                    <td rowspan="{{ max(1, count($student['courses'])) }}">{{ $student['matric_number'] }}</td>
                                    @if(!empty($report['include_name']))
                                        <td rowspan="{{ max(1, count($student['courses'])) }}">{{ $student['name'] }}</td>
                                    @endif
                                @endif
                                <td>{{ $course['code'] }}</td>
                                <td>{{ $course['ca'] ?? '—' }}</td>
                                <td>{{ $course['exam'] ?? '—' }}</td>
                                <td>{{ $course['total'] ?? '—' }}</td>
                                <td>{{ $course['letter'] ?? '—' }}</td>
                                <td>{{ $course['units'] ?? '—' }}</td>
                                @if($i === 0)
                                    <td rowspan="{{ max(1, count($student['courses'])) }}">{{ $student['gpa'] }}</td>
                                    <td rowspan="{{ max(1, count($student['courses'])) }}">{{ $student['cgpa'] }}</td>
                                    <td rowspan="{{ max(1, count($student['courses'])) }}">{{ $student['remark'] }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        @endforeach
        <div class="sig">
            <div>{{ $report['signatures']['hod'] ?? 'Head of Department' }}</div>
            <div>{{ $report['signatures']['dean'] ?? 'Dean of Faculty' }}</div>
        </div>
    @endif
</body>
</html>
