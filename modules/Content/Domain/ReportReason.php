<?php

declare(strict_types=1);

namespace Modules\Content\Domain;

/**
 * Why a resident says a comment should not be there (F26).
 *
 * ---
 *
 * **A CONTROLLED VOCABULARY, AND THERE IS NO `other`.**
 *
 * `other` is the door free text comes back through: a category that means nothing forces a text
 * box to explain it, and that box is where somebody writes *"this is my neighbour Juan at 12
 * Mabini and he is lying"* — personal data about a third party, entered by a person under no
 * obligation to be accurate, into a municipal record that staff read and retention rules keep.
 *
 * The moderator has the comment in front of them. The category tells them what to look for, which
 * is the whole job it has. A report that does not fit any of these is a report about something
 * other than a comment, and belongs at a counter or on the LGU's complaints line rather than in a
 * moderation queue.
 *
 * These five are the intersection of what both app stores expect a reporting flow to cover and
 * what a municipal newsfeed can actually produce.
 */
enum ReportReason: string
{
    /** Abusive, threatening or hateful. */
    case Abusive = 'abusive';

    /**
     * Aimed at a particular person.
     *
     * Separate from [Abusive] because they need different handling: abuse is removed, harassment
     * of a named resident may also need somebody to check on that resident.
     */
    case Harassment = 'harassment';

    /**
     * False claims about municipal services.
     *
     * The one that matters most on this feed specifically. A comment under an advisory saying
     * relief distribution moved to a different hall sends people to the wrong place.
     */
    case FalseInformation = 'false-information';

    case Spam = 'spam';

    /**
     * Somebody's personal information, posted without their say.
     *
     * A phone number, an address, a case reference. The LGU is the controller of the surface it
     * appeared on, so this is a data-protection incident and not only a moderation one.
     */
    case PersonalInformation = 'personal-information';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
