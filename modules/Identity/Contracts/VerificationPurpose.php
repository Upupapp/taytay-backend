<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/**
 * Why a one-time code was issued.
 *
 * Purpose is part of the lookup, so a code sent to prove an email address cannot be
 * replayed to sign in. Codes are single-purpose by construction rather than by convention.
 */
enum VerificationPurpose: string
{
    case SignIn = 'sign-in';
    case VerifyEmail = 'verify-email';
    case VerifyMobile = 'verify-mobile';
}
