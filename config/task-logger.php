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

    // D-42: longest span (in calendar days) one leave request may cover; a guard against typos such as a wrong year.
    'leave_max_request_days' => 366,

    // D-48: choices offered in the HR form. The columns stay free text (D-19): an old or imported value outside
    // these lists is still shown and kept. Change the lists here.
    'source_of_hire_options' => [
        'Referral', 'LinkedIn', 'Company website', 'Job board', 'Recruitment agency', 'University or campus',
        'Social media', 'Walk-in', 'Internal transfer', 'Other',
    ],
    'emergency_contact_relationships' => [
        'Spouse', 'Partner', 'Mother', 'Father', 'Brother', 'Sister', 'Son', 'Daughter', 'Grandparent', 'Friend', 'Other',
    ],

    // D-52: expense receipts live on a private disk; one image or PDF per expense.
    'expenses' => [
        'disk' => 'local',
        'receipt_max_kb' => 5120,
    ],

    // BR-04: maximum minutes one user may log per calendar date.
    'max_log_minutes_per_day' => 24 * 60,

    // D-01: technology level scale.
    'technology_level_min' => 1,
    'technology_level_max' => 10,

    // ADR-0005 / D-26: two-factor authentication (TOTP). These are the seed defaults; the superadmin
    // changes them live on the Two-factor settings page (security settings group).
    'two_factor' => [
        'enabled' => true,          // global switch: users may enrol in 2FA
        'required' => false,        // when true, every user must enrol after the grace period
        'grace_days' => 14,         // days users have to enrol once 2FA becomes required
    ],

    // BR-33: exports run on the queue and are written to the private disk.
    'exports' => [
        'disk' => 'local',
        'keep_days' => 7,       // files are deleted this many days after completion
    ],

    // D-38: the session key that holds the impersonator's user id while someone acts as another user.
    // Matches the default of lab404/laravel-impersonate ('impersonated_by'); keep them in sync.
    'impersonation' => [
        'session_key' => 'impersonated_by',
    ],

    // Optional first superadmin created by SuperadminSeeder (local/bootstrap use).
    'superadmin' => [
        'email' => env('SUPERADMIN_EMAIL'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],
];
