<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Course registration {{ $matric_number }}</title>
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
    table.courses { width: 100%; border-collapse: collapse; }
    table.courses th, table.courses td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; }
    table.courses th { background: #f1f5f9; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
    table.courses td.num, table.courses th.num { text-align: center; width: 40px; }
    table.courses td.units, table.courses th.units { text-align: center; width: 56px; }
    .totals { margin-top: 12px; font-size: 13px; }
    .empty { color: #64748b; font-style: italic; padding: 12px 0; }
    .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 36px; }
    .sign-line { border-top: 1px solid #0f172a; padding-top: 6px; font-size: 12px; }
    .footer { margin-top: 28px; font-size: 11px; color: #64748b; text-align: center; font-family: "Segoe UI", system-ui, sans-serif; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; max-width: none; padding: 10mm; }
      .footer { display: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="brand">
      @if (!empty($logo_data_uri))
        <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
      @endif
      <h1>{{ $institution['name'] }}</h1>
      <p class="motto">{{ $institution['motto'] }}</p>
      <p class="doc-title">Course registration form</p>
    </div>

    <div class="meta">
      <div><span class="label">Name:</span> <span class="value">{{ strtoupper($full_name) }}</span></div>
      <div><span class="label">Matric no.:</span> <span class="value">{{ $matric_number }}</span></div>
      <div><span class="label">Programme:</span> <span class="value">{{ $programme }}</span></div>
      <div><span class="label">Level:</span> <span class="value">{{ $level }}</span></div>
      <div><span class="label">Session:</span> <span class="value">{{ $session ?: '—' }}</span></div>
      <div><span class="label">Semester:</span> <span class="value">{{ $semester ?: '—' }}</span></div>
    </div>

    @if ($rows->isEmpty())
      <p class="empty">No courses are registered for this semester.</p>
    @else
      <table class="courses">
        <thead>
          <tr>
            <th class="num">S/N</th>
            <th>Code</th>
            <th>Course title</th>
            <th>Status</th>
            <th class="units">Units</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            <tr>
              <td class="num">{{ $row['sn'] }}</td>
              <td>{{ $row['code'] }}</td>
              <td>{{ $row['title'] }}</td>
              <td>{{ $row['status'] }}</td>
              <td class="units">{{ $row['units'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <p class="totals">
      <strong>Unit total:</strong> {{ $units['overall'] ?? 0 }}
    </p>

    <div class="signs">
      <div class="sign-line">Student signature / date</div>
      <div class="sign-line">Course adviser / date</div>
    </div>

    <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p>
  </div>
</body>
</html>
