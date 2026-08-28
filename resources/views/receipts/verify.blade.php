<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $verified ? 'Receipt verified' : 'Receipt could not be verified' }} {{ $receipt_no }}</title>
  <style>
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
      max-width: 560px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #c5d0dc;
      padding: 28px 32px 24px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }
    .letterhead { display: table; width: 100%; }
    .letterhead-left, .letterhead-right { display: table-cell; vertical-align: middle; }
    .letterhead-right { width: 38%; text-align: right; font-size: 11px; color: #475569; line-height: 1.45; }
    .brand { display: table; }
    .brand img, .brand-text { display: table-cell; vertical-align: middle; }
    .brand img { width: 56px; height: 56px; object-fit: contain; margin-right: 12px; }
    .uni-name { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #0c4a6e; }
    .office { margin: 3px 0 0; font-size: 11px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: #b45309; }
    .rules { margin: 16px 0 18px; border: 0; height: 0; border-top: 3px solid #0c4a6e; border-bottom: 1px solid #d4af37; }
    .status {
      margin: 0 0 16px;
      padding: 12px 14px;
      border-radius: 10px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .status.ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .status.bad { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .facts { width: 100%; border-collapse: collapse; }
    .facts th, .facts td { text-align: left; padding: 8px 12px 8px 0; vertical-align: top; border-bottom: 1px solid #eef2f7; }
    .facts th { width: 38%; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
    .facts td { color: #0f172a; font-weight: 600; }
    .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; }
    .amount { margin: 16px 0 0; padding: 14px 16px; background: #0c4a6e; color: #fff; border-radius: 10px; }
    .amount .label { margin: 0 0 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; opacity: 0.8; }
    .amount .figure { margin: 0; font-size: 24px; font-weight: 800; }
    .note { margin: 18px 0 0; font-size: 12px; color: #64748b; }
    .footer { margin-top: 20px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 11px; color: #64748b; text-align: center; }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="letterhead">
      <div class="letterhead-left">
        <div class="brand">
          @if (!empty($logo_data_uri))
            <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
          @endif
          <div class="brand-text">
            <p class="uni-name">{{ $institution['name'] }}</p>
            <p class="office">{{ $institution['office'] }}</p>
          </div>
        </div>
      </div>
      <div class="letterhead-right">
        {{ $institution['address'] }}<br>
        {{ $institution['contact'] }}
      </div>
    </div>
    <hr class="rules">

    @if ($verified)
      <p class="status ok">Receipt verified</p>
      <table class="facts">
        <tr><th>Receipt number</th><td class="mono">{{ $receipt_no }}</td></tr>
        <tr><th>Received from</th><td>{{ $payer }}</td></tr>
        @if (!empty($payer_id))
          <tr><th>{{ $payer_id_label }}</th><td class="mono">{{ $payer_id }}</td></tr>
        @endif
        <tr><th>Payment for</th><td>{{ $category_label }}</td></tr>
        <tr><th>Date paid</th><td>{{ $paid_at }}</td></tr>
        <tr><th>Status</th><td>PAID</td></tr>
      </table>
      <div class="amount">
        <p class="label">Amount received</p>
        <p class="figure">₦{{ number_format((float) $amount, 2) }}</p>
      </div>
      <p class="note">This receipt matches a successful payment in the bursary records of {{ $institution['name'] }}.</p>
    @else
      <p class="status bad">This receipt could not be verified</p>
      <p class="note">The QR code is valid, but bursary records do not show a successful payment for this receipt. Do not accept it as proof of payment.</p>
    @endif

    <p class="footer">Bursary verification · {{ $institution['name'] }}</p>
  </div>
</body>
</html>
