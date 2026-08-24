<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Domain;

/**
 * What an applicant may attach to their own KYC case (F28).
 *
 * ---
 *
 * **A closed set, and a short one.** A KYC case is adjudicated first by `ResidentMatcher`
 * against the canonical registry; documents exist to settle the cases the match does not. They
 * are not a checklist an applicant works through, and every type added here is one more piece of
 * identity material this office holds about a person who may turn out to be already registered.
 *
 * **THERE IS NO SELFIE, NO LIVENESS CAPTURE AND NO BIOMETRIC**, and adding one is not a small
 * change. A facial image is `Sensitive` under this system's own classification, it is not
 * revocable the way a password is, and a released mobile build cannot be trusted to grade its own
 * verification (ADR 0002). Identity here is confirmed by a person comparing a document to a
 * registry, which is what the office already does at a counter.
 *
 * The `value` is also the `document_type` slot key in `Files`, so a case has at most one live
 * version of each — superseded rather than replaced, because the superseded version is the
 * evidence that matters when somebody asks what was shown at the time.
 */
enum KycDocumentType: string
{
    /**
     * A government-issued ID: PhilID, passport, driver's licence, postal ID, voter's ID.
     *
     * The one that usually settles it. Not narrowed to a specific issuer, because a resident who
     * holds only a barangay certificate and a voter's ID is exactly the resident this service is
     * for, and a form that accepts only a PhilID excludes the people least likely to have one.
     */
    case IdentityDocument = 'identity-document';

    /**
     * Something showing the claimed address: a utility bill, a barangay certificate.
     *
     * Separate from the identity document because the two answer different questions and a
     * reviewer may accept one and not the other. Optional in practice — the barangay office
     * frequently knows the household already.
     */
    case ProofOfAddress = 'proof-of-address';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * The `owner_type` every KYC document slot is filed under.
     *
     * A constant rather than a literal at each call site: `slotFor()` takes three strings, and a
     * typo in this one silently creates a second slot that nothing ever reads.
     */
    public const OWNER_TYPE = 'kyc-case';
}
