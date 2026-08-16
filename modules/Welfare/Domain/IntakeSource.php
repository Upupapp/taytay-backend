<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * How a request reached the office (ADR 0017 §1).
 *
 * Recorded because the channel changes what the office knows about the claim, not because it
 * changes what the office does with it. A walk-in was taken by a clerk who saw the person; a
 * self-service submission was typed by the applicant at home; a legacy import was never
 * verified by anyone here at all. Those are different evidential positions, and after the fact
 * nobody can reconstruct which applied unless it was written down at the time.
 *
 * WHAT THE SOURCE MUST NEVER DO is change the rules. The same lifecycle, the same permissions
 * and the same assessment apply whatever channel a request arrived on — that is CLAUDE.md
 * Article 3.1, and it is why this enum carries no behaviour beyond a single provenance
 * question. A `isSelfService()` that gated a *decision* would be a per-client business rule
 * wearing a domain enum's clothes.
 */
enum IntakeSource: string
{
    /** A clerk took this at the counter, with the applicant present. */
    case WalkIn = 'walk-in';

    /** A barangay official referred it. */
    case BarangayReferral = 'barangay-referral';

    case CitizenWeb = 'citizen-web';

    case CitizenMobile = 'citizen-mobile';

    /**
     * Migrated from a previous system or a paper backlog.
     *
     * Never verified by this system's own intake, so its provenance is materially weaker than
     * anything else here — which is exactly why it is worth a distinct value rather than being
     * flattened into `walk-in`.
     */
    case LegacyImport = 'legacy-import';

    /**
     * Whether the applicant entered this themselves, unattended.
     *
     * Used only to decide which *consent record* the intake must carry: an unattended
     * submission needs the applicant's own acknowledgement of the privacy notice, where a
     * counter intake is covered by the clerk's process. It gates no welfare decision.
     */
    public function isSelfService(): bool
    {
        return match ($this) {
            self::CitizenWeb, self::CitizenMobile => true,
            default => false,
        };
    }

    /**
     * Whether a citizen client may claim this source.
     *
     * A client asserting `walk-in` or `legacy-import` would be manufacturing provenance it
     * cannot have — so the citizen endpoint does not accept the field at all and the server
     * derives it from the channel.
     */
    public function isCitizenAssertable(): bool
    {
        return $this->isSelfService();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
