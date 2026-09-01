<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Academic Transcript — {{ $report['student']['matric_number'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm 18mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
            line-height: 1.35;
        }
        .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .letterhead td { border: none; vertical-align: middle; }
        .logo { width: 64px; height: 64px; object-fit: contain; }
        .uni-name {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin: 0 0 2px;
            text-align: center;
        }
        .office {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            text-align: center;
            margin: 0;
        }
        .doc-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            text-align: center;
            margin: 8px 0 10px;
            text-decoration: underline;
        }
        .photo {
            width: 78px;
            height: 92px;
            object-fit: cover;
            border: 1px solid #222;
        }
        .photo-empty {
            width: 78px;
            height: 92px;
            border: 1px solid #222;
            background: #f4f4f4;
        }
        .bio { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .bio td { border: none; padding: 1px 0; vertical-align: top; }
        .bio .label {
            width: 150px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
        }
        .bio .value {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
        }
        .semester-title {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 12px 0 4px;
            letter-spacing: 0.04em;
        }
        table.courses {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        table.courses th, table.courses td {
            border: 1px solid #222;
            padding: 3px 5px;
            vertical-align: middle;
        }
        table.courses th {
            font-size: 8px;
            text-transform: uppercase;
            background: #f3f3f3;
            text-align: left;
        }
        table.courses td { font-size: 9px; }
        .code { width: 72px; white-space: nowrap; }
        .units { width: 72px; text-align: center; }
        .grade { width: 88px; text-align: center; white-space: nowrap; }
        .totals { margin: 2px 0 0; font-size: 9.5px; font-weight: 700; text-transform: uppercase; }
        .totals div { margin: 1px 0; }
        .sign-wrap { width: 100%; border-collapse: collapse; margin-top: 36px; }
        .sign-wrap td { border: none; vertical-align: bottom; width: 50%; }
        .sign-line {
            border-top: 1px solid #111;
            width: 72%;
            padding-top: 6px;
            font-size: 9.5px;
        }
        .sign-name { font-weight: 700; margin-bottom: 2px; }
        .key-page { page-break-before: always; }
        .key-title {
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            margin: 0 0 14px;
            letter-spacing: 0.08em;
        }
        .era {
            font-size: 11px;
            font-weight: 700;
            margin: 14px 0 6px;
            text-decoration: underline;
        }
        .key-block { margin-bottom: 10px; }
        .key-head { font-weight: 700; margin: 0 0 4px; }
        table.key {
            width: 280px;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        table.key th, table.key td {
            border: 1px solid #222;
            padding: 3px 6px;
            font-size: 9px;
            text-align: center;
        }
        table.key th { background: #f3f3f3; font-weight: 700; }
        .note {
            margin-top: 18px;
            font-size: 9.5px;
            text-align: justify;
        }
        .footer {
            margin-top: 16px;
            font-size: 8px;
            color: #444;
            text-align: center;
        }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <table class="letterhead">
        <tr>
            <td style="width: 80px;">
                @if (!empty($logo_data_uri))
                    <img class="logo" src="{{ $logo_data_uri }}" alt="">
                @endif
            </td>
            <td>
                <p class="uni-name">{{ $institution['name'] ?? $report['university'] ?? 'Bells University of Technology' }}</p>
                <p class="office">{{ $institution['office'] ?? 'Office of the Registrar' }}</p>
            </td>
            <td style="width: 86px; text-align: right;">
                @if (!empty($photo_data_uri))
                    <img class="photo" src="{{ $photo_data_uri }}" alt="">
                @else
                    <div class="photo-empty"></div>
                @endif
            </td>
        </tr>
    </table>

    <div class="doc-title">Academic Transcript</div>

    <table class="bio">
        <tr>
            <td class="label">Matric Number:</td>
            <td class="value">{{ $report['student']['matric_number'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Name:</td>
            <td class="value">{{ $report['student']['name'] ?? '—' }}</td>
        </tr>
        @if (!empty($report['student']['programme']))
            <tr>
                <td class="label">Degree Programme:</td>
                <td class="value">{{ $report['student']['programme'] }}</td>
            </tr>
        @endif
        @if (!empty($report['student']['department']))
            <tr>
                <td class="label">Department:</td>
                <td class="value">{{ $report['student']['department'] }}</td>
            </tr>
        @endif
        @if (!empty($report['student']['college']))
            <tr>
                <td class="label">College:</td>
                <td class="value">{{ $report['student']['college'] }}</td>
            </tr>
        @endif
    </table>

    @forelse($report['terms'] ?? [] as $term)
        <div class="semester-title">{{ $term['heading'] ?? trim(($term['session_label'] ?? '').' '.($term['name'] ?? '')) }}</div>
        <table class="courses">
            <thead>
                <tr>
                    <th class="code">Course Code</th>
                    <th>Course Title</th>
                    <th class="units">Unit Value</th>
                    <th class="grade">Grade Obtained</th>
                </tr>
            </thead>
            <tbody>
                @foreach($term['rows'] ?? [] as $row)
                    <tr>
                        <td class="code">{{ $row['course']['code'] ?? '—' }}</td>
                        <td>{{ $row['course']['title'] ?? '—' }}</td>
                        <td class="units">{{ $row['course']['units'] ?? '—' }}</td>
                        <td class="grade">{{ $row['grade_obtained'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="totals">
            <div>Total credit offered: {{ $term['credits_offered'] ?? '—' }}</div>
            <div>Total credit passed: {{ $term['credits_passed'] ?? '—' }}</div>
            <div>Semester GPA: {{ isset($term['gpa']) && $term['gpa'] !== null && $term['gpa'] !== '' ? number_format((float) $term['gpa'], 2) : '—' }}</div>
            <div>Cumulative CGPA: {{ isset($term['cgpa']) && $term['cgpa'] !== null && $term['cgpa'] !== '' ? number_format((float) $term['cgpa'], 2) : number_format((float) ($report['cgpa'] ?? 0), 2) }}</div>
        </div>
    @empty
        <p>No released grades are available on this transcript.</p>
    @endforelse

    @if (!empty($report['cgpa_note']))
        <p style="font-size:9px;color:#444;margin:10px 0 0;">{{ $report['cgpa_note'] }}</p>
    @endif

    <table class="sign-wrap">
        <tr>
            <td>
                <div class="sign-line">
                    <div class="sign-name">{{ $report['registrar_name'] ?? $report['signed_by'] ?? '' }}</div>
                    {{ $report['registrar_title'] ?? 'Registrar' }}
                </div>
            </td>
            <td>
                <div class="sign-line" style="margin-left: auto;">
                    Date<br>
                    {{ $report['generated_at'] ?? '' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="key-page">
        <div class="key-title">Grading System and Classification of Degrees</div>

        <div class="era">2005 – 2012</div>
        <div class="key-block">
            <p class="key-head">A. Scores, grades, and grade points</p>
            <table class="key">
                <thead>
                    <tr><th>Scores</th><th>Grades</th><th>Points</th></tr>
                </thead>
                <tbody>
                    <tr><td>70 – 100</td><td>A</td><td>5</td></tr>
                    <tr><td>60 – 69</td><td>B</td><td>4</td></tr>
                    <tr><td>50 – 59</td><td>C</td><td>3</td></tr>
                    <tr><td>45 – 49</td><td>D</td><td>2</td></tr>
                    <tr><td>40 – 44</td><td>E</td><td>1</td></tr>
                    <tr><td>00 – 39</td><td>F</td><td>0</td></tr>
                </tbody>
            </table>
            <p class="key-head">B. Classes of degrees</p>
            <table class="key">
                <thead>
                    <tr><th>Classification</th><th>CGPA</th></tr>
                </thead>
                <tbody>
                    <tr><td>1st Class Honours</td><td>4.50 – 5.00</td></tr>
                    <tr><td>2nd Class Honours (Upper Division)</td><td>3.50 – 4.49</td></tr>
                    <tr><td>2nd Class Honours (Lower Division)</td><td>2.40 – 3.49</td></tr>
                    <tr><td>3rd Class</td><td>1.50 – 2.39</td></tr>
                    <tr><td>Pass</td><td>1.00 – 1.49</td></tr>
                </tbody>
            </table>
        </div>

        <div class="era">2013 – Date</div>
        <div class="key-block">
            <p class="key-head">A. Scores, grades, and grade points</p>
            <table class="key">
                <thead>
                    <tr><th>Scores</th><th>Grades</th><th>Points</th></tr>
                </thead>
                <tbody>
                    <tr><td>70 – 100</td><td>A</td><td>5</td></tr>
                    <tr><td>60 – 69</td><td>B</td><td>4</td></tr>
                    <tr><td>50 – 59</td><td>C</td><td>3</td></tr>
                    <tr><td>45 – 49</td><td>D</td><td>2</td></tr>
                    <tr><td>00 – 44</td><td>F</td><td>0</td></tr>
                </tbody>
            </table>
            <p class="key-head">B. Classes of degrees</p>
            <table class="key">
                <thead>
                    <tr><th>Classification</th><th>CGPA</th></tr>
                </thead>
                <tbody>
                    <tr><td>1st Class Honours</td><td>4.50 – 5.00</td></tr>
                    <tr><td>2nd Class Honours (Upper Division)</td><td>3.50 – 4.49</td></tr>
                    <tr><td>2nd Class Honours (Lower Division)</td><td>2.40 – 3.49</td></tr>
                    <tr><td>3rd Class Honours</td><td>1.50 – 2.39</td></tr>
                </tbody>
            </table>
        </div>

        <p class="note">
            Kindly be informed that {{ $institution['name'] ?? $report['university'] ?? 'Bells University of Technology' }}
            uses English Language as the only medium of instruction and communication for all Academic Programmes in the University.
        </p>
    </div>

    <div class="footer">
        Official transcript
        @if (!empty($report['request_token']))
            · Request {{ $report['request_token'] }}
        @endif
        @if (!empty($report['copies']))
            · Copies: {{ $report['copies'] }}
        @endif
        · Issued {{ $report['generated_at'] ?? '' }}
    </div>
</body>
</html>
