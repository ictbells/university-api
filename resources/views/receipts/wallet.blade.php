<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt {{ $payment->receipt_no ?? $payment->reference }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", system-ui, sans-serif;
      color: #0f172a;
      background: #f8fafc;
      padding: 24px;
    }
    .sheet {
      max-width: 720px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }
    .brand {
      text-align: center;
      padding-bottom: 20px;
      border-bottom: 1px solid #e2e8f0;
      margin-bottom: 8px;
    }
    .brand img {
      width: 72px;
      height: 72px;
      object-fit: contain;
      border-radius: 999px;
      background: #fff;
      margin: 0 auto 12px;
      display: block;
    }
    .brand h1 { margin: 0; font-size: 22px; color: #0f172a; }
    .brand .motto { margin: 6px 0 0; color: #64748b; font-size: 13px; font-style: italic; }
    .brand .doc-title { margin: 10px 0 0; color: #475569; font-size: 14px; font-weight: 600; }
    .badge-wrap { margin-top: 12px; }
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: #ecfdf5;
      color: #047857;
      font-size: 12px;
      font-weight: 600;
    }
    h2 { margin: 28px 0 12px; font-size: 16px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 10px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    th { color: #64748b; font-weight: 500; width: 38%; }
    .total { font-size: 18px; font-weight: 700; color: #0369a1; }
    .footer { margin-top: 28px; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.6; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; box-shadow: none; border-radius: 0; max-width: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="brand">
      @if (!empty($logo_data_uri))
        <img src="{{ $logo_data_uri }}" alt="{{ $university }} crest">
      @endif
      <h1>{{ $university }}</h1>
      <p class="motto">{{ $motto }}</p>
      <p class="doc-title">Campus wallet funding receipt</p>
      <div class="badge-wrap"><span class="badge">PAID</span></div>
    </div>

    <h2>Receipt details</h2>
    <table>
      <tr><th>Category</th><td>Wallet funding</td></tr>
      <tr><th>Payer</th><td>{{ $payer ?: $payment->user?->name }}</td></tr>
      @if ($payment->receipt_no)
        <tr><th>Receipt number</th><td>{{ $payment->receipt_no }}</td></tr>
      @endif
      <tr><th>Payment method</th><td>{{ $payment_method }}</td></tr>
      <tr><th>Payment reference</th><td>{{ $payment->reference ?: '—' }}</td></tr>
      <tr><th>Date paid</th><td>{{ optional($payment->created_at)->format('d M Y, h:i A') }}</td></tr>
      <tr><th>Amount credited</th><td class="total">₦{{ number_format((float) $payment->amount, 2) }}</td></tr>
    </table>

    <p class="footer">
      This receipt confirms that your campus wallet was funded successfully. Keep it for your records.<br>
      Receipt generated on {{ $generated_at }}
    </p>
  </div>
</body>
</html>
