<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * What an assessor recommends (ADR 0017 §4).
 *
 * A RECOMMENDATION IS NOT A DECISION. This enum exists to keep those two things apart in the
 * type system, because collapsing them is the single most consequential mistake this module
 * could make: the moment "recommended for approval" is treated as "approved", a social
 * worker's professional judgement silently becomes a commitment of public money that nobody
 * with approval authority ever made.
 *
 * The separation is enforced in three places, deliberately overlapping:
 *
 *  * here, in the vocabulary — these values are advisory verbs, never states;
 *  * in the lifecycle — `endorsed` and `approved` are separate states with separate
 *    permissions (ADR 0016);
 *  * in separation of duties — the endorser may not approve (ADR 0016 §6).
 *
 * Completing an assessment therefore moves a case to `endorsed` at most. Nothing here reaches
 * `approved`, and no configuration in this TAB can make it. The master command permits an
 * automatic path only behind "an explicit LGU-approved deterministic rule", and no such rule
 * has been supplied — see gap G-21.
 */
enum Recommendation: string
{
    /** The assessor recommends the case be approved. A human with approval authority decides. */
    case Approve = 'recommend-approve';

    /** The assessor recommends refusal. Still a recommendation; refusal is its own decision. */
    case Deny = 'recommend-deny';

    /** Better served elsewhere — another office, another agency, another programme. */
    case Refer = 'recommend-refer';

    /**
     * The assessor cannot form a view on what is in front of them.
     *
     * A first-class outcome rather than an absence. Forcing a recommendation from an
     * incomplete file is how "insufficient information" becomes "denied" in the record.
     */
    case Insufficient = 'insufficient-information';

    /**
     * The case state this recommendation *suggests* next.
     *
     * Suggests. The transition still goes through the state machine, still needs the target's
     * permission, and still passes separation of duties. Nothing here bypasses any of that —
     * a recommendation supplies a default for a human to act on, not an instruction.
     */
    public function suggestedNextStatus(): ?CaseStatus
    {
        return match ($this) {
            self::Approve => CaseStatus::Endorsed,
            // Deliberately NOT `Rejected`. A refusal is a decision with its own permission and
            // its own mandatory reason; an assessor recommending denial does not make one.
            self::Deny => null,
            self::Refer => null,
            self::Insufficient => CaseStatus::Returned,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $recommendation): string => $recommendation->value, self::cases());
    }
}
