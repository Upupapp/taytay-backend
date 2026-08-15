<?php

declare(strict_types=1);

namespace Modules\Credential\Application;

use Modules\Credential\Infrastructure\Eloquent\Credential;

/**
 * The published way for other modules to ask Credential to act on a resident's cards.
 *
 * Exists for exactly one reason: a resident merge has to repoint the absorbed person's
 * credentials at the survivor, and ResidentProfile may not reach into this module's tables
 * to do it (CLAUDE.md Article 2.1/2.2). Without this seam the merge would either leave the
 * credentials stranded on a soft-deleted resident — an ID that verifies against a record
 * nobody can open — or would be written as a cross-module UPDATE, which is the boundary
 * violation that makes every later refactor unsafe.
 *
 * Kept deliberately narrow. This is not a general credential API for other modules.
 */
final class CredentialDirectory
{
    /**
     * Repoints every credential from one resident to another.
     *
     * Returns how many moved, because the caller records that count as evidence the merge
     * actually carried the cards across (`resident_merges.reassigned_credentials`).
     *
     * Status is untouched on purpose: a revoked card stays revoked, and an active card
     * stays active. A merge is a statement about *who the holder is*, not about whether
     * their ID is still good — silently reactivating a revoked credential because two rows
     * turned out to be one person would hand back an ID somebody had deliberately taken
     * away.
     */
    public function reassignResident(string $fromResidentUuid, string $toResidentUuid): int
    {
        if ($fromResidentUuid === $toResidentUuid) {
            return 0;
        }

        return Credential::query()
            ->where('resident_id', $fromResidentUuid)
            ->update(['resident_id' => $toResidentUuid]);
    }
}
