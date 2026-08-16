<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

use Illuminate\Support\Carbon;

/**
 * How close a document is to lapsing.
 *
 * Mirrors the admin console's `DocumentValidity` exactly, including the distinction that matters
 * most: **`NoExpiry` and `Unknown` are different facts.** A birth certificate genuinely never
 * expires; a certificate of indigency whose expiry nobody wrote down might have lapsed months
 * ago. Collapsing both to "fine" hides the second, which is the one that is somebody's
 * unfinished work.
 */
enum DocumentValidity: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring-soon';
    case Expired = 'expired';
    case NoExpiry = 'no-expiry';
    case Unknown = 'unknown';

    /**
     * How much warning staff get before a document lapses, in days.
     *
     * **The office's own convention, not a legal period** — a certificate of indigency is
     * commonly treated as good for six months and staff want warning before it lapses rather
     * than after. Carried over from the console, where it is recorded with the same caveat, and
     * still not confirmed against a written issuance (gap G-25).
     */
    public const WARNING_DAYS = 30;

    public const WARNING_BASIS = 'Office convention, pending confirmation against a written issuance.';

    public static function of(?Carbon $expiresOn, bool $expiryUnknown, ?Carbon $on = null): self
    {
        if ($expiryUnknown) {
            return self::Unknown;
        }

        if ($expiresOn === null) {
            return self::NoExpiry;
        }

        $now = $on ?? Carbon::now();

        // Inclusive of the expiry day: a document expiring today is still good today, which is
        // what the person holding it at the counter believes and is right about.
        if ($expiresOn->endOfDay()->lt($now)) {
            return self::Expired;
        }

        return $now->diffInDays($expiresOn->endOfDay()) <= self::WARNING_DAYS
            ? self::ExpiringSoon
            : self::Valid;
    }

    /**
     * Whether this document can still satisfy a requirement today.
     *
     * `Unknown` counts as usable: nobody has established that it lapsed, and refusing an
     * applicant because a clerk omitted a field is punishing them for the office's omission.
     * It surfaces as work to finish, not as a blocker.
     */
    public function isUsable(): bool
    {
        return $this !== self::Expired;
    }
}
