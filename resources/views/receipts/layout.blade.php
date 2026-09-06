<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt {{ $receipt_no }}</title>
  <style>
    @page { size: A4; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Calibri, "Liberation Sans", Arial, sans-serif;
      color: #0f172a;
      background: #e8eef3;
      padding: 24px 16px;
      font-size: 13px;
      line-height: 1.45;
    }
    .sheet {
      position: relative;
      max-width: 760px;
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
      font-size: 92px;
      font-weight: 800;
      letter-spacing: 0.18em;
      color: rgba(4, 120, 87, 0.07);
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
    }
    .brand {
      display: table;
    }
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
      margin-bottom: 18px;
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
    }
    .doc-title {
      margin: 0;
      font-size: 20px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #0c4a6e;
    }
    .serial {
      text-align: right;
      white-space: nowrap;
    }
    .serial span {
      display: block;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #64748b;
    }
    .serial strong {
      display: inline-block;
      margin-top: 4px;
      font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
      font-size: 15px;
      color: #0c4a6e;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      padding: 6px 10px;
      border-radius: 6px;
    }
    .qr-wrap {
      margin-top: 10px;
      text-align: right;
    }
    .qr-wrap a { display: inline-block; }
    .qr-wrap img {
      display: block;
      width: 110px;
      height: 110px;
      margin-left: auto;
      background: #fff;
    }
    .qr-wrap span {
      display: block;
      margin-top: 4px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #64748b;
    }
    .badge {
      display: inline-block;
      margin-top: 8px;
      padding: 3px 10px;
      border-radius: 999px;
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.14em;
    }
    .facts {
      width: 100%;
      border-collapse: collapse;
      position: relative;
      z-index: 1;
    }
    .facts th, .facts td {
      text-align: left;
      padding: 8px 12px 8px 0;
      vertical-align: top;
      border-bottom: 1px solid #eef2f7;
    }
    .facts th {
      width: 32%;
      color: #64748b;
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .facts td { color: #0f172a; font-weight: 600; }
    .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-weight: 600; }
    .amount-band {
      position: relative;
      z-index: 1;
      margin: 18px 0 8px;
      padding: 16px 18px;
      background: linear-gradient(180deg, #0c4a6e 0%, #075985 100%);
      color: #fff;
      border-radius: 10px;
    }
    .amount-band .label {
      margin: 0 0 4px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      opacity: 0.8;
    }
    .amount-band .figure {
      margin: 0;
      font-size: 28px;
      font-weight: 800;
      letter-spacing: 0.02em;
    }
    .amount-band .words {
      margin: 6px 0 0;
      font-size: 12px;
      font-style: italic;
      opacity: 0.95;
    }
    h3 {
      margin: 22px 0 8px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: #0c4a6e;
      position: relative;
      z-index: 1;
    }
    .lines {
      width: 100%;
      border-collapse: collapse;
      position: relative;
      z-index: 1;
      font-size: 13px;
    }
    .lines th, .lines td {
      padding: 9px 10px;
      border: 1px solid #dbe4ee;
    }
    .lines th {
      background: #0c4a6e;
      color: #fff;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .lines td.num, .lines th.num { text-align: right; white-space: nowrap; }
    .lines tfoot td {
      background: #f8fafc;
      font-weight: 800;
      color: #0c4a6e;
    }
    .signoff {
      display: table;
      width: 100%;
      margin-top: 36px;
      position: relative;
      z-index: 1;
    }
    .signoff > div {
      display: table-cell;
      width: 50%;
      padding-right: 24px;
      font-size: 12px;
      color: #475569;
    }
    .sign-dots {
      margin-top: 8px;
      letter-spacing: 0.12em;
      color: #64748b;
      font-size: 14px;
    }
    .sign-line {
      margin-top: 8px;
      padding-top: 0;
      border-top: none;
      font-weight: 700;
      color: #0f172a;
    }
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
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; box-shadow: none; max-width: none; padding: 0; }
      .sheet::before { display: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="watermark">PAID</div>
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
        <h1 class="doc-title">{{ $doc_title }}</h1>
        <span class="badge">PAID</span>
      </div>
      <div class="serial">
        <span>Receipt number</span>
        <strong>{{ $receipt_no }}</strong>
        @if (!empty($qr_data_uri))
          <div class="qr-wrap">
            @if (!empty($qr_verify_url))
              <a href="{{ $qr_verify_url }}">
                <img src="{{ $qr_data_uri }}" alt="Verify receipt {{ $receipt_no }}" width="110" height="110">
              </a>
            @else
              <img src="{{ $qr_data_uri }}" alt="Verify receipt {{ $receipt_no }}" width="110" height="110">
            @endif
            <span>Scan to verify</span>
          </div>
        @endif
      </div>
    </div>
    @yield('body')
    <div class="signoff">
      <div>
        <div class="sign-dots">……………………</div>
        <div class="sign-line">For: Bursar</div>
      </div>
      <div></div>
    </div>
    <p class="footer">
      This is a computer-generated receipt of {{ $institution['name'] }}. It is valid without a physical signature.<br>
      Keep a copy for your records. Scan the QR code to confirm this receipt online. Generated {{ $generated_at }}.
    </p>
  </div>
</body>
</html>
