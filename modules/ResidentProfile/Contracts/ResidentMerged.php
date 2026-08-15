<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * Two canonical resident records were resolved into one (ADR 0013 §6).
 *
 * WHY AN EVENT RATHER THAN A CALL. A merge has to repoint everything that referenced the
 * absorbed resident, including records owned by modules that sit *below* ResidentProfile in
 * the dependency graph — `Credential` in particular. Calling into Credential from here would
 * create the cycle `ResidentProfile → Credential → ResidentProfile`, which the boundary map
 * forbids: "downward calls must be inverted with a domain event, not a direct call."
 *
 * So ResidentProfile announces what happened and knows nothing about who cares. Each owning
 * module reassigns its own rows, inside the merge transaction, and returns how many it moved
 * so the merge record can report it.
 *
 * Listeners run **synchronously and inside the transaction** on purpose. Queued handling
 * would let the merge commit while a credential still pointed at a soft-deleted resident —
 * an ID that verifies against a record nobody can open — and the window would be invisible.
 *
 * Published under `Contracts/` because it is vocabulary other modules must be able to name.
 */
final readonly class ResidentMerged
{
    public function __construct(
        /** The record that survived and now owns everything. */
        public string $survivorResidentUuid,
        /** The record that was absorbed and soft-deleted. */
        public string $absorbedResidentUuid,
        /** Identity account UUID of the reviewer who executed the merge, if any. */
        public ?string $actorSubjectId = null,
    ) {}
}
