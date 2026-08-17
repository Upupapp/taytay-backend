<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Network identifiers on audit entries
    |--------------------------------------------------------------------------
    |
    | Whether high-risk audit entries record the caller's IP address and user agent.
    |
    | OFF BY DEFAULT, AND THAT IS A PRIVACY DECISION RATHER THAN A TECHNICAL ONE. An IP
    | address is personal data under RA 10173. Capturing one on every routine read builds a
    | movement log of the office's own staff — thousands of rows a day recording where a clerk
    | was sitting — which is disproportionate to any use it would ever be put to. On a sensitive
    | document download it is proportionate evidence.
    |
    | So capture is limited to `high` risk entries even when this is on, and whether it is on at
    | all belongs to Taytay's DPO. The master command is explicit: "IP/user-agent WHERE POLICY
    | PERMITS" (ADR 0034 §3).
    |
    */

    'capture_network' => (bool) env('AUDIT_CAPTURE_NETWORK', false),

    /*
    |--------------------------------------------------------------------------
    | Who may read the trail
    |--------------------------------------------------------------------------
    |
    | Reading the audit trail is itself audited (`audit.searched`). An audit trail nobody can
    | read is theatre; one anybody can read is a second copy of who-did-what-to-whom with none
    | of the access control of the records it describes.
    |
    */

    'max_page_size' => 100,

];
