<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sick / Excuse Note — {{ $student->matric_number ?? $student->student_number }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 24px;
      font-size: 14px;
      line-height: 1.5;
    }
    .sheet {
      max-width: 720px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 36px 40px;
    }
    h1 { font-size: 18px; text-align: center; margin: 20px 0 24px; text-transform: uppercase; letter-spacing: 0.04em; }
    .meta { margin-bottom: 18px; }
    .meta td { padding: 4px 8px 4px 0; vertical-align: top; }
    .meta td:first-child { color: #64748b; width: 140px; }
    .body { margin: 20px 0; }
    .sign { margin-top: 48px; }
    .muted { color: #64748b; font-size: 12px; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; }
      .no-print { display: none; }
    }
  </style>
</head>
<body>
  <p class="no-print muted" style="text-align:center;margin-bottom:12px;">
    <button onclick="window.print()">Print</button>
  </p>
  <div class="sheet">
    <div style="text-align:center;border-bottom:2px solid #0f172a;padding-bottom:12px;">
      <strong>Bells University of Technology</strong><br>
      <span class="muted">University Clinic — Medical Excuse / Sick Note</span>
    </div>
    <h1>Sick / Excuse Note</h1>
    <table class="meta">
      <tr><td>Student</td><td>{{ $student->first_name }} {{ $student->last_name }}</td></tr>
      <tr><td>Matric / ID</td><td>{{ $student->matric_number ?: ($student->student_number ?: '—') }}</td></tr>
      <tr><td>Issued on</td><td>{{ $issued_on }}</td></tr>
      <tr><td>Valid from</td><td>{{ $valid_from }}</td></tr>
      <tr><td>Valid to</td><td>{{ $valid_to }}</td></tr>
    </table>
    <div class="body">
      <p><strong>Reason</strong></p>
      <p>{{ $note->reason }}</p>
      @if($note->restrictions)
        <p><strong>Restrictions / recommendations</strong></p>
        <p>{{ $note->restrictions }}</p>
      @endif
    </div>
    <div class="sign">
      <p>______________________________</p>
      <p>{{ $staff_name }}<br><span class="muted">Clinic Officer</span></p>
    </div>
  </div>
</body>
</html>
