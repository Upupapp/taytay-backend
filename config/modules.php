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
        /*
         * The append-only trail and the privacy governance around it. Loaded immediately after
         * Shared because every module above writes to it, and because it depends on nothing else:
         * a trail that needed ResidentProfile in order to record a merge would close a cycle with
         * the module whose merges it exists to record (ADR 0034 §1).
         */
        'Audit',
        /*
         * Stored objects and the documents presented against them. Loaded immediately after
         * Shared because every module above may store a document, and because it publishes no
         * routes of its own — the module that owns a record authorises access to that record's
         * files (ADR 0020 §5).
         */
        'Files',
        'AccessControl',
        'Identity',
        'ResidentProfile',
        'Credential',
        'ServiceCatalog',
        // Social welfare casework. Loaded after ResidentProfile because a case references a
        // resident through that module's published services.
        'Welfare',
        /*
         * Work queues and the automation that fills them. Loaded LAST because it listens to
         * every module above and calls back into none of them — Welfare announces that a referral
         * went overdue and does not know Tasks exists (ADR 0024 §3).
         */
        'Tasks',
        // Outbound dispatch. Owns HOW somebody is told, never WHY — Welfare decides that a case
        // was approved, and a provider outage here cannot reach welfare work (ADR 0025 §1).
        'Notification',
        /*
         * Aggregates and exports. Owns no facts: every number is counted from another module's
         * tables, and a read model that could write would be a second authority (ADR 0026 §1).
         */
        'Reporting',
        /*
         * Record discovery and saved filter presets. Owns no records and no index: every searcher
         * runs a scoped query against the owning module's table, applying the same permission and
         * scope that module's detail endpoint applies (ADR 0027 §1).
         */
        'Search',
        /*
         * Admin-authored public communication. The only module whose records are meant to be read
         * by people who are not their subject, which inverts the usual risk (ADR 0028).
         */
        'Content',
        /*
         * Official LGU events. Separate from Content even though both publish to the public: a
         * post is a statement, an event is an operational commitment with a venue, a capacity and
         * a list of people expecting to be let in (ADR 0030 §1).
         */
        'Events',
    ],

];
