<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * Whether a conditional requirement applies to this applicant.
 *
 * THE SOFTWARE STATES THE CONDITION AND NEVER EVALUATES IT. A conditional requirement reads
 * something like "if the applicant is not the patient, a medical certificate naming them" — and
 * whether that holds is a judgement about a person's circumstances, not a comparison.
 *
 * Deciding that somebody does **not** need a document is exactly as consequential as deciding
 * that they do: it is the step that can quietly waive a safeguard, and it is the one an auditor
 * asks about after the fact. So it carries an author, a timestamp and a mandatory reason, and
 * `Undecided` is a real state rather than a default that means "probably fine".
 */
enum RequirementApplicability: string
{
    case Undecided = 'undecided';
    case Applies = 'applies';
    case DoesNotApply = 'does-not-apply';

    public function isDecided(): bool
    {
        return $this !== self::Undecided;
    }
}
