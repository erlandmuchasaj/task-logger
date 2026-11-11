<?php

declare(strict_types=1);

// Isolated defaults for [ASSUMED D-xx] items (docs/SPEC.md §3). Change here, not in code.
return [
    // D-15: months after the offboarding end date before personal data is anonymised.
    // The live value is a spatie/laravel-settings setting; this is its seed default.
    'anonymize_after_months' => 2,

    // D-08: leave carry-over between years is a per-country policy option, off by default.
    'leave_carry_over_default' => false,

    // D-08/BR-18: prorated leave rounds to the nearest half day.
    'leave_rounding_step' => 0.5,

    // D-08: default working week (ISO weekdays, 1 = Monday), configurable per country.
    'default_working_days' => [1, 2, 3, 4, 5],

    // BR-04: maximum minutes one user may log per calendar date.
    'max_log_minutes_per_day' => 24 * 60,

    // D-01: technology level scale.
    'technology_level_min' => 1,
    'technology_level_max' => 10,
];
