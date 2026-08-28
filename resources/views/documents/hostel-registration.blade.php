<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hostel registration form {{ $matric_number }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 24px;
      font-size: 15px;
      line-height: 1.55;
    }
    .sheet {
      max-width: 800px;
      min-height: 980px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 36px 44px 48px;
      position: relative;
    }
    .header {
      display: table;
      width: 100%;
      margin-bottom: 28px;
    }
    .header-left, .header-center, .header-right {
      display: table-cell;
      vertical-align: top;
    }
    .header-left { width: 110px; }
    .header-right { width: 110px; text-align: right; }
    .header-center { text-align: center; padding: 0 12px; }
    .header-left img {
      width: 88px;
      height: 88px;
      object-fit: contain;
      display: block;
    }
    .uni-name {
      margin: 10px 0 0;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .doc-title {
      margin: 8px 0 0;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      text-decoration: underline;
    }
    .photo, .photo-empty {
      width: 104px;
      height: 120px;
      object-fit: cover;
      border: 1px solid #334155;
      background: #f8fafc;
      display: inline-block;
      vertical-align: top;
    }
    .fields { margin-top: 8px; }
    .row {
      display: table;
      width: 100%;
      margin: 0 0 10px;
    }
    .cell {
      display: table-cell;
      vertical-align: top;
      padding-right: 16px;
    }
    .cell:last-child { padding-right: 0; }
    .triple .cell { width: 33.33%; }
    .label { font-weight: 400; }
    .value { font-weight: 400; }
    .name, .programme { text-transform: uppercase; }
    .rule {
      border: 0;
      border-top: 1px solid #111;
      margin: 22px 0 18px;
    }
    .allocated {
      font-weight: 700;
      margin: 0 0 10px;
    }
    .extra {
      font-style: italic;
      margin: 0;
    }
    .sign {
      position: absolute;
      right: 44px;
      bottom: 56px;
      width: 260px;
      text-align: center;
    }
    .sign-line {
      border: 0;
      border-top: 1px dotted #111;
      margin: 0 0 6px;
    }
    .sign-label {
      margin: 0;
      font-style: italic;
      font-size: 13px;
    }
    .footer {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 16px;
      font-size: 11px;
      color: #64748b;
      text-align: center;
      font-family: "Segoe UI", system-ui, sans-serif;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; max-width: none; min-height: 100vh; padding: 12mm 14mm 22mm; }
      .sign { right: 14mm; bottom: 22mm; }
      .footer { display: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <div class="header-left">
        @if (!empty($logo_data_uri))
          <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
        @endif
      </div>
      <div class="header-center">
        <h1 class="uni-name">{{ $institution['name'] }}</h1>
        <p class="doc-title">Hostel Registration Form</p>
      </div>
      <div class="header-right">
        @if (!empty($photo_data_uri))
          <img class="photo" src="{{ $photo_data_uri }}" alt="Passport photograph">
        @else
          <span class="photo-empty" aria-hidden="true"></span>
        @endif
      </div>
    </div>

    <div class="fields">
      <div class="row triple">
        <div class="cell"><span class="label">Session:</span> <span class="value">{{ $session }}</span></div>
        <div class="cell"><span class="label">Semester:</span> <span class="value">{{ $semester }}</span></div>
        <div class="cell"><span class="label">Date:</span> <span class="value">{{ $date }}</span></div>
      </div>
      <div class="row"><span class="label">Matric No.:</span> <span class="value">{{ $matric_number }}</span></div>
      <div class="row"><span class="label">Fullname:</span> <span class="value name">{{ $full_name }}</span></div>
      <div class="row"><span class="label">Program:</span> <span class="value programme">{{ strtoupper($programme) }}</span></div>
      <div class="row"><span class="label">Gender:</span> <span class="value">{{ $gender }}</span></div>
      <div class="row"><span class="label">Phone Number:</span> <span class="value">{{ $phone }}</span></div>
      <div class="row"><span class="label">Parent's Phone Number:</span> <span class="value">{{ $parent_phone }}</span></div>
    </div>

    <hr class="rule">

    <p class="allocated">Allocated Hostel/Room: {{ $allocated_hostel }}</p>
    @if (!empty($additional_information))
      <p class="extra">Additional Information: {{ $additional_information }}</p>
    @endif

    <div class="sign">
      <hr class="sign-line">
      <p class="sign-label">Hostel's Manager Signature &amp; date</p>
    </div>

    <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p>
  </div>
</body>
</html>