<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | NOTHING IN THIS FILE IS APPROVED
    |--------------------------------------------------------------------------
    |
    | Every value below is a PLACEHOLDER pending review by Taytay's Data Protection Officer and
    | the LGU's legal counsel, under Republic Act No. 10173 and current National Privacy
    | Commission issuances.
    |
    | The master command is explicit: "Do not hardcode legal retention periods or legal bases
    | without Taytay DPO/legal approval." So they are here — in one reviewable file, expressed as
    | configuration — rather than scattered through the code as constants somebody would have to
    | go hunting for. Approving them is then a single small act by the people entitled to make it
    | (ADR 0034 §5).
    |
    | `approved` below is the switch. While it is false, the retention sweeper refuses to delete
    | anything and says why. That is deliberate: an unapproved retention schedule that quietly ran
    | would destroy records on a timetable nobody signed off, and deletion is the one operation
    | this system cannot undo.
    |
    */

    'retention' => [

        /*
         | Set to true ONLY when the DPO has approved the categories below, in writing, with the
         | approval recorded. Until then no scheduled deletion occurs anywhere in this system.
         */
        'approved' => (bool) env('PRIVACY_RETENTION_APPROVED', false),

        'approved_by' => env('PRIVACY_RETENTION_APPROVED_BY', ''),
        'approved_on' => env('PRIVACY_RETENTION_APPROVED_ON', ''),

        /*
         | How long each category of record is kept after the record it belongs to closes, in
         | days. The categories are the ones this system actually distinguishes; the numbers are
         | conventional starting points, not law.
         |
         | Where a shorter period is proposed than an obvious analogue, the reason is stated —
         | because a reviewer's useful question is "why this number", and an unexplained number
         | invites being raised rather than examined.
         */
        'categories' => [
            // Identity and account records. Kept while an account can still be recovered.
            'account' => (int) env('RETENTION_ACCOUNT_DAYS', 2555),

            // The canonical resident record. Long, because a resident's relationship with the
            // LGU is lifelong and re-registering somebody already known is its own harm.
            'resident' => (int) env('RETENTION_RESIDENT_DAYS', 3650),

            // Welfare casework, including the running record.
            'welfare_case' => (int) env('RETENTION_WELFARE_CASE_DAYS', 1825),

            // Money. Ordinarily governed by COA rules rather than by privacy law, and the longer
            // of the two applies.
            'release' => (int) env('RETENTION_RELEASE_DAYS', 3650),

            // Uploaded documents. Mirrors FileClassification::retentionDays(), which is the
            // schedule the file layer already reads.
            'document' => (int) env('RETENTION_DOCUMENT_DAYS', 1825),

            /*
             | SHORTEST DELIBERATELY. RA 9262 / RA 9344 material and health records.
             |
             | Holding safeguarding material longer than the case needs is itself the risk: it is
             | the category where retention and protection point in opposite directions, and the
             | protective answer is the shorter one.
             */
            'safeguarding' => (int) env('RETENTION_SAFEGUARDING_DAYS', 1095),

            // The audit trail. LONGER than most of what it describes, on purpose — a trail that
            // expired before the records it covers would be unable to answer the question it
            // exists for.
            'audit' => (int) env('RETENTION_AUDIT_DAYS', 3650),

            // Notification delivery receipts. Operational, and there is no reason to keep a
            // record of every text message for years.
            'notification' => (int) env('RETENTION_NOTIFICATION_DAYS', 365),

            // Person-level exports. Hours, not days — see ADR 0026 §3.
            'export' => (int) env('RETENTION_EXPORT_DAYS', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal bases
    |--------------------------------------------------------------------------
    |
    | Which basis under RA 10173 §12/§13 this LGU relies on for each processing purpose.
    |
    | **CONSENT IS THE MINORITY CASE AND NEVER THE IMPORTANT ONE.** Almost everything a municipal
    | social welfare office does is a legal obligation or a public-task function: it does not need
    | consent and, more to the point, it cannot honour a withdrawal of one. Recording statutory
    | processing as "consent" is not a labelling mistake — it is a promise the office cannot keep,
    | and the person it is broken to is a resident who asked for their data to stop being
    | processed (ADR 0034 §4).
    |
    | So the bases are declared here per purpose, and `consent_records` exists only for the few
    | purposes where consent genuinely is the basis.
    |
    */

    'legal_bases' => [
        // The statutory functions. No consent involved, and a withdrawal would be refused.
        'resident_registry' => 'public-task',
        'welfare_assistance' => 'legal-obligation',
        'kyc_verification' => 'legal-obligation',
        'credential_issuance' => 'public-task',
        'audit_and_accountability' => 'legal-obligation',

        // The genuinely optional ones. These are the purposes `consent_records` covers.
        'marketing_communications' => 'consent',
        'photography_for_publication' => 'consent',
        'referral_to_external_provider' => 'consent',
        'research_and_statistics' => 'consent',
    ],

    /*
    |--------------------------------------------------------------------------
    | What each category holds, and how it is classified
    |--------------------------------------------------------------------------
    |
    | The SAME categories as the retention schedule above, deliberately. The office holds one set
    | of record kinds; how long each is kept and how sensitive each is are two facts about the
    | same thing, and splitting them into two lists is how they come to disagree about which
    | categories exist.
    |
    | The vocabulary is RA 10173's, because that is the law that applies:
    |
    |   public              not personal information (§3(g)) — published by the municipality
    |   internal            not personal information; office working material about nobody
    |   personal            personal information (§3(g)) — identifies a living person
    |   sensitive-personal  sensitive personal information (§3(l)) — health, offences, and the
    |                       protection sectors; RA 9262 (VAWC) and RA 9344 (CICL) fall here
    |
    | These are a reading of the statute applied to categories this system actually distinguishes.
    | They are reference data about nobody, and they are still the DPO's to confirm — the same
    | approval that covers the periods above should cover these.
    |
    */
    'classifications' => [
        'account' => [
            'classification' => 'personal',
            'holds' => 'Staff and citizen accounts: name, email, mobile number, and the roles held.',
        ],
        'resident' => [
            'classification' => 'personal',
            'holds' => 'The canonical resident record: name, birth date, address, civil status and sectors.',
        ],
        'welfare_case' => [
            'classification' => 'personal',
            'holds' => 'Assistance casework: what was asked for, what was assessed and what was decided.',
        ],
        'release' => [
            'classification' => 'personal',
            'holds' => 'Payouts: who received what, when, and who acknowledged it.',
        ],
        'document' => [
            'classification' => 'personal',
            'holds' => 'Uploaded requirements. Individual files may be classified higher than the category.',
        ],
        'safeguarding' => [
            /*
             | THE ONE THAT MATTERS MOST, and the reason this list is published at all. RA 9262 and
             | RA 9344 material sits here, and it is also the category with the SHORTEST retention
             | above — the two point in opposite directions and the protective answer wins.
             */
            'classification' => 'sensitive-personal',
            'holds' => 'Protection concerns and safety planning under RA 9262 and RA 9344, and health information.',
        ],
        'audit' => [
            'classification' => 'personal',
            'holds' => 'Who did what, when. Names an actor, and names the record acted on — never the values changed.',
        ],
        'notification' => [
            'classification' => 'personal',
            'holds' => 'Messages sent to a person through the app, and whether they were read.',
        ],
        'export' => [
            'classification' => 'personal',
            'holds' => 'Generated report files. A person-level export inherits the classification of what it names.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Consent purposes
    |--------------------------------------------------------------------------
    |
    | The closed vocabulary a consent record may name. Derived from the bases above rather than
    | listed twice, so a purpose cannot be marked `consent` in one place and something else in
    | the other — the disagreement that would make "what has this person agreed to" unanswerable.
    |
    */

    'consent_purposes' => null, // resolved from legal_bases by ConsentRegistry

    /*
    |--------------------------------------------------------------------------
    | Privacy notice
    |--------------------------------------------------------------------------
    |
    | The version currently in force. An acknowledgement always points at a specific version,
    | because "she accepted the privacy notice" means nothing without knowing which one.
    |
    */

    'current_notice_version' => env('PRIVACY_NOTICE_VERSION', ''),

];
