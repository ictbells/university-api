<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Exam clearance {{ $matric_number }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 24px;
      font-size: 13px;
      line-height: 1.4;
    }
    .sheet {
      max-width: 860px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 32px 36px;
    }
    .brand { text-align: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #0f172a; }
    .brand img { width: 64px; height: 64px; object-fit: contain; margin: 0 auto 8px; display: block; }
    .brand h1 { margin: 0; font-size: 18px; letter-spacing: 0.02em; text-transform: uppercase; }
    .brand .motto { margin: 4px 0 0; color: #475569; font-size: 12px; font-style: italic; }
    .brand .doc-title { margin: 10px 0 0; font-size: 14px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
    .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; margin: 16px 0 18px; }
    .meta .label { color: #64748b; }
    .meta .value { font-weight: 700; }
    .status {
      border: 2px solid {{ $cleared ? '#047857' : '#b45309' }};
      background: {{ $cleared ? '#ecfdf5' : '#fffbeb' }};
      color: {{ $cleared ? '#065f46' : '#92400e' }};
      padding: 10px 12px;
      margin: 0 0 16px;
      text-align: center;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    table.checks { width: 100%; border-collapse: collapse; }
    table.checks th, table.checks td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; vertical-align: top; }
    table.checks th { background: #f1f5f9; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
    table.checks td.result { font-weight: 700; width: 90px; }
    .passed { color: #047857; }
    .failed { color: #b45309; }
    .empty { color: #64748b; font-style: italic; padding: 12px 0; }
    .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 36px; }
    .sign-line { border-top: 1px solid #0f172a; padding-top: 6px; font-size: 12px; }
    .footer { margin-top: 28px; font-size: 11px; color: #64748b; text-align: center; font-family: "Segoe UI", system-ui, sans-serif; }
    .no-print { text-align: right; margin-bottom: 12px; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; max-width: none; padding: 10mm; }
      .footer, .no-print { display: none; }
    }
  </style>
</head>
<body>
  <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>
  <div class="sheet">
    <div class="brand">
      @if (!empty($logo_data_uri))
        <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
      @endif
      <h1>{{ $institution['name'] }}</h1>
      <p class="motto">{{ $institution['motto'] }}</p>
      <p class="doc-title">Exam clearance</p>
    </div>

    <div class="meta">
      <div><span class="label">Name:</span> <span class="value">{{ strtoupper($full_name) }}</span></div>
      <div><span class="label">Matric no.:</span> <span class="value">{{ $matric_number }}</span></div>
      <div><span class="label">Programme:</span> <span class="value">{{ $programme }}</span></div>
      <div><span class="label">Level:</span> <span class="value">{{ $level }}</span></div>
      <div><span class="label">Session:</span> <span class="value">{{ $session ?: '—' }}</span></div>
      <div><span class="label">Semester:</span> <span class="value">{{ $semester ?: '—' }}</span></div>
    </div>

    <div class="status">
      {{ $cleared ? 'Cleared to sit examinations' : 'Not cleared to sit examinations' }}
    </div>

    @if (count($checks) === 0)
      <p class="empty">No exam-clearance conditions are currently enabled.</p>
    @else
      <table class="checks">
        <thead>
          <tr>
            <th>Condition</th>
            <th>Result</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($checks as $check)
            <tr>
              <td>{{ $check['label'] }}</td>
              <td class="result {{ ($check['passed'] ?? false) ? 'passed' : 'failed' }}">
                {{ ($check['passed'] ?? false) ? 'Met' : 'Not met' }}
              </td>
              <td>{{ $check['detail'] ?? '' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div class="signs">
      <div class="sign-line">Student signature / date</div>
      <div class="sign-line">Exams &amp; records / date</div>
    </div>

    <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p>
  </div>
</body>
</html>
