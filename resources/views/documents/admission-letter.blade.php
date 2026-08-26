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
    ol > li { margin: 0 0 12px; text-align: justify; }
    .notes { margin: 8px 0 0 1.1rem; padding: 0; }
    .notes li { margin: 0 0 6px; text-align: justify; }
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
      OFFER OF ADMISSION FOR THE {{ $session }} ACADEMIC SESSION
    </p>

    <ol>
      <li>
        With reference to your application for admission into {{ $institution['name'] }}, Ota, for the
        {{ $session }} Academic Session, I am pleased to inform you that you have been offered admission into the
        <strong>{{ strtoupper($college) }}</strong> for {{ $programme_kind }} in
        <strong>{{ $programme }}</strong>, having fulfilled the admission requirements.
      </li>
      <li>
        Please visit the application portal at
        <strong>{{ $portal_url }}</strong>
        to pay the non-refundable acceptance fee of
        <strong>N{{ number_format($acceptance_amount, 0) }}</strong>
        ({{ $acceptance_amount_words }}) only within two weeks from the date of this letter and print your receipt
        to avoid forfeiture of the admission offered to you.
      </li>
      <li>
        Please also note the following carefully:
        <ul class="notes">
          <li>Be informed that fees paid are not refundable after acceptance of offer of admission or upon voluntary withdrawal from the Programme;</li>
          <li>All payments to {{ $institution['name'] }} are to be made online through the University’s portal only;</li>
          <li>
            Visit <strong>{{ $fees_url }}</strong> for the approved schedule of fees for the various Programmes;
          </li>
          <li>A compulsory medical screening, which includes drug test, would be carried out on all fresh students at the University Health Centre on resumption;</li>
          <li>There will be a compulsory one-week orientation programme on resumption;</li>
          <li>Students will be responsible for their feeding. However, the University has provided Cafeteria Services where food will be available on Pay-As-You-Eat (PAYE) basis;</li>
          <li>Further relevant information about your studentship is available in the Student Information Handbook, which will be supplied to you after due clearance. You are expected to familiarize yourself with the provisions of the Handbook; and</li>
          <li>
            The University pays particular interest in the dressing of students. Visit our website
            <strong>{{ $dress_code_url }}</strong>
            for details on dress codes as non-compliance will attract stiff penalty. For the avoidance of doubt indecent and improper dressing, including growing of long hair and beard are not allowed for men.
          </li>
        </ul>
      </li>
      <li>
        Kindly ensure that copies of the following documents are duly submitted for clearance:
        <ul class="notes">
          <li>Birth Certificate or Sworn Affidavit of Declaration of Age;</li>
          @if (!empty($show_jamb_documents))
            <li>Admission letter as issued by JAMB (Institution Copy);</li>
            <li>Unified Tertiary Matriculation Examination Result Slip; and</li>
          @endif
          <li>The Ordinary Level Results of SSCE, GCE, NECO/Equivalents.</li>
        </ul>
      </li>
    </ol>

    <div class="closing">
      <p>
        You are warmly welcome to the promising world of Bellstech and we wish you a successful academic and all-round experience.
      </p>
      <p>Accept our congratulations!</p>
      <p class="motto">‘Only the best is good for Bells’</p>
    </div>

    <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p>
  </div>
</body>
</html>
