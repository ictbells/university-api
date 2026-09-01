<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] ?? 'Undergraduate Semester Result' }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 8mm; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9px;
            color: #111;
            line-height: 1.25;
            margin: 0;
            padding: 0;
        }
        .center { text-align: center; }
        .university {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 0 0 2px;
        }
        .address {
            font-size: 9px;
            text-transform: uppercase;
            margin: 0 0 4px;
        }
        .sheet-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 4px 0 2px;
            letter-spacing: 0.06em;
        }
        .meta-line {
            font-size: 9px;
            margin: 2px 0;
        }
        .meta-line strong { font-weight: 700; }
        .level-line {
            font-size: 10px;
            font-weight: 700;
            margin: 2px 0 6px;
            text-transform: uppercase;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #333;
            padding: 2px 3px;
            vertical-align: middle;
        }
        th {
            background: #f3f3f3;
            font-weight: 700;
            text-align: center;
            font-size: 7px;
            text-transform: uppercase;
        }
        .broadsheet th, .broadsheet td { font-size: 7px; }
        .broadsheet .sn { width: 22px; text-align: center; }
        .broadsheet .matric { width: 78px; text-align: left; }
        .broadsheet .name { text-align: left; min-width: 90px; }
        .broadsheet .course { min-width: 42px; text-align: center; }
        .broadsheet .other { text-align: left; font-size: 6.5px; min-width: 90px; }
        .broadsheet .num { text-align: center; width: 32px; }
        .broadsheet .status { text-align: center; width: 36px; }
        .broadsheet .outstanding { text-align: left; font-size: 6.5px; min-width: 80px; }
        .header-meta { font-size: 6.5px; font-weight: 700; }
        .empty { font-style: italic; color: #555; text-align: center; }
        .sheet {
            page-break-inside: auto;
        }
        .sheet + .sheet {
            page-break-before: always;
        }
        .sheet-end {
            page-break-inside: avoid;
            margin-top: 16px;
        }
        .signatures {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0 14px;
        }
        .signatures td {
            border: none;
            vertical-align: bottom;
            text-align: center;
            padding: 0 10px;
        }
        .sig-line {
            border-bottom: 1px solid #111;
            height: 28px;
            margin: 0 auto 4px;
            width: 88%;
        }
        .sig-label {
            font-size: 8px;
            font-weight: 700;
            margin: 0;
        }
        .end-tables {
            width: 100%;
            border-collapse: collapse;
        }
        .end-tables > tbody > tr > td {
            border: none;
            vertical-align: top;
            padding: 0;
        }
        .summary-wrap { width: 48%; padding-right: 10px; }
        .legend-wrap { width: 52%; padding-left: 10px; }
        .block-title {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 0 0 4px;
        }
        .summary-table, .legend-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td,
        .legend-table th, .legend-table td {
            border: 1px solid #333;
            font-size: 7px;
            padding: 2px 4px;
        }
        .summary-table td.label { text-align: left; }
        .summary-table td.count { text-align: center; font-weight: 700; width: 36px; }
        .legend-table td { text-align: left; }
        .legend-note {
            font-size: 7px;
            margin: 4px 0 0;
        }
        .supplementary-banner {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 4px 0;
            border: 1px solid #111;
            padding: 2px 8px;
            display: inline-block;
        }
        .no-print { margin-bottom: 8px; text-align: right; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>
    @php $sheets = $report['sheets'] ?? $report['departments'] ?? []; @endphp

    @forelse ($sheets as $sheet)
        <div class="sheet">
            <div class="center">
                <p class="university">{{ $report['university'] }}</p>
                <p class="address">{{ $report['campus_address'] ?? 'KM 8, Idi-Iroko Road, Ota, Ogun State' }}</p>
                @if (!empty($report['is_supplementary']))
                    <p class="supplementary-banner">SUPPLEMENTARY</p>
                @endif
                <p class="sheet-title">{{ $report['title'] ?? 'UNDERGRADUATE SEMESTER RESULT' }}</p>
                <p class="meta-line">
                    <strong>College:</strong> {{ $sheet['college_name'] ?? $report['college_name'] ?? '—' }}
                    &nbsp;|&nbsp; <strong>Department:</strong> {{ $sheet['department_name'] ?? '—' }}
                    &nbsp;|&nbsp; <strong>Programme:</strong> {{ $sheet['programme'] ?? '—' }}
                    &nbsp;|&nbsp; <strong>Session:</strong> {{ $report['session_label'] ?? $report['academic_year'] ?? '—' }}
                    &nbsp;|&nbsp; <strong>Semester:</strong> {{ $report['semester_label'] ?? '—' }}
                </p>
                @if (!empty($sheet['level_label']))
                    <p class="level-line">{{ $sheet['level_label'] }}</p>
                @endif
            </div>

            @php
                $columns = $sheet['course_columns'] ?? [];
                $colspan = 3 + count($columns) + 9;
            @endphp
            <table class="broadsheet">
                <thead>
                    <tr>
                        <th class="sn">S/N</th>
                        <th class="matric">Matric No.</th>
                        <th class="name">Name</th>
                        @foreach ($columns as $col)
                            <th class="course">{{ $col['code'] }}</th>
                        @endforeach
                        <th class="other">Other Courses Taken</th>
                        <th class="num">TUT</th>
                        <th class="num">TUP</th>
                        <th class="num">TUF</th>
                        <th class="num">PGPA</th>
                        <th class="num">SGPA</th>
                        <th class="num">CGPA</th>
                        <th class="status">Status</th>
                        <th class="outstanding">Outstanding</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th></th>
                        <th></th>
                        @foreach ($columns as $col)
                            <th class="header-meta">{{ $col['header_meta'] ?? '' }}</th>
                        @endforeach
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($sheet['students'] ?? []) as $student)
                        <tr>
                            <td class="sn">{{ $student['sn'] }}</td>
                            <td class="matric">{{ $student['matric'] }}</td>
                            <td class="name">{{ $student['name'] ?? '—' }}</td>
                            @foreach ($columns as $col)
                                <td class="course">{{ $student['scores'][$col['code']] ?? '—' }}</td>
                            @endforeach
                            <td class="other">{{ $student['other_courses'] ?? '—' }}</td>
                            <td class="num">{{ $student['tut'] ?? $student['tur'] ?? '—' }}</td>
                            <td class="num">{{ $student['tup'] ?? '—' }}</td>
                            <td class="num">{{ $student['tuf'] ?? '—' }}</td>
                            <td class="num">{{ $student['pgpa'] ?? '—' }}</td>
                            <td class="num">{{ $student['sgpa'] ?? $student['gpa'] ?? '—' }}</td>
                            <td class="num">{{ $student['cgpa'] ?? '—' }}</td>
                            <td class="status">{{ $student['status'] ?? '—' }}</td>
                            <td class="outstanding">{{ $student['outstanding'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ max($colspan, 6) }}" class="empty">No students listed.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="sheet-end">
                @if (!empty($report['signatures']))
                    <table class="signatures">
                        <tr>
                            @foreach ($report['signatures'] as $signature)
                                <td>
                                    <div class="sig-line"></div>
                                    <p class="sig-label">{{ $signature['label'] }}</p>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                @endif

                @php $summary = $sheet['summary'] ?? []; @endphp
                <table class="end-tables">
                    <tr>
                        <td class="summary-wrap">
                            <p class="block-title">Summary</p>
                            <table class="summary-table">
                                <tr>
                                    <td class="label">Number of Candidates in Good Standing</td>
                                    <td class="count">{{ $summary['good_standing'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number of Candidates not in Good Standing</td>
                                    <td class="count">{{ $summary['not_good_standing'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number Absent with Permission</td>
                                    <td class="count">{{ $summary['absent_with_permission'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number of Candidates with incomplete result</td>
                                    <td class="count">{{ $summary['incomplete'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number Absent without Permission</td>
                                    <td class="count">{{ $summary['absent_without_permission'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number Rusticated/Suspended/Expelled</td>
                                    <td class="count">{{ $summary['rusticated'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Number Sick</td>
                                    <td class="count">{{ $summary['sick'] ?? 0 }}</td>
                                </tr>
                                <tr>
                                    <td class="label">Total Number of Candidates</td>
                                    <td class="count">{{ $summary['total'] ?? 0 }}</td>
                                </tr>
                            </table>
                        </td>
                        <td class="legend-wrap">
                            <p class="block-title">Legend</p>
                            <table class="legend-table">
                                <tr>
                                    <td>SGPA: Semester Grade Point Average</td>
                                    <td>TUF: Total Units Failed</td>
                                </tr>
                                <tr>
                                    <td>CGPA: Cumulative Grade Point Average</td>
                                    <td>ABS_P: Absent with Permission</td>
                                </tr>
                                <tr>
                                    <td>TUT: Total Units Taken</td>
                                    <td>ABS_NP: Absent without Permission</td>
                                </tr>
                                <tr>
                                    <td>PGPA: Previous Grade Point Average</td>
                                    <td>TUP: Total Units Passed</td>
                                </tr>
                                <tr>
                                    <td>RUS: Rustication &nbsp; SUS: Suspension</td>
                                    <td>PB: Probation &nbsp; WR: Warning</td>
                                </tr>
                                <tr>
                                    <td>WD: Withdrawal &nbsp; AR: Awaiting Result</td>
                                    <td>EXP: Expelled</td>
                                </tr>
                            </table>
                            <p class="legend-note">
                                GS (2013 &amp; above): CGPA ≥ 1.5 AND Outstanding &lt; 12 Units<br>
                                GS (2012 &amp; before): CGPA ≥ 1.0 AND Outstanding &lt; 12 Units<br>
                                NGS (2013 &amp; above): CGPA &lt; 1.5 OR Outstanding ≥ 12 Units<br>
                                NGS (2012 &amp; before): CGPA &lt; 1.0 OR Outstanding ≥ 12 Units
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    @empty
        <div class="center">
            <p class="university">{{ $report['university'] }}</p>
            <p class="empty" style="margin-top:20px;">No results found for the selected filters.</p>
        </div>
    @endforelse
</body>
</html>
