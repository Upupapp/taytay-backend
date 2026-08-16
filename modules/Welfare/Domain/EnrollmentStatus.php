<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * Where a beneficiary stands on a programme's roll (ADR 0019 §2).
 *
 * Three states rather than a boolean, because `suspended` is a real and common condition that
 * `is_active` cannot express: a household under review for a possible double claim is neither
 * receiving nor removed, and collapsing it into either is wrong in a way that costs somebody
 * either money or their good name.
 */
enum EnrollmentStatus: string
{
    /** On the roll and receiving. */
    case Active = 'active';

    /**
     * On the roll, temporarily not receiving.
     *
     * Reversible by design. A suspension pending a check must be undoable without a new
     * enrolment row, or the roll fills with fragments every time somebody is queried and
     * cleared.
     */
    case Suspended = 'suspended';

    /** Off the roll. Terminal — rejoining is a new enrolment, not a revival. */
    case Exited = 'exited';

    public function isOnRoll(): bool
    {
        return $this !== self::Exited;
    }

    public function isReceiving(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this status may still change.
     *
     * Exit is final for a given enrolment. Reviving one would rewrite a period the beneficiary
     * was genuinely off the roll, and any release made in the meantime would silently look
     * authorised.
     */
    public function canChange(): bool
    {
        return $this !== self::Exited;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
