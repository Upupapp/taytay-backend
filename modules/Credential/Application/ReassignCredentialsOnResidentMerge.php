<?php

declare(strict_types=1);

namespace Modules\Credential\Application;

use Modules\ResidentProfile\Contracts\ResidentMerged;

/**
 * Moves a merged resident's credentials onto the surviving record.
 *
 * This listener is the *inversion* that keeps the dependency graph acyclic: Credential
 * already depends on ResidentProfile, so ResidentProfile must not depend back on Credential
 * (boundary map §2). It announces the merge; this module decides what that means for cards.
 *
 * Returns the number moved. The dispatcher collects listener return values, which is how the
 * merge record can report `reassigned_credentials` without ResidentProfile ever knowing that
 * credentials exist.
 *
 * Runs synchronously inside the merge transaction — see {@see ResidentMerged} for why a
 * queued handler would be wrong here.
 */
final class ReassignCredentialsOnResidentMerge
{
    public function __construct(private readonly CredentialDirectory $credentials) {}

    public function handle(ResidentMerged $event): int
    {
        return $this->credentials->reassignResident(
            $event->absorbedResidentUuid,
            $event->survivorResidentUuid,
        );
    }
}
