<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admission Letter {{ $offer_reference }}</title>
  <style>
    @page { size: A4; margin: 10mm 12mm; }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 16px;
      font-size: 11.5px;
      line-height: 1.32;
    }
    .sheet {
      position: relative;
      max-width: 210mm;
      min-height: 277mm;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 14px 18px 12px;
      overflow: hidden;
    }
    .watermark {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      z-index: 0;
    }
    .watermark img {
      width: min(58%, 340px);
      height: auto;
      opacity: 0.07;
      object-fit: contain;
    }
    .sheet-body { position: relative; z-index: 1; }
    .header {
      display: table;
      width: 100%;
      border-bottom: 1.5px solid #0f172a;
      padding-bottom: 8px;
      margin-bottom: 10px;
    }
    .header-left, .header-right { display: table-cell; vertical-align: top; }
    .header-left { width: 68%; }
    .header-right { width: 32%; text-align: right; font-size: 10px; line-height: 1.3; }
    .brand-row { display: table; }
    .brand-row img, .brand-text { display: table-cell; vertical-align: middle; }
    .brand-row img {
      width: 48px;
      height: 48px;
      object-fit: contain;
      margin-right: 10px;
    }
    .uni-name {
      margin: 0;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.02em;
      text-transform: uppercase;
    }
    .office {
      margin: 2px 0 0;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }
    .meta {
      display: table;
      width: 100%;
      margin: 0 0 8px;
    }
    .meta-ref, .meta-date { display: table-cell; vertical-align: top; }
    .meta-ref { font-weight: 700; }
    .meta-date { text-align: right; }
    .recipient { margin: 0 0 6px; }
    .recipient .name { font-weight: 700; text-transform: uppercase; margin: 0; }
    .salutation { margin: 0 0 8px; }
    .subject {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 2px;
      font-size: 11.5px;
    }
    .subject-level {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 8px;
      font-size: 11.5px;
    }
    .subject u, .subject-level u {
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    a { color: #0000ee; text-decoration: underline; }
    p { margin: 0 0 6px; text-align: justify; }
    ol.clauses { margin: 0 0 6px; padding-left: 1.2rem; }
    ol.clauses > li { margin: 0 0 6px; text-align: justify; }
    ol.sub {
      list-style: lower-alpha;
      margin: 4px 0 0;
      padding-left: 1.15rem;
    }
    ol.sub li { margin: 0 0 3px; text-align: justify; }
    .closing { margin-top: 8px; }
    .signatory { margin-top: 10px; }
    .signatory img {
      display: block;
      max-height: 42px;
      max-width: 140px;
      margin: 0 0 2px;
      object-fit: contain;
    }
    .sign-space { height: 28px; }
    .sign-name, .sign-title { font-weight: 700; margin: 0; }
    .letter-footer {
      margin-top: 14px;
      padding-top: 8px;
      border-top: 1px solid #cbd5e1;
      text-align: center;
    }
    .letter-footer .motto {
      margin: 0;
      text-align: center;
      font-weight: 700;
      font-style: italic;
      font-size: 12px;
    }
    @media print {
      body { background: #fff; padding: 0; font-size: 11px; line-height: 1.28; }
      .sheet {
        border: none;
        max-width: none;
        min-height: auto;
        padding: 0;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .watermark img { opacity: 0.08; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    @if (!empty($logo_data_uri))
      <div class="watermark" aria-hidden="true">
        <img src="{{ $logo_data_uri }}" alt="">
      </div>
    @endif
    <div class="sheet-body">
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
        <div class="meta-ref">Ref. No.: {{ $offer_reference }}</div>
        <div class="meta-date">{{ $letter_date }}</div>
      </div>

      <div class="recipient">
        <p class="name">{{ $full_name }}</p>
      </div>

      <p class="salutation">Dear {{ $salutation_name }},</p>

      <p class="subject"><u>ADMISSION FOR THE {{ $session }} ACADEMIC SESSION</u></p>
      <p class="subject-level"><u>{{ $study_level }}@if (!empty($is_direct_entry)) (DIRECT ENTRY)@endif</u></p>

      <ol class="clauses">
        <li>
          With reference to your application for admission into {{ $institution['name'] }}, Ota, for the
          {{ $session }} Academic Session, I am pleased to inform you that you have been offered
          <strong>admission</strong> into the
          <strong>{{ strtoupper($college) }}</strong> for a <strong>{{ $programme_kind }}</strong> in
          <strong>{{ $programme }}</strong>, <strong>having fulfilled the admission requirements.</strong>
        </li>
        <li>
          Please visit the application portal at
          <a href="{{ $portal_url }}">{{ $portal_url }}</a>
          to pay the non-refundable acceptance fee of
          <strong>₦{{ number_format($acceptance_amount, 2) }}</strong>
          ({{ $acceptance_amount_words }}) only within two weeks from the date of this letter and
          <strong>print your receipt</strong>
          to avoid forfeiture of the admission offered to you.
        </li>
        <li>
          Please also note the following carefully:
          <ol class="sub">
            <li><strong>Fees paid are not refundable</strong> after acceptance of offer of admission or upon voluntary withdrawal from the Programme;</li>
            <li>All <strong> payments to {{ $institution['name'] }} are to be made online through the University’s portal only</strong>;</li>
            <li>
              Visit <a href="{{ $fees_url }}">{{ $fees_url }}</a> for the approved schedule of fees for the various Programmes;
            </li>
            <li><strong>A compulsory medical screening, which includes drug test, would be carried out on all fresh students at the University Health Centre on resumption</strong>;</li>
            <li>There will be a <strong>compulsory one week orientation programme</strong> on resumption;</li>
            <li>Students will be responsible for their feeding. However, the University has provided Cafeteria Services where food will be available on Pay-As-You-Eat (PAYE) basis;</li>
            <li>Further relevant information about your studentship is available in the Student Information Handbook, which will be supplied to you after due clearance. You are expected to familiarize yourself with the provisions of the Handbook; and</li>
            <li>
              The University pays particular interest in the dressing of students. Visit our website
              <a href="{{ $dress_code_url }}">{{ $dress_code_url }}</a>
              for details on dress codes as non-compliance will attract stiff penalty. For the avoidance of doubt
              <strong>indecent and improper dressing</strong>, including growing of long hair and beard are not allowed for men.
            </li>
          </ol>
        </li>
        <li>
          Kindly ensure that copies of the following documents are duly submitted for clearance:
          <ol class="sub">
            <li>Birth Certificate or Sworn Affidavit of Declaration of Age;</li>
            @if (!empty($show_jamb_documents))
              <li>Admission letter as issued by JAMB (Institution Copy);</li>
              <li>Unified Tertiary Matriculation Examination Result Slip; and</li>
            @endif
            <li>The Ordinary Level Results of SSCE, GCE, NECO/Equivalents.</li>
          </ol>
        </li>
      </ol>

      <div class="closing">
        <p>
          You are warmly welcome to the promising world of Bellstech and we wish you a successful academic and all-round experience.
        </p>
        <p>Accept our congratulations!</p>
      </div>

      <div class="signatory">
        @if (!empty($signature_data_uri))
          <img src="{{ $signature_data_uri }}" alt="Registrar signature">
        @else
          <div class="sign-space"></div>
        @endif
        @if (!empty($registrar_name))
          <p class="sign-name">{{ $registrar_name }}</p>
        @endif
        <p class="sign-title">{{ $registrar_title }}</p>
      </div>

      <div class="letter-footer">
        <p class="motto">‘Only the best is good for Bells’</p>
      </div>
    </div>
  </div>
</body>
</html>
