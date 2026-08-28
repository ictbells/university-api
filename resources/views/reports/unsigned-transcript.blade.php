<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unsigned Transcript — {{ $report['student']['matric_number'] ?? '' }}</title>
  <style>
    @page { size: A4; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #0f172a;
      background: #e8eef3;
      padding: 24px 16px;
      font-size: 13px;
      line-height: 1.45;
    }
    .sheet {
      position: relative;
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #c5d0dc;
      padding: 28px 32px 24px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
      overflow: hidden;
    }
    .sheet::before {
      content: "";
      position: absolute;
      inset: 8px;
      border: 1px solid #d4af37;
      pointer-events: none;
    }
    .watermark {
      position: absolute;
      top: 46%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(-22deg);
      font-size: 72px;
      font-weight: 800;
      letter-spacing: 0.18em;
      color: rgba(180, 83, 9, 0.07);
      pointer-events: none;
      user-select: none;
    }
    .letterhead {
      display: table;
      width: 100%;
      position: relative;
      z-index: 1;
    }
    .letterhead-left, .letterhead-right {
      display: table-cell;
      vertical-align: middle;
    }
    .letterhead-right {
      width: 34%;
      text-align: right;
      font-size: 11px;
      color: #475569;
      line-height: 1.45;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    .brand { display: table; }
    .brand img, .brand-text { display: table-cell; vertical-align: middle; }
    .brand img {
      width: 68px;
      height: 68px;
      object-fit: contain;
      margin-right: 14px;
    }
    .uni-name {
      margin: 0;
      font-size: 17px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0c4a6e;
    }
    .office {
      margin: 3px 0 0;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #b45309;
    }
    .motto {
      margin: 4px 0 0;
      font-size: 12px;
      font-style: italic;
      color: #64748b;
    }
    .rules {
      margin: 16px 0 18px;
      border: 0;
      height: 0;
      border-top: 3px solid #0c4a6e;
      border-bottom: 1px solid #d4af37;
      position: relative;
      z-index: 1;
    }
    .doc-bar {
      display: table;
      width: 100%;
      margin-bottom: 16px;
      position: relative;
      z-index: 1;
    }
    .doc-bar > div { display: table-cell; vertical-align: middle; }
    .doc-kicker {
      margin: 0 0 4px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: #64748b;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    .doc-title {
      margin: 0;
      font-size: 20px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #0c4a6e;
    }
    .scope {
      margin: 4px 0 0;
      font-size: 12px;
      color: #475569;
    }
    .banner {
      position: relative;
      z-index: 1;
      border: 2px solid #b45309;
      background: #fffbeb;
      color: #92400e;
      padding: 10px 12px;
      margin: 0 0 16px;
      text-align: center;
      font-weight: 700;
      letter-spacing: 0.03em;
    }
    .facts {
      width: 100%;
      border-collapse: collapse;
      position: relative;
      z-index: 1;
      margin-bottom: 16px;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    .facts th, .facts td {
      text-align: left;
      padding: 7px 12px 7px 0;
      vertical-align: top;
      border-bottom: 1px solid #eef2f7;
    }
    .facts th {
      width: 28%;
      color: #64748b;
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .facts td { color: #0f172a; font-weight: 600; }
    .grid {
      display: table;
      width: 100%;
      margin-bottom: 16px;
      position: relative;
      z-index: 1;
      table-layout: fixed;
    }
    .card {
      display: table-cell;
      border: 1px solid #dbe4ee;
      border-radius: 8px;
      padding: 10px 12px;
      background: #f8fafc;
    }
    .card + .card { border-left: 0; }
    .label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #64748b;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    .value {
      font-size: 20px;
      font-weight: 800;
      margin-top: 4px;
      color: #0c4a6e;
    }
    h2 {
      margin: 18px 0 8px;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #0c4a6e;
      position: relative;
      z-index: 1;
    }
    table.courses {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 12px;
      position: relative;
      z-index: 1;
    }
    table.courses th, table.courses td {
      border: 1px solid #dbe4ee;
      padding: 7px 8px;
      text-align: left;
      vertical-align: top;
    }
    table.courses th {
      background: #0c4a6e;
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    table.courses td.num, table.courses th.num { text-align: center; white-space: nowrap; }
    table.courses tfoot td {
      background: #f8fafc;
      font-weight: 800;
      color: #0c4a6e;
    }
    .empty { color: #64748b; font-style: italic; position: relative; z-index: 1; }
    .footer {
      margin-top: 22px;
      padding-top: 12px;
      border-top: 1px dashed #cbd5e1;
      font-size: 11px;
      color: #64748b;
      text-align: center;
      line-height: 1.6;
      position: relative;
      z-index: 1;
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
    }
    .no-print { text-align: right; margin-bottom: 12px; }
    .no-print button {
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
      padding: 6px 12px;
      border: 1px solid #cbd5e1;
      background: #fff;
      border-radius: 6px;
      cursor: pointer;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; box-shadow: none; max-width: none; padding: 0; }
      .sheet::before { display: none; }
      .no-print { display: none; }
    }
  </style>
</head>
<body>
  <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>
  <div class="sheet">
    <div class="watermark">UNSIGNED</div>
    <div class="letterhead">
      <div class="letterhead-left">
        <div class="brand">
          @if (!empty($logo_data_uri))
            <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
          @endif
          <div class="brand-text">
            <p class="uni-name">{{ $institution['name'] }}</p>
            <p class="office">{{ $institution['office'] }}</p>
            @if (!empty($institution['motto']))
              <p class="motto">{{ $institution['motto'] }}</p>
            @endif
          </div>
        </div>
      </div>
      <div class="letterhead-right">
        {{ $institution['address'] }}<br>
        {{ $institution['contact'] }}
      </div>
    </div>
    <hr class="rules">

    <div class="doc-bar">
      <div>
        <p class="doc-kicker">Academic records</p>
        <h1 class="doc-title">Unsigned transcript</h1>
        @if (!empty($report['scope_label']))
          <p class="scope">{{ $report['scope_label'] }}</p>
        @endif
      </div>
    </div>

    <div class="banner">
      UNSIGNED — FOR STUDENT VIEWING ONLY<br>
      This document is not signed and is not valid for official use.
    </div>

    <table class="facts">
      <tr>
        <th>Name</th>
        <td>{{ strtoupper($report['student']['name'] ?? '') }}</td>
      </tr>
      <tr>
        <th>Matric number</th>
        <td>{{ $report['student']['matric_number'] ?? '—' }}</td>
      </tr>
      @if (!empty($report['student']['programme']))
        <tr>
          <th>Programme</th>
          <td>{{ $report['student']['programme'] }}</td>
        </tr>
      @endif
      @if (!empty($report['student']['faculty']) || !empty($report['student']['department']))
        <tr>
          <th>College / Department</th>
          <td>{{ trim(($report['student']['faculty'] ?? '').(!empty($report['student']['faculty']) && !empty($report['student']['department']) ? ' / ' : '').($report['student']['department'] ?? '')) }}</td>
        </tr>
      @endif
      @if (!empty($report['student']['level']))
        <tr>
          <th>Level</th>
          <td>{{ $report['student']['level'] }}</td>
        </tr>
      @endif
      <tr>
        <th>Generated</th>
        <td>{{ $report['generated_at'] ?? '' }}</td>
      </tr>
    </table>

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
      @php
        $rows = $term['rows'] ?? [];
        $unitsTotal = 0;
        foreach ($rows as $row) {
          $unitsTotal += (int) ($row['course']['units'] ?? 0);
        }
      @endphp
      <h2>{{ $term['session_label'] ?? '' }} · {{ $term['name'] ?? '' }} — GPA {{ isset($term['gpa']) && $term['gpa'] !== null && $term['gpa'] !== '' ? number_format((float) $term['gpa'], 2) : '—' }}</h2>
      <table class="courses">
        <thead>
          <tr>
            <th>Code</th>
            <th>Title</th>
            <th class="num">Units</th>
            <th class="num">Score</th>
            <th class="num">Grade</th>
          </tr>
        </thead>
        <tbody>
          @foreach($rows as $row)
            @php
              $released = ($row['result_status'] ?? '') === 'released';
            @endphp
            <tr>
              <td>{{ $row['course']['code'] ?? '—' }}</td>
              <td>
                {{ $row['course']['title'] ?? '—' }}
                @if(!empty($row['is_carry_over'])) (Carry-over)@endif
              </td>
              <td class="num">{{ $row['course']['units'] ?? '—' }}</td>
              <td class="num">{{ $released ? ($row['score'] ?? '—') : 'Pending' }}</td>
              <td class="num">{{ $released ? ($row['letter'] ?? '—') : 'Pending' }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2">Total</td>
            <td class="num">{{ $unitsTotal }}</td>
            <td class="num"></td>
            <td class="num"></td>
          </tr>
        </tfoot>
      </table>
    @empty
      <p class="empty">No registered courses for this session/semester.</p>
    @endforelse

    <p class="footer">
      {{ $report['notice'] ?? 'Released scores only. Official signed transcripts are issued by the Registry.' }}<br>
      Generated electronically on {{ $report['generated_at'] ?? '' }} · {{ $institution['name'] }}
    </p>
  </div>
</body>
</html>
