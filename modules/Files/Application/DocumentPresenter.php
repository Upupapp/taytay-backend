<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Modules\Files\Contracts\DocumentVersionView;
use Modules\Files\Contracts\StoredFileView;
use Modules\Files\Domain\DocumentNumber;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Files\Infrastructure\Eloquent\StoredFile;

/**
 * Turns Files' own rows into the published views other modules receive.
 *
 * The one place the Eloquent shape is converted, so the boundary is a single reviewable file
 * rather than a habit spread across every caller. Everything that leaves this module leaves
 * through here.
 */
final class DocumentPresenter
{
    public function version(DocumentVersion $version): DocumentVersionView
    {
        /** @var StoredFile|null $file */
        $file = $version->relationLoaded('file') ? $version->getRelation('file') : $version->file()->first();

        return new DocumentVersionView(
            id: (string) $version->uuid,
            version: (int) $version->version,
            source: $version->source,
            // Masked here, once, so no consumer can render the stored remnant as a whole number
            // and none has to remember to mask (ADR 0020 §4).
            documentNumber: DocumentNumber::display($version->document_number_last4),
            issuedOn: $version->issued_on,
            expiresOn: $version->expires_on,
            validity: $version->validity(),
            verificationStatus: $version->verification_status,
            verificationNote: $version->verification_note,
            verifiedAt: $version->verified_at,
            receivedAt: $version->received_at,
            supersededAt: $version->superseded_at,
            supersededReason: $version->superseded_reason,
            file: $file === null ? null : $this->file($file),
        );
    }

    public function file(StoredFile $file): StoredFileView
    {
        return new StoredFileView(
            id: (string) $file->uuid,
            name: (string) $file->original_name,
            mimeType: (string) $file->mime_type,
            byteSize: (int) $file->byte_size,
            pageCount: $file->page_count === null ? null : (int) $file->page_count,
            scanStatus: $file->scan_status,
            isAvailable: $file->isAvailable(),
        );
    }
}
