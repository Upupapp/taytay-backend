<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

use Illuminate\Support\Carbon;

/**
 * One version of a document, as other modules see it.
 *
 * THE MASK IS ALREADY APPLIED. `documentNumber` is the display form — `••••3456` — built by
 * Files from the four characters it stored, so no consuming module can render the bare remnant
 * as though it were a whole number, and no consumer has to remember to mask (ADR 0020 §4).
 *
 * Carries no `stored_file_id` and no path. A module holding this can describe a document and ask
 * Files to open it; it cannot reach the bytes on its own.
 */
final class DocumentVersionView
{
    public function __construct(
        public readonly string $id,
        /** 1-based, never reused. */
        public readonly int $version,
        public readonly DocumentSource $source,
        /** Already masked, or null where no number is kept. */
        public readonly ?string $documentNumber,
        public readonly ?Carbon $issuedOn,
        public readonly ?Carbon $expiresOn,
        public readonly DocumentValidity $validity,
        public readonly VerificationStatus $verificationStatus,
        /** The reviewer's remark. Internal — a consumer decides who may see it. */
        public readonly ?string $verificationNote,
        public readonly ?Carbon $verifiedAt,
        public readonly ?Carbon $receivedAt,
        /** Set once a later version replaced this one. Never unset. */
        public readonly ?Carbon $supersededAt,
        public readonly ?string $supersededReason,
        /** Null for `encoded` and `external-verification`, which hold no file by design. */
        public readonly ?StoredFileView $file,
    ) {}

    public function isCurrent(): bool
    {
        return $this->supersededAt === null;
    }

    /**
     * Whether this version satisfies the requirement it was presented against.
     *
     * Verified AND not expired. Either alone is not enough: an accepted certificate that lapsed
     * last month is still accepted and no longer proves anything.
     */
    public function satisfies(): bool
    {
        return $this->verificationStatus->satisfiesRequirement() && $this->validity->isUsable();
    }
}
