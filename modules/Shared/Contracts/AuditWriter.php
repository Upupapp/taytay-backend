<?php

declare(strict_types=1);

namespace Modules\Shared\Contracts;

/**
 * Recording that something happened (ADR 0034 §1).
 *
 * **AN INTERFACE IN SHARED, IMPLEMENTED IN `Audit`, AND THE INVERSION IS NOT DECORATIVE.**
 *
 * Every module writes to the audit trail — that is what makes auditing worth having. But `Audit`
 * also has an HTTP surface of its own, and every protected endpoint must ask `AccessControl`
 * whether the caller may read the trail. Those two facts together form a cycle:
 * `AccessControl → Audit → AccessControl`. `ModuleBoundaryTest` caught it the moment the ten
 * module writers were consolidated, which is exactly what that test is for.
 *
 * The resolution is the one the boundary map already prescribes for a downward dependency: invert
 * it. Modules depend on this interface, which lives in the module everyone may depend on and which
 * depends on nothing. `Audit` provides the implementation and is then free to depend on
 * `AccessControl` like any other module with a surface to protect.
 *
 * Shared holds the **interface only** — no table, no query, no business rule. That keeps its
 * charter intact (Article 2.3): a published vocabulary, not an implementation.
 *
 * THERE IS NO NULL IMPLEMENTATION AND NO FALLBACK. If `Audit` is not loaded this binding fails and
 * the application does not boot. That is correct: a system holding Philippine personal data with
 * auditing silently switched off is worse than one that refuses to start, and Article 5.4 makes
 * the trail non-optional.
 */
interface AuditWriter
{
    /**
     * Records one audited act.
     *
     * @param  string  $entityType  the record acted upon, in `Module.Thing` form
     * @param  list<string>|array<string, mixed>  $changedFields  COLUMN NAMES, never values. An
     *                                                            associative array is read for its
     *                                                            keys, because a `$changes` array
     *                                                            is already keyed by field name
     *                                                            and passing one whole is the easy
     *                                                            mistake this guards against.
     * @param  string|null  $reason  a reason the actor typed FOR THIS PURPOSE — never lifted from
     *                               a case note or a rejection justification, which are written
     *                               for a colleague and belong to the record rather than the trail
     */
    public function record(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        string $entityType,
        ?string $entityId = null,
        array $changedFields = [],
        ?string $reason = null,
        ?string $accountType = null,
    ): void;
}
