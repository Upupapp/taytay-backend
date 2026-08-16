<?php

declare(strict_types=1);

namespace Modules\Files\Contracts;

/**
 * Where a recorded document came from.
 *
 * Taken from the admin console's own vocabulary (`DocumentSource` in the Angular domain), which
 * is authoritative for this concept — it was derived from how the office actually works.
 *
 * TWO OF THESE CARRY NO FILE, AND THAT IS THE POINT. The office routinely satisfies a
 * requirement without keeping a copy: a clerk types the details from a paper the applicant
 * brought and hands it back, or telephones the issuing office and confirms it. Inventing an
 * empty file for those cases would make "is there something to open?" a question every screen
 * has to guess at, and would suggest evidence the office does not hold.
 */
enum DocumentSource: string
{
    /** A file supplied in digital form. */
    case Uploaded = 'uploaded';

    /** A paper document imaged at the counter. */
    case Scanned = 'scanned';

    /** Details typed from a paper document. There is no image to open. */
    case Encoded = 'encoded';

    /** No copy held. A staff member confirmed the document with the office that issued it. */
    case ExternalVerification = 'external-verification';

    public function holdsAFile(): bool
    {
        return $this === self::Uploaded || $this === self::Scanned;
    }

    /**
     * Whether a document number is worth keeping for this source.
     *
     * **Only when there is no file.** For an uploaded or scanned document the image *is* the
     * record, and storing the number as well creates a second copy of a government identifier
     * for no operational gain — the same reasoning that keeps extracted numbers out of
     * `kyc_documents` (ADR 0010, data minimisation).
     *
     * For an encoded or externally-verified document there is nothing else: the number is the
     * only thing distinguishing "we checked their PhilSys card" from "we checked something".
     * Even then it is stored **masked** — see {@see DocumentNumber}.
     */
    public function needsADocumentNumber(): bool
    {
        return ! $this->holdsAFile();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
