<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Domain;

/**
 * The advisory result of running guidance against an applicant (ADR 0018 §3).
 *
 * NAMED TO BE UNUSABLE AS A DECISION. There is no `eligible` and no `ineligible` value, and
 * their absence is the control: the first thing a later feature would do with an `ineligible`
 * is read it as the answer, and the second thing somebody would do is wire it to a refusal. A
 * value called `likely-ineligible` cannot be mistaken for a determination in a code review, in
 * a screen, or in a conversation with an applicant.
 *
 * The master command permits this engine to "flag likely matches/mismatches" and forbids it
 * becoming "an opaque denial system". These three values are that sentence, expressed as a type.
 *
 * Nothing in this codebase transitions a case on the strength of one. Refusal is
 * `CaseStatus::Rejected`, which needs `request.reject`, a mandatory reason, and a human
 * (ADR 0016).
 */
enum EligibilityOutcome: string
{
    /** Every criterion the guidance could evaluate was met. */
    case LikelyEligible = 'likely-eligible';

    /** At least one blocking criterion was clearly not met. */
    case LikelyIneligible = 'likely-ineligible';

    /**
     * Something could not be determined, or a non-blocking criterion failed.
     *
     * The default destination for doubt. A case worker looks; the system does not guess.
     */
    case NeedsReview = 'needs-review';

    /**
     * Builds the outcome from criterion results.
     *
     * The ordering is the policy:
     *
     *  1. Anything unknown → `needs-review`. Absence of evidence is not evidence of
     *     ineligibility, and incomplete records belong overwhelmingly to the people least able
     *     to complete them.
     *  2. A blocking criterion clearly not met → `likely-ineligible`.
     *  3. A non-blocking criterion not met → `needs-review`, never a mismatch verdict.
     *  4. Otherwise → `likely-eligible`.
     *
     * Note that (1) outranks (2) deliberately: a case with one clear mismatch and one unknown
     * fact goes to a human, because the unknown might be the thing that explains the mismatch.
     *
     * Named `fromResults` rather than `from`: a backed enum already declares `from(string)`, and
     * overloading it would be a fatal redeclaration.
     *
     * @param  list<array{result: string, is_blocking: bool}>  $results
     */
    public static function fromResults(array $results): self
    {
        if ($results === []) {
            // A programme with no guidance is not a programme everybody qualifies for. It is a
            // programme nobody has written rules for yet, which is a question for staff.
            return self::NeedsReview;
        }

        foreach ($results as $result) {
            if ($result['result'] === 'unknown') {
                return self::NeedsReview;
            }
        }

        foreach ($results as $result) {
            if ($result['result'] === 'not-met' && $result['is_blocking']) {
                return self::LikelyIneligible;
            }
        }

        foreach ($results as $result) {
            if ($result['result'] === 'not-met') {
                return self::NeedsReview;
            }
        }

        return self::LikelyEligible;
    }

    /**
     * What an applicant is told, if they are told anything.
     *
     * Never phrased as a decision. "You may qualify" is honest about what the system did; "You
     * qualify" is a promise the system has no authority to make, and the office would have to
     * break it.
     */
    public function citizenMessage(): string
    {
        return match ($this) {
            self::LikelyEligible => 'Based on the information we hold, you may qualify for this programme. A case officer will confirm.',
            self::LikelyIneligible => 'Based on the information we hold, you may not meet the conditions for this programme. You may still apply, and a case officer will review it.',
            self::NeedsReview => 'We need a case officer to look at this before we can say whether the programme applies to you.',
        };
    }
}
