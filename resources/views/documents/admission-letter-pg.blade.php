<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admission Letter {{ $offer_reference }}</title>
  <style>
    @page { size: A4; margin: 10mm 12mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 16px;
      font-size: 11px;
      line-height: 1.32;
    }
    .sheet {
      position: relative;
      max-width: 210mm;
      min-height: 277mm;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 14px 18px 16px;
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
    .recipient { margin: 0 0 8px; }
    .recipient .name { font-weight: 700; text-transform: none; margin: 0; }
    .recipient .addr { margin: 0; }
    .salutation { margin: 0 0 8px; }
    .subject {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 8px;
      font-size: 11px;
    }
    p { margin: 0 0 6px; text-align: justify; }
    ol.clauses { margin: 0 0 6px; padding-left: 1.2rem; }
    ol.clauses > li { margin: 0 0 6px; text-align: justify; }
    ol.sub {
      list-style: lower-alpha;
      margin: 4px 0 6px;
      padding-left: 1.15rem;
    }
    ol.sub li { margin: 0 0 3px; text-align: justify; }
    ol.roman {
      list-style: lower-roman;
      margin: 3px 0 0;
      padding-left: 1.15rem;
    }
    ol.roman li { margin: 0 0 2px; text-align: justify; }
    .closing { margin-top: 8px; }
    .signatory { margin-top: 14px; }
    .yours { margin: 0 0 28px; }
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
      body { background: #fff; padding: 0; font-size: 10.5px; line-height: 1.28; }
      .sheet {
        border: none;
        max-width: none;
        min-height: auto;
        padding: 0;
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
        <div class="meta-ref">{{ $offer_reference }}</div>
        <div class="meta-date">{{ $letter_date }}</div>
      </div>

      <div class="recipient">
        <p class="name">{{ $recipient_name }}</p>
        @foreach ($address_lines as $line)
          <p class="addr">{{ $line }}</p>
        @endforeach
      </div>

      <p class="salutation">Dear {{ $honorific }} {{ $salutation_surname }},</p>

      <p class="subject">PROVISIONAL ADMISSION INTO POSTGRADUATE PROGRAMME FOR {{ $session }} ACADEMIC SESSION.</p>

      <ol class="clauses">
        <li>
          I am pleased to inform you that you have been offered a provisional admission to pursue
          a {{ $programme_pursuit }} in the Department of {{ $department }}, {{ $college }}.
        </li>
        <li>
          Your admission is subject to the following conditions:
          <ol class="sub">
            <li>You are expected to accept this provisional offer of admission within two weeks from the date of this letter;</li>
            <li>
              You are required to pay a non-refundable acceptance fee of
              {{ $acceptance_amount_words_lower }} (N{{ number_format($acceptance_amount, 0) }}) only,
              which shall be a part of the fees while the balance should be paid in two equal installments as follows;
              <ol class="roman">
                <li>50% at the commencement of the Semester and before the First Semester Examinations;</li>
                <li>50% at the commencement of the Semester and before the Second Semester Examinations;</li>
              </ol>
            </li>
            <li>If it is discovered that you are not qualified for admission into the above programme or that your admission is based on false information supplied by you, your admission will automatically be nullified.</li>
            <li>You should not be a registered student in another Department of a College/Faculty of this or any other University pursuing two courses concurrently.</li>
          </ol>
          Failure to comply with any of the conditions in paragraph 2 of this provisional admission letter will automatically lead to withdrawal of the offer of admission and in case the offer had been accepted, it will automatically lead to the termination of your studentship.
        </li>
        <li>
          Deferment of admission should take place in two (2) months of offer of admission or a week after the Matriculation.
        </li>
        <li>
          Official Transcripts of the Academic Records should be forwarded to the Secretary, College of Postgraduate Studies, under confidential cover by your Undergraduate Institution.
        </li>
        <li>
          You are required to bring along the following documents to the Postgraduate School for registration and clearance.
          <ol class="sub">
            <li>Original(s) of a copy of form receipt from the portal upon payment for online application;</li>
            <li>Originals and four (4) photocopies each of all the academic certificates specified in your application form;</li>
            <li>Originals and (4) photocopies each of your birth certificate or sworn affidavit of declaration of age;</li>
            <li>Four (4) copies of a recent passport photographs;</li>
          </ol>
        </li>
        <li>
          You are expected to register at the ICT Resource Centre within one month of acceptance of the offer of admission, Failure to do so will attract late registration payment;
        </li>
        <li>
          Semester Registration for all students (PGD/ M.Eng/ M.Sc/ MBA &amp; Ph.D.) is mandatory and must be strictly adhered to; Non-Registration of Courses for two (2) consecutive Sessions nullifies your Studentship from Bells University of Technology;
        </li>
        <li>
          Students are expected to be responsible for their accommodation;
        </li>
        <li>
          You are warmly welcome to the promising world of Bells University of Technology, Ota, we wish you a successful academic and all round experience.
        </li>
      </ol>

      <div class="closing">
        <p>Accept our congratulations.</p>
      </div>

      <div class="signatory">
        <p class="yours">Yours faithfully,</p>
        @if (!empty($signatory_name))
          <p class="sign-name">{{ $signatory_name }}</p>
        @endif
        <p class="sign-title">{{ $signatory_title }}</p>
      </div>

      
    </div>
  </div>
</body>
</html>
