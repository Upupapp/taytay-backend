<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Assessment templates (VERSIONED, PROVISIONAL STORE)
    |--------------------------------------------------------------------------
    |
    | Read through Modules\Welfare\Domain\AssessmentTemplates (ADR 0017 §4).
    |
    | Config rather than a table, for the third time in this codebase and for the same reason
    | (ADR 0015 §2): a file in git is versioned in the strongest available sense — every change
    | to a question has an author, a date, a diff and a review. It becomes an LGU-editable
    | table when form authoring is designed; the Domain class is the seam, so no caller changes.
    |
    | EVERY ASSESSMENT PINS ITS TEMPLATE VERSION. Without that, editing a question silently
    | changes what past assessments appear to have asked, and their answers stop meaning what
    | they meant. Bump `version` whenever any question below changes — including its wording.
    |
    | THESE FORMS ARE PLACEHOLDERS AWAITING MSWDO VALIDATION. They are a plausible AICS-style
    | intake assessment, not Taytay's instrument. The master command forbids hardcoding policy
    | that has not been supplied; gap G-21 tracks it.
    |
    | NOTHING HERE SCORES ANYTHING. These questions collect what an assessor observed. No
    | weight, no threshold, no total — a form that computed an eligibility number would be the
    | automatic decision the master command forbids, wearing a questionnaire's clothes.
    |
    */

    'templates' => [

        'aics-general' => [
            'code' => 'aics-general',
            'version' => '2026.08.1',
            'label' => 'AICS general intake assessment',
            'status' => 'placeholder-pending-lgu-approval',
            'questions' => [
                ['code' => 'household_income_bracket', 'label' => 'Reported monthly household income bracket', 'type' => 'choice', 'required' => true, 'choices' => ['none', 'below-5000', '5000-10000', '10000-20000', 'above-20000']],
                ['code' => 'income_earners', 'label' => 'Number of income earners in the household', 'type' => 'integer', 'required' => true],
                ['code' => 'dwelling_observed', 'label' => 'Dwelling condition observed', 'type' => 'choice', 'required' => false, 'choices' => ['adequate', 'needs-repair', 'unsafe', 'not-observed']],
                ['code' => 'presenting_problem', 'label' => 'Presenting problem in the assessor\'s words', 'type' => 'text', 'required' => true],
                ['code' => 'other_assistance_received', 'label' => 'Other assistance received in the last 12 months', 'type' => 'text', 'required' => false],
                ['code' => 'immediate_risk', 'label' => 'Is there immediate risk to safety, health or shelter?', 'type' => 'choice', 'required' => true, 'choices' => ['none', 'possible', 'present']],
            ],
        ],

        'medical-assistance' => [
            'code' => 'medical-assistance',
            'version' => '2026.08.1',
            'label' => 'Medical assistance assessment',
            'status' => 'placeholder-pending-lgu-approval',
            'questions' => [
                ['code' => 'facility', 'label' => 'Attending facility', 'type' => 'text', 'required' => true],
                ['code' => 'billing_status', 'label' => 'Billing status', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'partially-settled', 'settled', 'unknown']],
                ['code' => 'philhealth_applied', 'label' => 'PhilHealth benefit already applied?', 'type' => 'choice', 'required' => true, 'choices' => ['yes', 'no', 'unknown']],
                ['code' => 'presenting_problem', 'label' => 'Presenting problem in the assessor\'s words', 'type' => 'text', 'required' => true],
                /*
                 | Deliberately absent: diagnosis, and any field inviting one.
                 |
                 | A diagnosis is health information — the most restricted category under
                 | RA 10173 — and an assistance assessment does not need it. What the office
                 | needs to decide is whether there is a bill the household cannot meet, which
                 | `billing_status` and `philhealth_applied` answer without recording anybody's
                 | medical condition in a welfare file that ordinary staff can list.
                 */
            ],
        ],
    ],
];
