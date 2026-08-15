<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Modules\ResidentProfile\Contracts\ResidentSummary;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;

/**
 * The published way for other modules to ask about a resident.
 *
 * Returns a {@see ResidentSummary}, never the Eloquent model: a module that receives the
 * model receives the whole record, and the boundary in ADR 0001 becomes advisory. This is
 * the only entry point other modules may use (CLAUDE.md Article 2.1).
 */
final class ResidentDirectory
{
    public function summaryFor(string $residentUuid): ?ResidentSummary
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->first();

        if ($resident === null) {
            return null;
        }

        return new ResidentSummary(
            id: $resident->uuid,
            displayName: $resident->displayName(),
            verificationTier: $resident->verification_tier,
        );
    }
}
