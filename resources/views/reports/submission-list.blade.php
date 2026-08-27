<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Results list</title>
    <style>
        @page { margin: 14px 16px; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #111;
            line-height: 1.3;
            margin: 0;
            padding: 0;
        }
        .center { text-align: center; }
        .university {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin: 0 0 2px;
        }
        .org-line {
            font-size: 13px;
            font-weight: 700;
            margin: 1px 0;
        }
        .meta {
            margin: 6px 0 10px;
            font-size: 13px;
        }
        .meta strong { font-weight: 700; }
        .dept-block {
            margin-top: 10px;
            page-break-inside: auto;
            break-inside: auto;
        }
        .dept-title {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 6px;
            border-bottom: 1px solid #333;
            padding-bottom: 2px;
            page-break-after: avoid;
        }
        .course-block {
            margin: 8px 0 12px;
            page-break-inside: auto;
            break-inside: auto;
        }
        .course-title {
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 4px;
            page-break-after: avoid;
        }
        .list-title {
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            page-break-inside: auto;
        }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td {
            border: 1px solid #444;
            padding: 3px 4px;
            vertical-align: middle;
        }
        th {
            background: #f0f0f0;
            font-weight: 700;
            text-align: center;
        }
        .sn { width: 26px; text-align: center; }
        .matric { width: 96px; text-align: left; }
        .score, .grade, .units, .failed, .gpa, .course-score { text-align: center; }
        .course-score { min-width: 40px; font-size: 9px; }
        .course-meta { font-size: 8px; font-weight: 700; text-align: center; }
        .dept-matrix th, .dept-matrix td { font-size: 9px; padding: 2px 3px; vertical-align: middle; }
        .dept-matrix thead tr.header-row-2 th {
            background: #f7f7f7;
            font-weight: 700;
            height: 18px;
        }
        .dept-matrix thead tr.header-row-2 th.course-meta {
            font-size: 8px;
            white-space: nowrap;
        }
        .dept-matrix .matric { width: 86px; font-size: 9px; }
        .empty { font-style: italic; color: #555; }
        .sheet-mark {
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            margin: 0 0 4px;
        }
        .supplementary-banner {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 4px 0 6px;
            text-align: center;
            border: 1px solid #111;
            padding: 3px 8px;
        }
        .faculty-sheet {
            page-break-inside: auto;
        }
        .faculty-sheet + .faculty-sheet {
            page-break-before: always;
        }
        .faculty-info {
            margin: 10px 0 8px;
            font-size: 12px;
            text-align: left;
        }
        .faculty-info div { margin: 2px 0; }
        .faculty-info .label {
            font-weight: 700;
            display: inline-block;
            min-width: 110px;
        }
        .summary-title {
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            margin: 8px 0 4px;
            text-transform: uppercase;
        }
        .faculty-summary th, .faculty-summary td {
            font-size: 8px;
            padding: 2px 3px;
            text-align: center;
        }
        .faculty-summary .matric { text-align: left; font-size: 8px; }
        .faculty-summary .col-num {
            background: #e8e8e8;
            font-size: 8px;
            font-weight: 700;
        }
        .signature-page {
            page-break-before: always;
            padding-top: 280px;
        }
        .signature-row {
            width: 100%;
            border-collapse: collapse;
        }
        .signature-row td {
            border: none;
            width: 50%;
            vertical-align: top;
            padding: 0 24px;
            text-align: center;
        }
        .signature-line {
            border-bottom: 1px solid #111;
            height: 28px;
            margin: 0 auto 6px;
            width: 78%;
        }
        .signature-name {
            font-size: 12px;
            font-weight: 700;
            margin: 0 0 2px;
        }
        .signature-title {
            font-size: 11px;
            font-weight: 700;
            margin: 0;
            text-transform: uppercase;
        }
        .footer-note {
            margin-top: 16px;
            font-size: 9px;
            color: #555;
        }
        .no-print { margin-bottom: 8px; text-align: right; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>
    @php $layout = $report['layout'] ?? 'course_grouped'; @endphp

    @if (in_array($layout, ['faculty_summary', 'board_summary'], true))
        @php
            $showStudentName = !empty($report['show_student_name']) || $layout === 'board_summary';
            $showWgpToDate = array_key_exists('show_wgp_to_date', $report)
                ? !empty($report['show_wgp_to_date'])
                : $layout === 'faculty_summary';
            $summaryCols = 14;
        @endphp
        @forelse (($report['departments'] ?? []) as $department)
            <div class="faculty-sheet">
                <div class="sheet-mark">{{ $department['sheet_mark'] ?? ($layout === 'board_summary' ? '003' : '002') }}</div>
                <div class="center">
                    <p class="university">{{ $report['university'] }}</p>
                    @if (!empty($report['is_supplementary']))
                        <p class="supplementary-banner">SUPPLEMENTARY</p>
                    @endif
                    <p class="org-line" style="text-transform:uppercase;margin-top:6px;">
                        {{ $report['sheet_banner'] ?? 'NON-FINAL YEAR STUDENTS' }}
                    </p>
                    @if (!empty($report['sheet_exam_line']))
                        <p class="org-line" style="text-transform:uppercase;">{{ $report['sheet_exam_line'] }}</p>
                    @endif
                </div>

                <div class="faculty-info">
                    <div><span class="label">FACULTY:</span> {{ $report['faculty_name'] }}</div>
                    <div><span class="label">DEPARTMENT:</span> {{ $department['name'] }}</div>
                    <div><span class="label">LEVEL:</span> {{ $report['sheet_level'] !== '' ? $report['sheet_level'] : '—' }}</div>
                    <div><span class="label">PROGRAM:</span> {{ $department['programme'] ?? '—' }}</div>
                </div>

                <p class="summary-title">SUMMARY OF RESULTS TABLE</p>
                <table class="faculty-summary">
                    <thead>
                        <tr>
                            @for ($i = 1; $i <= $summaryCols; $i++)
                                <th class="col-num">{{ $i }}</th>
                            @endfor
                        </tr>
                        <tr>
                            <th class="sn">S/N</th>
                            <th class="matric">Matric NO.</th>
                            @if ($showStudentName)
                                <th>Student's Name in Alphabetical Order with Surname First</th>
                            @endif
                            <th>Session of Entry</th>
                            <th>Mode of Entry</th>
                            <th>TUR Current Semester</th>
                            <th>TUP Current Semester</th>
                            <th>WGP Current Semester</th>
                            <th>GPA</th>
                            <th>Cum. Units Regd. to Date</th>
                            <th>Cum. Unit Passed to Date</th>
                            <th>{{ $showStudentName ? 'Units Not in to Date (List courses)' : 'Units Not in to Date' }}</th>
                            @if ($showWgpToDate)
                                <th>Cumulative WGP to Date</th>
                            @endif
                            <th>CGPA</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($department['students'] ?? []) as $student)
                            <tr>
                                <td class="sn">{{ $student['sn'] }}</td>
                                <td class="matric">{{ $student['matric'] }}</td>
                                @if ($showStudentName)
                                    <td style="text-align:left;font-size:7px;">{{ $student['name'] ?? '—' }}</td>
                                @endif
                                <td>{{ $student['year_of_entry'] ?? '—' }}</td>
                                <td>{{ $student['mode_of_entry'] ?? '—' }}</td>
                                <td>{{ $student['tur'] ?? '—' }}</td>
                                <td>{{ $student['tup'] ?? '—' }}</td>
                                <td>{{ $student['wgp'] ?? '—' }}</td>
                                <td>{{ $student['gpa'] ?? '—' }}</td>
                                <td>{{ $student['tur_to_date'] ?? '—' }}</td>
                                <td>{{ $student['tup_to_date'] ?? '—' }}</td>
                                <td style="text-align:left;font-size:7px;">{{ $student['units_not_in_to_date'] ?? '' }}</td>
                                @if ($showWgpToDate)
                                    <td>{{ $student['wgp_to_date'] ?? '—' }}</td>
                                @endif
                                <td>{{ $student['cgpa'] ?? '—' }}</td>
                                <td>{{ $student['remark'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $summaryCols }}" class="empty">No students listed.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <div class="sheet-mark">{{ $layout === 'board_summary' ? '003' : '002' }}</div>
            <div class="center">
                <p class="university">{{ $report['university'] }}</p>
                <p class="empty" style="margin-top:20px;">No results found for the selected filters.</p>
            </div>
        @endforelse

        @if (!empty($report['departments']))
            <div class="signature-page">
                <table class="signature-row">
                    <tr>
                        <td>
                            <div class="signature-line"></div>
                            <p class="signature-name">&nbsp;</p>
                            <p class="signature-title">{{ $report['signature_hod_title'] ?? 'Head Of Department' }}</p>
                        </td>
                        <td>
                            <div class="signature-line"></div>
                            <p class="signature-name">&nbsp;</p>
                            <p class="signature-title">{{ $report['signature_dean_title'] ?? 'Dean' }}</p>
                        </td>
                    </tr>
                </table>
            </div>
        @endif

    @elseif ($layout === 'student_matrix')
        <div class="sheet-mark">002</div>
        <div class="center">
            <p class="university">{{ $report['university'] }}</p>
            @if (!empty($report['is_supplementary']))
                <p class="supplementary-banner">SUPPLEMENTARY</p>
            @endif
            <p class="org-line" style="text-transform:uppercase;">{{ $report['sheet_title'] ?? 'DEPARTMENTAL RESULTS' }}</p>
            @if (!empty($report['sheet_subtitle']))
                <p class="org-line">{{ $report['sheet_subtitle'] }}</p>
            @endif
            @if (!empty($report['sheet_exam_line']))
                <p class="meta" style="margin:4px 0;"><strong>{{ $report['sheet_exam_line'] }}</strong></p>
            @endif
            @if (!empty($report['sheet_level']))
                <p class="meta" style="margin:0;"><strong>{{ $report['sheet_level'] }}</strong></p>
            @endif
        </div>

        @php
            $columns = $report['course_columns'] ?? [];
            if ($columns === [] && !empty($report['course_codes'])) {
                $columns = array_map(fn ($c) => ['code' => $c, 'header_meta' => ''], $report['course_codes']);
            }
            $matrixColspan = 4 + count($columns) + 10;
        @endphp
        <table class="dept-matrix">
            <thead>
                <tr class="header-row-1">
                    <th class="sn">S/N</th>
                    <th class="matric">Matric Number</th>
                    <th>Year of Entry</th>
                    <th>Mode of Entry</th>
                    @foreach ($columns as $col)
                        <th class="course-score">{{ $col['code'] }}</th>
                    @endforeach
                    <th>TUR Current Semester</th>
                    <th>TUP Current Semester</th>
                    <th>WGP Current Semester</th>
                    <th>GPA</th>
                    <th>TUR to Date</th>
                    <th>TUP to Date</th>
                    <th>WGP to Date</th>
                    <th>Courses Not in to Date</th>
                    <th>CGPA</th>
                    <th>Courses Failed</th>
                    <th>Remark</th>
                </tr>
                <tr class="header-row-2">
                    <th class="sn">&nbsp;</th>
                    <th class="matric">&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    @foreach ($columns as $col)
                        <th class="course-meta">@if (($col['header_meta'] ?? '') !== ''){{ $col['header_meta'] }}@else&nbsp;@endif</th>
                    @endforeach
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th>&nbsp;</th>
                    <th colspan="4">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($report['students'] ?? []) as $student)
                    <tr>
                        <td class="sn">{{ $student['sn'] }}</td>
                        <td class="matric">{{ $student['matric'] }}</td>
                        <td class="score">{{ $student['year_of_entry'] ?? '—' }}</td>
                        <td class="score">{{ $student['mode_of_entry'] ?? '—' }}</td>
                        @foreach ($columns as $col)
                            <td class="course-score">{{ $student['scores'][$col['code']] ?? '—' }}</td>
                        @endforeach
                        <td class="units">{{ $student['tur'] ?? $student['units_registered'] ?? '—' }}</td>
                        <td class="units">{{ $student['tup'] ?? $student['units_passed'] ?? '—' }}</td>
                        <td class="units">{{ $student['wgp'] ?? '—' }}</td>
                        <td class="gpa">{{ $student['gpa'] ?? '—' }}</td>
                        <td class="units">{{ $student['tur_to_date'] ?? '—' }}</td>
                        <td class="units">{{ $student['tup_to_date'] ?? '—' }}</td>
                        <td class="units">{{ $student['wgp_to_date'] ?? '—' }}</td>
                        <td style="text-align:left;font-size:8px;">{{ $student['courses_not_in_to_date'] ?? '—' }}</td>
                        <td class="gpa">{{ $student['cgpa'] ?? '—' }}</td>
                        <td style="text-align:left;font-size:8px;">{{ $student['courses_failed'] ?? '—' }}</td>
                        <td class="score">{{ $student['remark'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max($matrixColspan, 6) }}" class="empty">No students listed.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <p class="footer-note">Generated {{ now()->format('d M Y H:i') }} · {{ $report['university'] ?? '' }}</p>

    @else
        <div class="sheet-mark">002</div>
        <div class="center">
            <p class="university">{{ $report['university'] }}</p>
            @if (!empty($report['is_supplementary']))
                <p class="supplementary-banner">SUPPLEMENTARY</p>
            @endif
            <p class="org-line">{{ $report['faculty_name'] }}</p>
            @if (!empty($report['department_name']))
                <p class="org-line">{{ $report['department_name'] }}</p>
            @endif
        </div>
        <div class="meta center">
            <div><strong>Session:</strong> {{ $report['academic_year'] }} &nbsp;|&nbsp; <strong>Semester:</strong> {{ $report['semester_label'] }}</div>
            <div><strong>Status:</strong> {{ $report['status_label'] }}</div>
        </div>

        @php
            $colspan = 6;
            if (!empty($report['show_name'])) $colspan++;
            if (!empty($report['show_unit_totals'])) $colspan += 2;
            if (!empty($report['show_gpa'])) $colspan += 2;
            if (!empty($report['show_units'])) $colspan++;
            if (!empty($report['show_failed'])) $colspan++;
        @endphp

        @forelse ($report['departments'] as $department)
            <div class="dept-block">
                @if ($report['scope'] === 'faculty' || ($report['scope'] === 'board' && count($report['departments']) > 1))
                    <p class="dept-title">{{ $department['name'] }}</p>
                @endif

                @forelse ($department['courses'] as $course)
                    <div class="course-block">
                        <p class="course-title">
                            Course Code/Title: {{ $course['code'] }} — {{ $course['title'] }}
                        </p>
                        <p class="course-title" style="font-weight:600;">List of the students</p>
                        <table>
                            <thead>
                                <tr>
                                    <th class="sn">S/N</th>
                                    <th class="matric">Matric No.</th>
                                    @if (!empty($report['show_name']))
                                        <th>Student Name</th>
                                    @endif
                                    <th class="score">CA</th>
                                    <th class="score">Exam</th>
                                    <th class="score">Total</th>
                                    <th class="grade">Grade</th>
                                    @if (!empty($report['show_units']))
                                        <th class="units">Unit</th>
                                    @endif
                                    @if (!empty($report['show_failed']))
                                        <th class="failed">Failed</th>
                                    @endif
                                    @if (!empty($report['show_unit_totals']))
                                        <th class="units">Unit Reg.</th>
                                        <th class="units">Unit Pass</th>
                                    @endif
                                    @if (!empty($report['show_gpa']))
                                        <th class="gpa">GPA</th>
                                        <th class="gpa">CGPA</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($course['students'] as $student)
                                    <tr>
                                        <td class="sn">{{ $student['sn'] }}</td>
                                        <td class="matric">{{ $student['matric'] }}</td>
                                        @if (!empty($report['show_name']))
                                            <td>{{ $student['name'] ?? '—' }}</td>
                                        @endif
                                        <td class="score">{{ $student['ca'] ?? '—' }}</td>
                                        <td class="score">{{ $student['exam'] ?? '—' }}</td>
                                        <td class="score">{{ $student['score'] }}</td>
                                        <td class="grade">{{ $student['grade'] }}</td>
                                        @if (!empty($report['show_units']))
                                            <td class="units">{{ $student['units'] ?? '—' }}</td>
                                        @endif
                                        @if (!empty($report['show_failed']))
                                            <td class="failed">{{ $student['courses_failed'] ?? '—' }}</td>
                                        @endif
                                        @if (!empty($report['show_unit_totals']))
                                            <td class="units">{{ $student['units_registered'] ?? '—' }}</td>
                                            <td class="units">{{ $student['units_passed'] ?? '—' }}</td>
                                        @endif
                                        @if (!empty($report['show_gpa']))
                                            <td class="gpa">{{ $student['gpa'] ?? '—' }}</td>
                                            <td class="gpa">{{ $student['cgpa'] ?? '—' }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $colspan }}" class="empty">No students listed for this course.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @empty
                    <p class="empty">No courses in this department.</p>
                @endforelse
            </div>
        @empty
            <p class="empty center">No results found for the selected filters.</p>
        @endforelse
        <p class="footer-note">Generated {{ now()->format('d M Y H:i') }} · {{ $report['university'] ?? '' }}</p>
    @endif
</body>
</html>
