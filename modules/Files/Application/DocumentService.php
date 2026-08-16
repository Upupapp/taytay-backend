<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\VerificationStatus;
use Modules\Files\Domain\DocumentNumber;
use Modules\Files\Infrastructure\Eloquent\Document;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Presenting a document, replacing it, and deciding whether it is accepted (ADR 0020 §3).
 *
 * THE ACCEPTANCE CRITERION: **replacing a document preserves the old version's metadata.** There
 * is no `replace()` and no `delete()` in this class and there must not be. `append()` is the only
 * way a document changes, and it stamps the previous version rather than touching it.
 *
 * Why this is worth the extra table: the superseded version is the evidence of what the office
 * actually saw when it decided. A request approved in March on the strength of a certificate
 * that was replaced in June must still be explicable in December, and an overwriting model makes
 * that permanently unanswerable — not hard, unanswerable.
 *
 * This module NEVER decides who may do any of this. The owning module authorises and then calls;
 * only it knows whether a caller may touch a given case.
 */
final class DocumentService
{
    public function __construct(private readonly FilesAudit $audit) {}

    /**
     * The document slot for an owning record, created on first use.
     */
    public function slot(string $ownerType, string $ownerId, string $documentType): Document
    {
        /** @var Document $document */
        $document = Document::query()->firstOrCreate([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'document_type' => $documentType,
        ]);

        return $document;
    }

    /**
     * Appends a version, superseding whatever stood in its place.
     *
     * @param  array<string, mixed>  $attributes  source, document_number, issued_on, expires_on,
     *                                            expiry_unknown, replaces_because
     */
    public function append(
        Document $document,
        ?StoredFile $file,
        array $attributes,
        ActorContext $actor,
    ): DocumentVersion {
        $source = $attributes['source'] instanceof DocumentSource
            ? $attributes['source']
            : DocumentSource::from((string) $attributes['source']);

        $this->assertFileMatchesSource($source, $file);
        $this->assertDatesAreOrdered($attributes);

        return DB::transaction(function () use ($document, $file, $attributes, $actor, $source): DocumentVersion {
            /*
             * Locked, and the version number is read inside the lock.
             *
             * Two clerks replacing the same document in the same second would otherwise both read
             * version 3 and both write version 4 — and the unique key would reject the second,
             * turning a routine collision into an error somebody has to retry with a file they
             * may no longer have in front of them.
             */
            $previous = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            $isReplacement = $previous !== null;
            $reason = trim((string) ($attributes['replaces_because'] ?? ''));

            /*
             * A replacement must say why.
             *
             * This is the rule the whole append-only model exists to make meaningful: an
             * unexplained supersession leaves a version nobody can account for, which is worse
             * than having no history at all, because it looks like history.
             */
            if ($isReplacement && $reason === '') {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'Say why this document is being replaced.',
                );
            }

            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'version' => $isReplacement ? (int) $previous->version + 1 : 1,
                'stored_file_id' => $file?->id,
                'source' => $source,
                /*
                 * Masked before storage, and only kept where there is no file to be the record
                 * instead (ADR 0020 §4). The caller may hand over a full number; what survives
                 * this line is four characters.
                 */
                'document_number_last4' => $source->needsADocumentNumber()
                    ? DocumentNumber::lastFour($attributes['document_number'] ?? null)
                    : null,
                'issued_on' => $attributes['issued_on'] ?? null,
                'expires_on' => ($attributes['expiry_unknown'] ?? false) ? null : ($attributes['expires_on'] ?? null),
                'expiry_unknown' => (bool) ($attributes['expiry_unknown'] ?? false),
                'verification_status' => VerificationStatus::Pending,
                'received_by' => $actor->subjectId,
                'received_at' => now(),
            ]);

            if ($isReplacement) {
                // The previous row is stamped, never edited. Its file, dates, number and
                // reviewer stay exactly as they were when the office relied on them.
                $previous->forceFill([
                    'superseded_at' => now(),
                    'superseded_reason' => $reason,
                ])->save();
            }

            $this->audit->recordDocument(
                $actor->subjectId,
                $isReplacement ? 'document.replaced' : 'document.presented',
                $isReplacement ? 'Document replaced' : 'Document presented',
                (string) $document->uuid,
            );

            return $version;
        });
    }

    /**
     * A reviewer accepts or refuses a version.
     *
     * Only the current version can be decided. Verifying a superseded one would put an accepted
     * stamp on a document the office has already replaced, and the requirement would then be
     * satisfied by evidence nobody is using.
     */
    public function decide(
        DocumentVersion $version,
        VerificationStatus $status,
        ActorContext $actor,
        ?string $note,
    ): DocumentVersion {
        if (! $status->isDecided()) {
            throw new ApiException(ErrorCode::BadRequest, 'A review must accept or reject.');
        }

        if (! $version->isCurrent()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'That version has been replaced. Review the current one.',
            );
        }

        // Refusing must say what was wrong: the applicant has to be told what to bring instead,
        // and an unexplained rejection cannot be distinguished from a mistake.
        if ($status->requiresNote() && trim((string) $note) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, 'Say why this document was not accepted.');
        }

        $version->forceFill([
            'verification_status' => $status,
            'verification_note' => $note,
            'verified_by' => $actor->subjectId,
            'verified_at' => now(),
        ])->save();

        $this->audit->recordDocument(
            $actor->subjectId,
            'document.'.$status->value,
            'Document '.$status->value,
            (string) $version->document()->value('uuid'),
        );

        return $version->refresh();
    }

    /**
     * Every version ever presented against a slot, oldest first.
     *
     * @return Collection<int, DocumentVersion>
     */
    public function history(Document $document): Collection
    {
        return $document->versions()->with('file')->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertDatesAreOrdered(array $attributes): void
    {
        $issued = $attributes['issued_on'] ?? null;
        $expires = $attributes['expires_on'] ?? null;

        if ($issued === null || $expires === null) {
            return;
        }

        if (Carbon::parse((string) $expires)->lt(Carbon::parse((string) $issued))) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'That document expires before it was issued.',
            );
        }
    }

    private function assertFileMatchesSource(DocumentSource $source, ?StoredFile $file): void
    {
        if ($source->holdsAFile() && $file === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'That kind of record needs a file.');
        }

        /*
         * And the inverse, which is the one people leave out. A file attached to an
         * `external-verification` record claims the office holds a copy it does not, and the
         * next reader has no way to tell that the record is lying about itself.
         */
        if (! $source->holdsAFile() && $file !== null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'That kind of record holds no file.',
            );
        }
    }
}
