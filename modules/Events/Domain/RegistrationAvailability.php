<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

/**
 * Whether somebody may register right now.
 *
 * **DERIVED, NEVER STORED.** The master command asks for exactly this, and the reason is that a
 * stored copy is wrong the moment the clock moves past it: a column saying `open` at 17:00 for a
 * registration that closed at 16:59 needs something to notice and rewrite it, and whatever that
 * something is will one day not run — at which point the API is telling people to register for
 * something that closed, and the only symptom is a queue of confused residents.
 *
 * Computed, the answer is always current, a missed job is impossible, and there is no second
 * source to disagree with the first (ADR 0030 §2).
 */
enum RegistrationAvailability: string
{
    /** Registration is configured but has not opened yet. */
    case NotOpen = 'not-open';

    case Open = 'open';

    /** The window has passed, or the event is no longer accepting anybody. */
    case Closed = 'closed';

    /** Capacity is reached. Distinct from `closed` because a waitlist may still accept. */
    case Full = 'full';

    /** This event does not use registration at all. */
    case NotRequired = 'not-required';

    public function acceptsRegistration(): bool
    {
        return $this === self::Open;
    }

    /**
     * What a client shows. Returned alongside the state so the wording is the same everywhere and
     * a client cannot invent a friendlier one for a closed window.
     */
    public function message(): string
    {
        return match ($this) {
            self::NotRequired => 'No registration needed. Just come along.',
            self::NotOpen => 'Registration has not opened yet.',
            self::Open => 'Registration is open.',
            self::Closed => 'Registration has closed.',
            self::Full => 'This event is full.',
        };
    }
}
