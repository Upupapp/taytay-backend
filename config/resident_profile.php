<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | KYC retention
    |--------------------------------------------------------------------------
    |
    | Identity documents exist to answer one question — is this person who they say they
    | are — and must not be kept indefinitely once it is answered (RA 10173 storage
    | limitation). The clock starts at submission; a purge clears the stored objects while
    | keeping the case row as evidence that a document was supplied and reviewed.
    |
    | 180 days is a working default, not a legal finding: it is long enough to cover a
    | dispute or an audit of the decision, and short enough that a breach years later does
    | not expose scans of everyone who ever registered. The LGU's records officer should
    | confirm it against their retention schedule.
    |
    */

    'kyc' => [
        'retention_days' => (int) env('KYC_RETENTION_DAYS', 180),
        'max_documents' => 6,
        'max_document_bytes' => 8 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Biometrics
    |--------------------------------------------------------------------------
    |
    | OFF, and there is no schema for it.
    |
    | Biometric data is irrevocable: a leaked password is changed, a leaked face is not.
    | Under RA 10173 it is sensitive personal information, and the LGU has no operational
    | need for it that document review does not already meet — a clerk comparing a face to
    | an ID achieves the same outcome without the LGU holding a template it must then
    | protect forever.
    |
    | If this is ever enabled, it must store a verification RESULT and never a template,
    | and it needs its own ADR and a privacy impact assessment first.
    |
    */

    'biometrics' => [
        'enabled' => (bool) env('KYC_BIOMETRICS_ENABLED', false),
        'store_templates' => false,
    ],

];
