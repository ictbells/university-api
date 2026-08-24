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
    .photo {
      float: right;
      width: 96px;
      height: 112px;
      object-fit: cover;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      margin: 0 0 12px 16px;
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

    @if ($photoUri)
      <img class="photo" src="{{ $photoUri }}" alt="Passport photograph">
    @endif

    <div class="meta">
      <div><span class="label">Application number:</span> <span class="value">{{ $application->application_number ?: '—' }}</span></div>
      <div><span class="label">JAMB registration:</span> <span class="value">{{ $application->jamb_registration ?: $application->user?->jamb_registration ?: '—' }}</span></div>
      <div><span class="label">Entry mode:</span> <span class="value">{{ strtoupper((string) $application->entry_mode) }}</span></div>
      <div><span class="label">Session:</span> <span class="value">{{ $application->intake?->term?->session_label ?: '—' }}</span></div>
      <div><span class="label">1st choice:</span> <span class="value">{{ $first_choice ?: ($programme ?: '—') }}</span></div>
      <div><span class="label">2nd choice:</span> <span class="value">{{ $second_choice ?: '—' }}</span></div>
      <div><span class="label">College:</span> <span class="value">{{ $college ?: '—' }}</span></div>
      <div><span class="label">Department:</span> <span class="value">{{ $department ?: '—' }}</span></div>
      <div><span class="label">Programme:</span> <span class="value">{{ $programme ?: '—' }}</span></div>
      @if (!empty($second_choice))
        <div><span class="label">2nd college:</span> <span class="value">{{ $second_choice_college ?: '—' }}</span></div>
        <div><span class="label">2nd department:</span> <span class="value">{{ $second_choice_department ?: '—' }}</span></div>
      @endif
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
        <tr><th>Exam type</th><td>{{ $sitting['data']['exam_type'] ?? $sitting['data']['exam_type'] ?? '—' }}</td></tr>
        <tr><th>Exam centre</th><td>{{ $sitting['data']['exam_center'] ?? $sitting['data']['exam_center'] ?? '—' }}</td></tr>
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
