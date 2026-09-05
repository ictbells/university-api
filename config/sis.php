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

];
