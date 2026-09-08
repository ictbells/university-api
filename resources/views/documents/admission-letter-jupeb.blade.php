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
      font-size: 13px;
      line-height: 1.45;
    }
    .sheet {
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      padding: 40px 48px 36px;
    }
    .letterhead { text-align: center; margin-bottom: 18px; }
    .uni-name {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .office {
      margin: 4px 0 0;
      font-size: 14px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .ref {
      text-align: right;
      font-weight: 700;
      margin: 6px 0 16px;
    }
    .name {
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 16px;
    }
    .subject {
      text-align: center;
      font-weight: 700;
      text-transform: uppercase;
      margin: 0 0 16px;
      font-size: 13px;
      line-height: 1.4;
    }
    ol.clauses {
      margin: 0 0 14px;
      padding-left: 1.4em;
    }
    ol.clauses > li {
      margin: 0 0 10px;
      text-align: justify;
    }
    ol.sub {
      list-style: lower-alpha;
      margin: 8px 0 0;
      padding-left: 1.4em;
    }
    ol.sub li { margin: 0 0 6px; text-align: justify; }
    .welcome { text-align: justify; margin: 14px 0 12px; }
    .motto {
      font-weight: 700;
      margin: 0 0 8px;
    }
    .congrats {
      font-weight: 700;
      margin: 0 0 28px;
    }
    .signatory { margin-top: 8px; }
    .signatory img {
      display: block;
      max-height: 64px;
      max-width: 180px;
      margin: 0 0 4px;
      object-fit: contain;
    }
    .sign-space { height: 48px; }
    .sign-name, .sign-title { font-weight: 700; margin: 0; }
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
    <div class="letterhead">
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
    <p class="motto">“Only the best is good for Bells”</p>
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

    <!-- <p class="footer">Generated electronically on {{ $generated_at }} · {{ $institution['name'] }}</p> -->
  </div>
</body>
</html>
