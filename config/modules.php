<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled modules
    |--------------------------------------------------------------------------
    |
    | The authoritative registry of the modular monolith (ADR 0001). A module that is
    | not listed here is not loaded: its service provider is not registered and its
    | Routes/api_v1.php is not mounted.
    |
    | Order matters — modules are booted and their routes mounted in this order, so
    | `Shared` (which every other module may depend on) comes first.
    |
    | Ownership and dependency rules: docs/architecture/domain-boundary-map.md
    |
    */

    'enabled' => [
        'Shared',
        'AccessControl',
        'Identity',
        'ResidentProfile',
        'Credential',
        'ServiceCatalog',
        // Social welfare casework. Loaded after ResidentProfile because a case references a
        // resident through that module's published services.
        'Welfare',
    ],

];
