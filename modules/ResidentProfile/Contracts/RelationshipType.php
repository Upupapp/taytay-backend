<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * How one resident is related to another (ADR 0014 §4).
 *
 * Read a row as "<resident> is the <type> of <related_resident>".
 *
 * ONE DIRECTED ROW, INVERSE DERIVED. Storing both "A is parent of B" and "B is child of A"
 * gives two rows that can disagree after either is edited, with no principled way to decide
 * which is then true. So exactly one is stored and {@see inverse()} produces the other view
 * on read.
 *
 * That is also what makes duplicate prevention tractable: before writing `A parent-of B`,
 * the service checks for `B child-of A`. Without a defined inverse there is nothing to check
 * against, and every family ends up with both halves recorded — usually with different
 * effective dates.
 */
enum RelationshipType: string
{
    case Parent = 'parent';
    case Child = 'child';
    case Guardian = 'guardian';
    case Ward = 'ward';
    case Spouse = 'spouse';
    case Partner = 'partner';
    case Sibling = 'sibling';

    /** Financially or materially dependent on the other person. */
    case Dependent = 'dependent';

    /** The other side of `Dependent` — the person providing that support. */
    case Provider = 'provider';

    /**
     * Anything the LGU records in free text.
     *
     * Its inverse is itself, which is imprecise and deliberately so: an open category cannot
     * have a computable opposite, and inventing one would produce confidently wrong output.
     * `note` carries the detail a human needs.
     */
    case Other = 'other';

    /**
     * The relationship as seen from the other person.
     *
     * Symmetric types return themselves — a sibling's sibling is a sibling. That is a real
     * property of the relation, not a placeholder.
     */
    public function inverse(): self
    {
        return match ($this) {
            self::Parent => self::Child,
            self::Child => self::Parent,
            self::Guardian => self::Ward,
            self::Ward => self::Guardian,
            self::Dependent => self::Provider,
            self::Provider => self::Dependent,
            self::Spouse => self::Spouse,
            self::Partner => self::Partner,
            self::Sibling => self::Sibling,
            self::Other => self::Other,
        };
    }

    /**
     * Whether the relation reads the same in both directions.
     *
     * Matters when checking for a duplicate: for a symmetric type, `A spouse-of B` and
     * `B spouse-of A` are the same fact recorded twice, and the second must be refused.
     */
    public function isSymmetric(): bool
    {
        return $this === $this->inverse();
    }

    /**
     * Whether this relation implies responsibility for the other person.
     *
     * Used to decide what a citizen may see of a household member: a parent or guardian has
     * a legitimate reason to see their child's basic profile; a boarder sharing the roof
     * does not (ADR 0014 §5).
     */
    public function impliesCareResponsibility(): bool
    {
        return match ($this) {
            self::Parent, self::Guardian, self::Provider => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
