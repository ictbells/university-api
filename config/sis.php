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
    | Last issued postgraduate matric in the same YYYY/###### format.
    | Example: PG_MATRIC_LAST=2026/000020 → next PG student is 2026/000021.
    */
    'pg_matric_last' => env('PG_MATRIC_LAST', ''),

    /*
    | Optional PG admission year. Empty falls back to MATRIC_YEAR, then session year.
    */
    'pg_matric_year' => env('PG_MATRIC_YEAR', ''),

    'matric_digits' => (int) env('MATRIC_DIGITS', 6),

    /*
    | Shared serial for invoice numbers and payment receipt numbers.
    | Format: BUT/{admission year}/{####} e.g. BUT/2026/0001
    */
    'bursary_doc_prefix' => env('BURSARY_DOC_PREFIX', 'BUT'),
    'bursary_doc_last' => env('BURSARY_DOC_LAST', ''),
    'bursary_doc_year' => env('BURSARY_DOC_YEAR', ''),
    'bursary_doc_digits' => (int) env('BURSARY_DOC_DIGITS', 4),

];
