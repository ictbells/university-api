<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admission Letter {{ $offer_reference }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 24px;
      font-size: 13.5px;
      line-height: 1.45;
    }
    .sheet {
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 36px 40px;
    }
    .header {
      display: table;
      width: 100%;
      border-bottom: 2px solid #0f172a;
      padding-bottom: 14px;
      margin-bottom: 18px;
    }
    .header-left, .header-right { display: table-cell; vertical-align: top; }
    .header-left { width: 70%; }
    .header-right { width: 30%; text-align: right; font-size: 12px; line-height: 1.4; }
    .brand-row { display: table; }
    .brand-row img, .brand-text { display: table-cell; vertical-align: middle; }
    .brand-row img {
      width: 64px;
      height: 64px;
      object-fit: contain;
      margin-right: 12px;
    }
    .uni-name {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    .office {
      margin: 4px 0 0;
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
    }
    .meta { margin: 0 0 16px; }
    .meta p { margin: 0 0 4px; }
    .recipient { margin: 18px 0 14px; }
    .recipient .name { font-weight: 700; text-transform: uppercase; margin: 0 0 4px; }
    .recipient .addr { margin: 0; white-space: pre-line; }
    .subject {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      text-decoration: underline;
      margin: 18px 0;
      font-size: 13px;
    }
    p { margin: 0 0 12px; text-align: justify; }
    ol { margin: 8px 0 12px 1.25rem; padding: 0; }
    ol li { margin: 0 0 8px; text-align: justify; }
    .closing { margin-top: 22px; }
    .motto { font-style: italic; }
    .footer {
      margin-top: 28px;
      font-size: 11px;
      color: #64748b;
      text-align: center;
      font-family: "Segoe UI", system-ui, sans-serif;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; max-width: none; padding: 12mm; }
      .footer { display: none; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <div class="header-left">
        <div class="brand-row">
          @if (!empty($logo_data_uri))
            <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
          @endif
          <div class="brand-text">
            <p class="uni-name">{{ $institution['name'] }}</p>
            <p class="office">{{ $institution['office'] }}</p>
          </div>
        </div>
      </div>
      <div class="header-right">
        {{ $institution['address'] }}<br>
        {{ $institution['contact'] }}
      </div>
    </div>

    <div class="meta">
      <p><strong>{{ $offer_reference }}</strong></p>
      <p>{{ $letter_date }}</p>
    </div>

    <div class="recipient">
      <p class="name">{{ $full_name }}</p>
      @if (!empty($address))
        <p class="addr">{{ $address }}</p>
      @endif
    </div>

    <p>Dear {{ $salutation_name }},</p>

    <p class="subject">
      ADMISSION FOR THE {{ $session }} ACADEMIC SESSION {{ $study_level }}
    </p>

    <p>
      I am pleased to inform you that you have been offered admission into the
      <strong>{{ strtoupper($college) }}</strong> for {{ $programme_kind }} in
      <strong>{{ $programme }}</strong>.
    </p>

    <p>
      To accept this offer, please visit
      <strong>{{ $portal_url }}</strong>
      to pay a non-refundable acceptance fee of
      <strong>N{{ number_format($acceptance_amount, 0) }}</strong>
      ({{ $acceptance_amount_words }}) within two weeks of the date of this letter
      (on or before <strong>{{ $deadline }}</strong>).
    </p>

    <p>Please note the following:</p>
    <ol type="a">
      <li>Payment of the acceptance fee must be made online through the University portal only.</li>
      <li>Do not pay any fee through an individual or unauthorised channel.</li>
      <li>
        Details of the approved school fees schedule are available at
        <strong>{{ $fees_url }}</strong>.
      </li>
      <li>
        You are required to undergo a compulsory medical screening, including a drug test,
        at the University Health Centre upon resumption.
      </li>
      <li>A one-week orientation programme will be organised for all fresh students.</li>
      <li>Feeding on campus operates on a Pay-As-You-Eat basis.</li>
      <li>You will be issued a Student Information Handbook on resumption.</li>
      <li>
        The University maintains a strict dress code. Long hair and beards are not allowed for male students.
        More information is available at <strong>{{ $dress_code_url }}</strong>.
      </li>
    </ol>

    <p>For clearance, you will be required to present the following documents:</p>
    <ol type="a">
      <li>Birth Certificate or Sworn Affidavit of Declaration of Age.</li>
      <li>Admission letter as issued by JAMB (Institution Copy).</li>
      <li>Unified Tertiary Matriculation Examination (UTME) Result Slip.</li>
      <li>Ordinary Level Results of SSCE, GCE, NECO, or equivalents.</li>
    </ol>

    <div class="closing">
      <p>Accept our congratulations!</p>
      <p class="motto">“Only the best is good for Bells”.</p>
    </div>

    <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p>
  </div>
</body>
</html>
