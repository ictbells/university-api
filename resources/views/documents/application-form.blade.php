<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Application Form {{ $application->application_number ?: $application->id }}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", system-ui, sans-serif;
      color: #0f172a;
      background: #f8fafc;
      padding: 24px;
      font-size: 13px;
      line-height: 1.4;
    }
    .sheet {
      max-width: 860px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 28px 32px;
    }
    .brand { text-align: center; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0; }
    .brand img {
      width: 64px; height: 64px; object-fit: contain; border-radius: 999px;
      background: #fff; margin: 0 auto 10px; display: block;
    }
    .brand h1 { margin: 0; font-size: 18px; }
    .brand .motto { margin: 4px 0 0; color: #64748b; font-size: 12px; font-style: italic; }
    .brand .doc-title { margin: 10px 0 0; font-size: 14px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
    .meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 16px;
      margin-bottom: 18px;
      font-size: 12.5px;
    }
    .meta .label { color: #64748b; }
    .meta .value { font-weight: 600; }
    h2 {
      margin: 22px 0 10px;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #0369a1;
      border-bottom: 1px solid #e2e8f0;
      padding-bottom: 6px;
    }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 7px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    th { color: #64748b; font-weight: 500; width: 38%; }
    .identity { display: flex; gap: 16px; align-items: flex-start; margin-bottom: 18px; }
    .identity-body { flex: 1; min-width: 0; }
    .identity .meta { margin-bottom: 0; }
    .choices {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 16px;
      margin-top: 12px;
    }
    .choice-col {
      display: flex;
      flex-direction: column;
      gap: 6px;
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #f8fafc;
    }
    .choice-heading {
      margin: 0 0 2px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0369a1;
    }
    .photo {
      width: 110px;
      height: 130px;
      object-fit: cover;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      background: #f1f5f9;
      flex-shrink: 0;
    }
    .footer {
      clear: both;
      margin-top: 24px;
      font-size: 11px;
      color: #94a3b8;
      text-align: center;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .sheet { border: none; border-radius: 0; box-shadow: none; max-width: none; }
    }
  </style>
</head>
<body>
  @php
    $val = function ($key, $source = null) use ($biodata, $contact) {
        $source = $source ?? $biodata;
        $v = $source[$key] ?? null;
        if (is_bool($v)) return $v ? 'Yes' : 'No';
        if ($v === null || $v === '') return '—';
        return $v;
    };
    $photoUri = $photo_data_uri ?? null;
  @endphp

  <div class="sheet">
    <div class="brand">
      @if (!empty($logo_data_uri))
        <img src="{{ $logo_data_uri }}" alt="{{ $institution['name'] }} crest">
      @endif
      <h1>{{ $institution['name'] }}</h1>
      <p class="motto">{{ $institution['motto'] }}</p>
      <p class="doc-title">Application Form Printout</p>
    </div>

    <div class="identity">
      @if ($photoUri)
        <img class="photo" src="{{ $photoUri }}" alt="Passport photograph">
      @endif
      <div class="identity-body">
      <div class="meta">
      <div><span class="label">Application number:</span> <span class="value">{{ $application->application_number ?: '—' }}</span></div>
      <div><span class="label">JAMB registration:</span> <span class="value">{{ $application->jamb_registration ?: $application->user?->jamb_registration ?: '—' }}</span></div>
      <div><span class="label">Entry mode:</span> <span class="value">{{ strtoupper((string) $application->entry_mode) }}</span></div>
      <div><span class="label">Session:</span> <span class="value">{{ $application->intake?->term?->session_label ?: '—' }}</span></div>
      </div>
      <div class="choices">
        <div class="choice-col">
          <p class="choice-heading">1st choice</p>
          <div><span class="label">Programme:</span> <span class="value">{{ $first_choice ?: ($programme ?: '—') }}</span></div>
          <div><span class="label">College:</span> <span class="value">{{ $first_choice_college ?: ($college ?: '—') }}</span></div>
          <div><span class="label">Department:</span> <span class="value">{{ $first_choice_department ?: ($department ?: '—') }}</span></div>
        </div>
        <div class="choice-col">
          <p class="choice-heading">2nd choice</p>
          <div><span class="label">Programme:</span> <span class="value">{{ $second_choice ?: '—' }}</span></div>
          <div><span class="label">College:</span> <span class="value">{{ $second_choice_college ?: '—' }}</span></div>
          <div><span class="label">Department:</span> <span class="value">{{ $second_choice_department ?: '—' }}</span></div>
        </div>
      </div>
      </div>
    </div>

    <h2>Personal Details</h2>
    <table>
      <tr><th>Full Name</th><td>{{ $full_name }}</td></tr>
      <tr><th>JAMB Registration No</th><td>{{ $application->jamb_registration ?: $application->user?->jamb_registration ?: '—' }}</td></tr>
      <tr><th>Email</th><td>{{ $application->user?->email ?: '—' }}</td></tr>
      <tr><th>Phone Number</th><td>{{ $val('phone', $contact) !== '—' ? $val('phone', $contact) : ($application->user?->phone ?: '—') }}</td></tr>
      <tr><th>Date of Birth</th><td>{{ !empty($biodata['date_of_birth']) ? \Illuminate\Support\Carbon::parse($biodata['date_of_birth'])->format('d F Y') : '—' }}</td></tr>
      <tr><th>Gender</th><td>{{ $val('gender') }}</td></tr>
      <tr><th>Marital Status</th><td>{{ $val('marital_status') }}</td></tr>
      <tr><th>Religion</th><td>{{ $val('religion') }}</td></tr>
      <tr><th>Country</th><td>{{ $val('country') }}</td></tr>
      <tr><th>State/Province</th><td>{{ $val('state') }}</td></tr>
      <tr><th>LGA</th><td>{{ $val('lga') }}</td></tr>
      <tr><th>Address</th><td>{{ $val('address', $contact) }}</td></tr>
      <tr><th>NIN</th><td>{{ $val('nin') }}</td></tr>
    </table>

    <h2>Health Information</h2>
    <table>
      <tr><th>Blood Group</th><td>{{ $val('blood_group') }}</td></tr>
      <tr><th>Genotype</th><td>{{ $val('genotype') }}</td></tr>
      <tr><th>Medical Condition/Disabilities</th><td>{{ array_key_exists('has_medical_condition', $biodata) ? $val('has_medical_condition') : '—' }}</td></tr>
      <tr><th>Health Condition Details</th><td>{{ $val('medical_condition_details') }}</td></tr>
    </table>

    <h2>Next of Kin Information</h2>
    <table>
      <tr><th>Name</th><td>{{ $val('next_of_kin') }}</td></tr>
      <tr><th>Relationship</th><td>{{ $val('next_of_kin_relationship') }}</td></tr>
      <tr><th>Phone Number</th><td>{{ $val('next_of_kin_phone') }}</td></tr>
      <tr><th>Email</th><td>{{ $val('next_of_kin_email') }}</td></tr>
      <tr><th>Address</th><td>{{ $val('next_of_kin_address') }}</td></tr>
    </table>

    <h2>Sponsor</h2>
    <table>
      <tr><th>Name</th><td>{{ $val('sponsor_name') }}</td></tr>
      <tr><th>Relationship</th><td>{{ $val('sponsor_relationship') }}</td></tr>
      <tr><th>Phone Number</th><td>{{ $val('sponsor_phone') }}</td></tr>
      <tr><th>Email</th><td>{{ $val('sponsor_email') }}</td></tr>
      <tr><th>Address</th><td>{{ $val('sponsor_address') }}</td></tr>
    </table>

    @php
      $sittings = [];
      $firstSitting = $academic['first_sitting'] ?? $academic['first_sitting'] ?? null;
      $secondSitting = $academic['second_sitting'] ?? $academic['second_sitting'] ?? null;
      if (!empty($firstSitting)) {
        $sittings[] = ['label' => "O'Level — First sitting", 'data' => $firstSitting];
      } elseif (!empty($academic['olevel_results'])) {
        $sittings[] = ['label' => "O'Level Results", 'data' => ['results' => $academic['olevel_results']]];
      }
      if (!empty($secondSitting['results'])) {
        $sittings[] = ['label' => "O'Level — Second sitting", 'data' => $secondSitting];
      }
    @endphp

    @foreach ($sittings as $sitting)
      <h2>{{ $sitting['label'] }}</h2>
      <table>
        <tr><th>Exam type</th><td>{{ $sitting['data']['exam_type'] ?? '—' }}</td></tr>
        <tr><th>Exam centre</th><td>{{ $sitting['data']['exam_center'] ?? $sitting['data']['exam_centre'] ?? '—' }}</td></tr>
        <tr><th>Exam year</th><td>{{ $sitting['data']['exam_year'] ?? '—' }}</td></tr>
        <tr><th>Exam number</th><td>{{ $sitting['data']['exam_number'] ?? '—' }}</td></tr>
      </table>
      <table>
        @foreach (($sitting['data']['results'] ?? []) as $row)
          <tr>
            <th>{{ $row['subject_name'] ?? $row['subject_name'] ?? $row['subject'] ?? 'Subject' }}</th>
            <td>{{ $row['grade'] ?? '—' }}</td>
          </tr>
        @endforeach
      </table>
    @endforeach

    @php $utme = $academic['utme'] ?? []; @endphp
    @if (!empty($utme['aggregate']) || !empty($utme['course_choice']) || !empty($utme['subjects']) || !empty($utme['institution_choices']))
      <h2>UTME / JAMB</h2>
      <table>
        <tr><th>Examination year</th><td>{{ $utme['exam_year'] ?? '—' }}</td></tr>
        <tr><th>Aggregate</th><td>{{ $utme['aggregate'] ?? '—' }}</td></tr>
        <tr><th>Course choice</th><td>{{ $utme['course_choice'] ?? '—' }}</td></tr>
      </table>
      @if (!empty($utme['subjects']))
        <table>
          @foreach ($utme['subjects'] as $row)
            <tr>
              <th>{{ $row['subject'] ?? 'Subject' }}</th>
              <td>{{ $row['score'] ?? '—' }}</td>
            </tr>
          @endforeach
        </table>
      @endif
      @if (!empty($utme['institution_choices']))
        <h2>JAMB institution choices</h2>
        <table>
          @foreach ($utme['institution_choices'] as $choice)
            <tr>
              <th>Choice {{ $choice['choice_order'] ?? '' }}</th>
              <td>{{ $choice['institution_name'] ?? '—' }}{{ !empty($choice['programme_name']) ? ' — '.$choice['programme_name'] : '' }}</td>
            </tr>
          @endforeach
        </table>
      @endif
    @endif

    @if (!empty($direct_entry['previous_institution']) || !empty($direct_entry['qualification_title']))
      <h2>Direct Entry</h2>
      <table>
        <tr><th>JAMB DE number</th><td>{{ $direct_entry['jamb_de_number'] ?? ($application->jamb_registration ?: '—') }}</td></tr>
        <tr><th>Previous institution</th><td>{{ $direct_entry['previous_institution'] ?? '—' }}</td></tr>
        <tr><th>Qualification</th><td>{{ $direct_entry['qualification_title'] ?? '—' }} ({{ str_replace('_', ' ', $direct_entry['qualification_type'] ?? '') }})</td></tr>
        <tr><th>Class</th><td>{{ str_replace('_', ' ', $direct_entry['qualification_class'] ?? '—') }}</td></tr>
        <tr><th>Year</th><td>{{ $direct_entry['qualification_year'] ?? '—' }}</td></tr>
        <tr><th>Programme</th><td>{{ $direct_entry['programme'] ?? '—' }}</td></tr>
        <tr><th>Requested entry level</th><td>{{ $direct_entry['requested_entry_level'] ?? '—' }}</td></tr>
      </table>
    @endif

    @if (!empty($transfer_background['previous_university']))
      <h2>Transfer background</h2>
      <table>
        <tr><th>Previous university</th><td>{{ $transfer_background['previous_university'] ?? '—' }}</td></tr>
        <tr><th>Previous programme</th><td>{{ $transfer_background['previous_programme'] ?? '—' }}</td></tr>
        <tr><th>Previous student ID</th><td>{{ $transfer_background['previous_student_id'] ?? '—' }}</td></tr>
        <tr><th>Credits earned</th><td>{{ $transfer_background['credits_earned'] ?? '—' }}</td></tr>
        <tr><th>CGPA</th><td>{{ $transfer_background['cgpa'] ?? '—' }}</td></tr>
        <tr><th>Requested entry level</th><td>{{ $transfer_background['requested_entry_level'] ?? '—' }}</td></tr>
        <tr><th>Transfer approval</th><td>{{ !empty($transfer_background['has_transfer_approval']) ? 'Yes' : 'No' }}{{ !empty($transfer_background['approval_reference']) ? ' — '.$transfer_background['approval_reference'] : '' }}</td></tr>
        <tr><th>Reason</th><td>{{ $transfer_background['reason_for_transfer'] ?? '—' }}</td></tr>
      </table>
    @endif

    @if (!empty($credit_assessment['decision']))
      <h2>Credit transfer assessment</h2>
      <table>
        <tr><th>Decision</th><td>{{ str_replace('_', ' ', $credit_assessment['decision'] ?? '—') }}</td></tr>
        <tr><th>Approved entry level</th><td>{{ $credit_assessment['approved_entry_level'] ?? '—' }}</td></tr>
        <tr><th>Credits accepted</th><td>{{ $credit_assessment['credits_accepted'] ?? '—' }}</td></tr>
        <tr><th>Credits waived</th><td>{{ $credit_assessment['credits_waived'] ?? '—' }}</td></tr>
        <tr><th>Assessor notes</th><td>{{ $credit_assessment['assessor_notes'] ?? '—' }}</td></tr>
      </table>
      @if (!empty($credit_assessment['course_mappings']))
        <table>
          @foreach ($credit_assessment['course_mappings'] as $map)
            <tr>
              <th>{{ $map['previous_course'] ?? 'Course' }}</th>
              <td>{{ $map['equivalent_course'] ?? '—' }} · {{ $map['credits'] ?? '' }} · {{ $map['decision'] ?? '' }}</td>
            </tr>
          @endforeach
        </table>
      @endif
    @endif

    @if (!empty($pg_background['prior_degrees']))
      <h2>Prior degrees</h2>
      @foreach ($pg_background['prior_degrees'] as $degree)
        <table>
          <tr><th>Degree</th><td>{{ $degree['degree_title'] ?? '—' }}</td></tr>
          <tr><th>Institution</th><td>{{ $degree['institution'] ?? '—' }}</td></tr>
          <tr><th>Field of study</th><td>{{ $degree['field_of_study'] ?? '—' }}</td></tr>
          <tr><th>Classification</th><td>{{ $degree['class'] ?? '—' }}</td></tr>
          <tr><th>Year</th><td>{{ $degree['year_awarded'] ?? '—' }}</td></tr>
        </table>
      @endforeach
      <h2>NYSC</h2>
      <table>
        <tr><th>Status</th><td>{{ $pg_background['nysc_status'] ?? '—' }}</td></tr>
        <tr><th>Number</th><td>{{ $pg_background['nysc_number'] ?? '—' }}</td></tr>
        <tr><th>Year</th><td>{{ $pg_background['nysc_year'] ?? '—' }}</td></tr>
        <tr><th>Exemption reason</th><td>{{ $pg_background['nysc_exemption_reason'] ?? '—' }}</td></tr>
      </table>
    @endif

    @if (!empty($pg_research))
      <h2>Research</h2>
      <table>
        <tr><th>Research interest</th><td>{{ $pg_research['research_interest'] ?? '—' }}</td></tr>
        <tr><th>Proposed area</th><td>{{ $pg_research['proposed_area'] ?? '—' }}</td></tr>
        <tr><th>Statement of purpose</th><td>{{ $pg_research['statement_of_purpose'] ?? '—' }}</td></tr>
      </table>
    @endif

    @if (!empty($pg_referees['referees']))
      <h2>Referees</h2>
      @foreach ($pg_referees['referees'] as $referee)
        <table>
          <tr><th>Name</th><td>{{ $referee['name'] ?? '—' }}</td></tr>
          <tr><th>Email</th><td>{{ $referee['email'] ?? '—' }}</td></tr>
          <tr><th>Institution</th><td>{{ $referee['institution'] ?? '—' }}</td></tr>
          <tr><th>Position</th><td>{{ $referee['position'] ?? '—' }}</td></tr>
        </table>
      @endforeach
    @endif

    @if (($documents ?? collect())->isNotEmpty())
      <h2>Uploaded documents</h2>
      <table>
        @foreach ($documents as $document)
          <tr>
            <th>{{ str_replace('_', ' ', $document->doc_type ?? $document->doc_type ?? 'Document') }}</th>
            <td>{{ $document->original_name ?? $document->original_name ?? 'On file' }}</td>
          </tr>
        @endforeach
      </table>
    @endif

    <p class="footer">
      Generated electronically by {{ $institution['name'] }} on {{ $generated_at }}.
    </p>
  </div>
</body>
</html>
