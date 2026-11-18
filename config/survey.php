<?php

/*
|--------------------------------------------------------------------------
| Public patient satisfaction survey
|--------------------------------------------------------------------------
|
| The only form the public can open and submit without logging in
| (/survei-kepuasan-pasien). The indicator is the one the Jotform form was
| imported into (jotform_forms), and it must have a published form.
| Every answer is recorded for one unit, by code.
|
*/

return [
    'jotform_form_id' => env('PUBLIC_SURVEY_JOTFORM_FORM_ID', '213401282467047'),

    'unit_code' => env('PUBLIC_SURVEY_UNIT_CODE', 'HUMAS'),

    // Submissions per client IP.
    'per_minute' => (int) env('PUBLIC_SURVEY_PER_MINUTE', 3),
    'per_hour' => (int) env('PUBLIC_SURVEY_PER_HOUR', 20),
];
