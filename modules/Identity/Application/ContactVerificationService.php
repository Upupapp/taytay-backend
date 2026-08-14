<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use Modules\Identity\Contracts\VerificationPurpose;
use Modules\Identity\Infrastructure\Eloquent\Account;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Proving that an account controls the email address or mobile number it claims.
 *
 * This matters beyond tidiness: an unverified contact is where password-reset links and
 * one-time codes would be delivered, so an unverified address is a route into the account.
 * Verification is also the prerequisite ResidentProfile's KYC will build on — but note it
 * proves control of a *channel*, never that the holder is a particular person. Identity
 * verification is a separate concern (TAB 06).
 */
final class ContactVerificationService
{
    public function __construct(
        private readonly OneTimeCodes $codes,
        private readonly IdentityAudit $audit,
    ) {}

    /**
     * @return string the plaintext code to deliver over the chosen channel
     */
    public function request(Account $account, VerificationPurpose $purpose): string
    {
        $this->assertContactPresent($account, $purpose);

        return $this->codes->issue($account, $purpose);
    }

    public function confirm(Account $account, VerificationPurpose $purpose, string $code): void
    {
        $this->assertContactPresent($account, $purpose);

        if (! $this->codes->consume($account, $purpose, $code)) {
            throw new ApiException(ErrorCode::Unauthenticated, 'That code is not valid.');
        }

        $account->forceFill([
            match ($purpose) {
                VerificationPurpose::VerifyEmail => 'email_verified_at',
                VerificationPurpose::VerifyMobile => 'mobile_verified_at',
                default => throw new ApiException(ErrorCode::BadRequest, 'That verification purpose is not supported here.'),
            } => now(),
        ])->save();

        $this->audit->record($account, 'identity.contact-verified', "Contact verified: {$purpose->value}");
    }

    /**
     * Refuses to issue a code for a channel the account has not supplied — otherwise the
     * flow silently succeeds and nothing is ever delivered.
     */
    private function assertContactPresent(Account $account, VerificationPurpose $purpose): void
    {
        $missing = match ($purpose) {
            VerificationPurpose::VerifyEmail => $account->email === null,
            VerificationPurpose::VerifyMobile => $account->mobile_number === null,
            default => true,
        };

        if ($missing) {
            throw new ApiException(ErrorCode::Conflict, 'There is no contact detail of that kind on this account.');
        }
    }
}
