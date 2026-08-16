<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * How urgently a case needs attention (ADR 0016 §4).
 *
 * SET BY A PERSON, NEVER DERIVED FROM THE VULNERABILITY SCORE.
 *
 * That restraint is deliberate and is the reason this enum exists rather than a computed
 * integer. The score in ADR 0015 is placeholder weights awaiting MSWDO approval (gap G-20),
 * it carries `decision_support_only: true` in its own payload, and safeguarding factors
 * contribute nothing to it by design. Wiring it into queue order would make an unapproved
 * ordering consequential — and would do it quietly, because nobody reading a case list can
 * see where the order came from.
 *
 * A case worker may of course *look at* the vulnerability snapshot before choosing. That is
 * what decision support means: a human reads the evidence and takes responsibility for the
 * judgement, and the judgement is recorded against their name.
 */
enum CasePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    /**
     * Immediate risk to life, safety or shelter.
     *
     * Raising a case to this level requires a reason, recorded on the case, because it moves
     * that person ahead of everyone else waiting.
     */
    case Urgent = 'urgent';

    public function requiresReason(): bool
    {
        return $this === self::Urgent;
    }

    /**
     * Sort weight, highest first. Used only to order a staff queue.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $priority): string => $priority->value, self::cases());
    }
}
