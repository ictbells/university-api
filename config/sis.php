<?php

return [

    /*
    | Last issued undergraduate (and DE/transfer) matric, e.g. 2026/000150.
    | The next student receives the next number (2026/000151).
    */
    'matric_last' => env('MATRIC_LAST', ''),

    /*
    | Admission year before the slash for undergraduate. Empty uses the session
    | start year, then the current calendar year.
    */
    'matric_year' => env('MATRIC_YEAR', ''),

    /*
    | Last issued postgraduate matric in the same YYYY/{serial} format.
    | Example with PG_MATRIC_DIGITS=4: PG_MATRIC_LAST=2026/0001 → next is 2026/0002.
    */
    'pg_matric_last' => env('PG_MATRIC_LAST', ''),

    /*
    | Optional PG admission year. Empty falls back to MATRIC_YEAR, then session year.
    */
    'pg_matric_year' => env('PG_MATRIC_YEAR', ''),

    /*
    | Zero-pad width for the serial after the slash (undergraduate / DE / transfer).
    */
    'matric_digits' => (int) env('MATRIC_DIGITS', 6),

    /*
    | Zero-pad width for postgraduate serials. Empty / 0 falls back to MATRIC_DIGITS.
    | Example: PG_MATRIC_DIGITS=4 → 2026/0001
    */
    'pg_matric_digits' => (int) env('PG_MATRIC_DIGITS', 0),

    /*
    | Shared serial for invoice numbers and payment receipt numbers.
    | Format: BUT/{admission year}/{####} e.g. BUT/2026/0001
    */
    'bursary_doc_prefix' => env('BURSARY_DOC_PREFIX', 'BUT'),
    'bursary_doc_last' => env('BURSARY_DOC_LAST', ''),
    'bursary_doc_year' => env('BURSARY_DOC_YEAR', ''),
    'bursary_doc_digits' => (int) env('BURSARY_DOC_DIGITS', 4),

    /*
    | Student financial-status CSV stream chunk size (students per hydrate/write batch).
    | Smaller values improve time-to-first-row; larger values reduce query round-trips.
    */
    'finance_export_csv_chunk' => max(50, (int) env('FINANCE_EXPORT_CSV_CHUNK', 250)),

    /*
    | When true, log stage timings for student-roster exports (counts/ms only — no PII).
    */
    'finance_export_profile' => (bool) env('FINANCE_EXPORT_PROFILE', false),

];
