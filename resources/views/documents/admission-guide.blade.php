<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
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
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 36px 44px 48px;
    }
    .brand { text-align: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 2px solid #0f172a; }
    .brand img { width: 72px; height: 72px; object-fit: contain; margin: 0 auto 8px; display: block; }
    .brand h1 { margin: 0; font-size: 18px; letter-spacing: 0.02em; text-transform: uppercase; }
    .brand .motto { margin: 4px 0 0; color: #475569; font-size: 12px; font-style: italic; }
    .doc-title { margin: 16px 0 10px; font-size: 16px; font-weight: 700; text-align: center; text-transform: uppercase; text-decoration: underline; }
    .intro { margin: 0 0 18px; }
    h2 { margin: 22px 0 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.03em; }
    .section p { margin: 0; white-space: pre-wrap; }
    .footer { margin-top: 32px; font-size: 11px; color: #64748b; text-align: center; font-family: "Segoe UI", system-ui, sans-serif; }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; max-width: none; padding: 12mm 14mm; }
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
    </div>
    <p class="doc-title">{{ $title }}</p>
    @if (!empty($intro))
      <p class="intro">{{ $intro }}</p>
    @endif
    @foreach ($sections as $section)
      <div class="section">
        <h2>{{ $section['heading'] ?? 'Section' }}</h2>
        <p>{{ $section['body'] ?? '' }}</p>
      </div>
    @endforeach
    <p class="footer">
      Generated electronically on {{ $generated_at }}
      @if (!empty($published_at)) · Published {{ $published_at->format('d M Y') }} @endif
      · {{ $institution['name'] }}
    </p>
  </div>
</body>
</html>
