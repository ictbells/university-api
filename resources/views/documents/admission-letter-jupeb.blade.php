<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admission Letter {{ $offer_reference }}</title>
  <style>
    @page { size: A4; margin: 8mm 10mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Times New Roman", Times, serif;
      color: #111;
      background: #f8fafc;
      padding: 12px;
      font-size: 10.5px;
      line-height: 1.28;
    }
    .sheet {
      position: relative;
      max-width: 210mm;
      min-height: 277mm;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 12px 16px 10px;
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
    .letterhead { text-align: center; margin-bottom: 8px; }
    .letterhead-logo {
      display: block;
      width: 56px;
      height: 56px;
      margin: 0 auto 6px;
      object-fit: contain;
    }
    .uni-name {
      margin: 0;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .office {
      margin: 2px 0 0;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .ref {
      text-align: right;
      font-weight: 700;
      margin: 4px 0 8px;
    }
    .name {
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 6px;
    }
    .subject {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 8px;
      font-size: 10.5px;
      line-height: 1.3;
    }
    ol.clauses {
      margin: 0 0 6px;
      padding-left: 1.15em;
    }
    ol.clauses > li {
      margin: 0 0 4px;
      text-align: justify;
    }
    ol.sub {
      list-style: lower-alpha;
      margin: 3px 0 0;
      padding-left: 1.1em;
    }
    ol.sub li { margin: 0 0 2px; text-align: justify; }
    .welcome { text-align: justify; margin: 6px 0 4px; }
    .congrats {
      font-weight: 700;
      margin: 0 0 8px;
    }
    .signatory { margin-top: 4px; }
    .signatory img {
      display: block;
      max-height: 36px;
      max-width: 130px;
      margin: 0 0 2px;
      object-fit: contain;
    }
    .sign-space { height: 24px; }
    .sign-name, .sign-title { font-weight: 700; margin: 0; }
    .letter-footer {
      margin-top: 10px;
      padding-top: 6px;
      border-top: 1px solid #cbd5e1;
      text-align: center;
    }
    .letter-footer .motto {
      margin: 0;
      text-align: center;
      font-weight: 700;
      font-style: italic;
      font-size: 11px;
    }
    @media print {
      body { background: #fff; padding: 0; font-size: 10px; line-height: 1.25; }
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
      <div class="letterhead">
        @if (!empty($logo_data_uri))
          <img class="letterhead-logo" src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
        @endif
        <p class="uni-name">{{ $letterhead_name }}</p>
        <p class="office">{{ $institution['office'] }}</p>
      </div>

      <p class="ref">Ref No: {{ $offer_reference }}</p>
      <p class="name">{{ $full_name }}</p>
      <p class="subject">
        OFFER OF PROVISIONAL ADMISSION INTO THE {{ $session }} FOUNDATION PROGRAMME OF {{ $letterhead_name }}
      </p>

      <ol class="clauses">
        <li>
          With reference to your application for admission to {{ $institution_with_city }}, for the
          {{ $session }} Academic Session, I am pleased to inform you that you have been offered provisional admission into the
          <strong>{{ $programme_label }}</strong> Foundation Programme.
        </li>
        <li>
          The Foundation Programme is in conjunction with the Joint Universities Preliminary Examinations Board (JUPEB).
          The successful completion of the programme will lead to Direct Entry admission into 200 Level of the Degree Programme.
        </li>
        <li>
          Please complete the enclosed Acceptance Form and return it with the non-refundable Acceptance Fee of
          <strong>N{{ number_format($acceptance_amount, 0) }}</strong>
          ({{ $acceptance_amount_words }}) only, not later than one week from the receipt of this letter; otherwise, the Provisional Admission will be forfeited.
          Kindly note that the acceptance fee is part of the total fee of the programme.
        </li>
        <li>
          In order to qualify for Direct Entry to the Degree Programme, candidates must:
          <ol class="sub">
            <li>Pass the External Examination to be conducted by the Joint Universities Preliminary Examinations Board (JUPEB) in August {{ $jupeb_exam_year }} for Direct Entry admission into 200 Level of your course of first choice;</li>
            <li>Provide evidence of registration with the Joint Admissions and Matriculation Board (JAMB) for {{ $session }} Direct Entry; and</li>
            <li>Satisfy the minimum five O’Level entry requirements for admission into any of the University’s programmes.</li>
          </ol>
        </li>
        <li>
          Please find the approved schedule of fees for the Foundation Programme on your application portal.
        </li>
        <li>
          The full fee for the session is required to be paid at the point of registration. Management will, however, accept payment in four equal installments as follows:
          Twenty-five percent (25%) at registration; Twenty-five percent (25%) before the commencement of the first semester examination;
          Twenty-five percent (25%) at resumption for the second semester; and the remaining twenty-five percent (25%) before the commencement of the second semester examination.
        </li>
        <li>
          There is also an approved Dress Code for all students in the University. Students are to note that non-compliance attracts stiff disciplinary measures. You are therefore advised, in your own interest, to comply.
        </li>
        <li>
          You are to present for the registration exercise, both the original and one (1) photocopy each of the following:
          Birth Certificate or Sworn Affidavit of Declaration of Age; Ordinary Level results of SSCE, GCE, NECO or equivalent;
        </li>
        <li>
          You are required to print the admission documents on your student application portal, fill and submit same with 4 recent passport photographs.
        </li>
        <li>
          Students will be responsible for their own feeding through private cafeterias approved by the University, where food will be available on a Pay-As-You-Eat (PAYE) basis.
        </li>
        <li>
          On resumption, you are to report at the University Health Centre for a mandatory medical screening.
        </li>
        <li>
          Further relevant information on your studentship in the University is provided in the Student Information Handbook, which will be supplied to you at the point of registration.
        </li>
      </ol>

      <p class="welcome">
        You are warmly welcome to the promising world of Bells University of Technology, and we wish you a successful academic and all-round experience.
      </p>
      <p class="congrats">Accept our congratulations.</p>

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
