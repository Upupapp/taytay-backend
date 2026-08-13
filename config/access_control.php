<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Role assignments (PROVISIONAL)
    |--------------------------------------------------------------------------
    |
    | Maps an authenticated subject identifier to the roles it holds. Roles outside the
    | Modules\AccessControl\Domain\Role catalog are ignored (deny by default).
    |
    | This is deliberately deployment-time configuration for TAB 01: there is no runtime
    | privilege-escalation path through it. It is replaced by a persisted, audited
    | AccessControl table once the Identity module owns accounts — see
    | docs/architecture/domain-boundary-map.md and the note on
    | Modules\AccessControl\Infrastructure\ConfigRoleAssignmentRepository.
    |
    | An account with no entry here holds no permissions, which is the correct default
    | for every citizen account.
    |
    */

    'assignments' => [
        // '<subject-id>' => ['lgu_staff'],
    ],

];
