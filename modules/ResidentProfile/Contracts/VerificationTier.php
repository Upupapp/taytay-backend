<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Contracts;

/**
 * How well a resident's identity has been established (ADR 0010 §4).
 *
 * Deliberately NOT the same as Identity's email/mobile verification, which only proves
 * control of a contact channel. Being able to receive an SMS is not evidence of who you
 * are, and conflating the two is how an unverified person ends up holding a verified
 * record.
 *
 * The tier only ever rises through a human decision. Nothing automatic promotes anyone.
 */
enum VerificationTier: string
{
    /** Self-asserted only. The default, and the only tier registration can produce. */
    case Unverified = 'unverified';

    /** Some evidence accepted — enough to transact, not enough to hold a credential. */
    case PartiallyVerified = 'partially-verified';

    /** Identity established by a reviewer against documents. */
    case Verified = 'verified';

    /**
     * A digital ID asserts identity to third parties, so it requires the full tier
     * (ADR 0011). Partial verification is enough to receive assistance — the LGU must not
     * make help conditional on paperwork a person cannot produce.
     */
    public function mayHoldCredential(): bool
    {
        return $this === self::Verified;
    }
}
