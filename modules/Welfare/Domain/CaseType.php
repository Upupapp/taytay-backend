<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * What kind of casework this is.
 *
 * A closed set because each type carries different handling rules, and an open string would
 * let a typo create a category that quietly reports as its own bucket.
 *
 * `Protective` is the one that matters most here: a protection case concerns a VAWC survivor,
 * a child at risk or a trafficking survivor, and its very existence is sensitive. It is gated
 * by `request.view-sensitive` everywhere — list, detail and count — for the same reason
 * safeguarding factors are gated in ADR 0015 §4: knowing a protection case exists for a named
 * person is most of the disclosure.
 */
enum CaseType: string
{
    /** Direct financial or in-kind assistance — AICS and the like. */
    case Assistance = 'assistance';

    /** Medical, burial or hospitalisation support. */
    case Medical = 'medical';

    /** Educational support. */
    case Educational = 'educational';

    /** Emergency or disaster response. */
    case Relief = 'relief';

    /** Livelihood or employment support. */
    case Livelihood = 'livelihood';

    /**
     * Protective services — VAWC, child protection, trafficking.
     *
     * Restricted throughout. See the class docblock.
     */
    case Protective = 'protective';

    /** Referral to another office or agency, tracked to outcome. */
    case Referral = 'referral';

    /**
     * Whether the existence of this case is itself sensitive information.
     */
    public function isRestricted(): bool
    {
        return $this === self::Protective;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
