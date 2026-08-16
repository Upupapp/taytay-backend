<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * Why this office may share a person's information with another organisation at all.
 *
 * Required before a referral can be sent, and absent on a draft. This is RA 10173's lawful-basis
 * requirement expressed as a column rather than as a paragraph in a manual.
 *
 * Consent is the ordinary case. The other two exist because insisting on written consent from
 * somebody unconscious in an emergency room, or from a child at risk of harm, would be its own
 * kind of failure — and a system that offers only consent teaches staff to record consent that
 * was never given.
 */
enum DisclosureBasis: string
{
    /** The client was told which office would receive their information, and what for, and agreed. */
    case ClientConsent = 'client-consent';

    /** A statute or issuance requires this office to report or refer. */
    case StatutoryMandate = 'statutory-mandate';

    /** Consent could not be obtained and delay would risk serious harm. */
    case VitalInterest = 'vital-interest';

    /**
     * What the note must establish, shown to the worker recording it.
     *
     * Each basis needs a *different* fact written down, and a single "reason" prompt gets the
     * same sentence for all three. A vital-interest referral whose note says "client agreed" is a
     * record that contradicts its own basis.
     */
    public function notePrompt(): string
    {
        return match ($this) {
            self::ClientConsent => 'What the client was told, and that they agreed.',
            self::StatutoryMandate => 'Which statute or issuance requires this.',
            self::VitalInterest => 'What the risk was, and why consent could not be obtained.',
        };
    }

    /**
     * Printed on the sheet so the receiving office knows the basis it holds the information on.
     *
     * It changes what they may lawfully do with it — information held on a vital-interest basis
     * is not information the client agreed to have passed on again.
     */
    public function statement(): string
    {
        return match ($this) {
            self::ClientConsent => 'Shared with the consent of the client.',
            self::StatutoryMandate => 'Shared under a statutory duty to refer.',
            self::VitalInterest => 'Shared to protect life, health or safety, without prior consent.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $basis): string => $basis->value, self::cases());
    }
}
