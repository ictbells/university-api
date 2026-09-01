<?php

return [

    /*
    | Last issued matric, e.g. 2026/000150. The next student receives the next
    | number (2026/000151). Set this to continue from an existing series.
    */
    'matric_last' => env('MATRIC_LAST', ''),

    /*
    | Admission year before the slash. Empty uses the session start year, then
    | the current calendar year.
    */
    'matric_year' => env('MATRIC_YEAR', ''),

    'matric_digits' => (int) env('MATRIC_DIGITS', 6),

];
