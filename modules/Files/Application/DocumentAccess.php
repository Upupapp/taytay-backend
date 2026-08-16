<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Support\Facades\DB;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Files\Infrastructure\Eloquent\FileAccessGrant;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;

/**
 * Handing out permission to read one file, once (ADR 0020 §5).
 *
 * TWO ACCEPTANCE CRITERIA MEET HERE: direct object-storage paths are not public, and a guessed
 * file id cannot be downloaded. Both are structural rather than checked:
 *
 *  * there is **no public URL anywhere** — the `object-storage` disk is configured without a
 *    `url`, so nothing can accidentally produce one, and the bytes are streamed by this
 *    application after a decision;
 *  * a grant is **issued to an account** and refused to anybody else even while unexpired, so
 *    guessing an id gets you a handle you cannot redeem, and stealing a handle gets you nothing.
 *
 * WHY A GRANT RATHER THAN A SIGNED URL. A signature is valid wherever it is pasted, for as long
 * as it lasts, to whoever holds it, and nothing records that it was used. That last part is the
 * one that decides it: Article 5.4 requires every read of another person's personal data to be
 * auditable, and object storage does not tell this application when somebody fetches an object.
 * A redeemable row is what makes `document.read` a fact somebody can find.
 *
 * THIS CLASS DOES NOT AUTHORISE. It records a decision the owning module has already made — only
 * Welfare knows whether this caller may see this case's attachments. Calling `issue()` IS the
 * assertion that the check has happened, which is why it takes an actor and not a permission.
 */
final class DocumentAccess
{
    /**
     * How long a handle lives.
     *
     * Long enough for a slow connection to start the transfer, short enough that a handle left
     * in a browser history or pasted into a chat is dead before anybody finds it.
     */
    public const GRANT_TTL_SECONDS = 120;

    public function __construct(
        private readonly FileStore $files,
        private readonly FilesAudit $audit,
    ) {}

    /**
     * Issues a single-use handle for a version's file.
     *
     * @param  string  $purpose  'view', 'download', 'preview' or 'share' — recorded because
     *                           opening a document to check a date and taking a copy out of the
     *                           office are different acts.
     */
    public function issue(
        DocumentVersion $version,
        ActorContext $actor,
        string $purpose = 'view',
        bool $forSharing = false,
    ): FileAccessGrant {
        /** @var StoredFile|null $file */
        $file = $version->file()->first();

        if ($file === null) {
            // An `encoded` or `external-verification` record. Not an error in the system, but
            // there is nothing to open, and saying so is better than a 404 that suggests the
            // record is missing.
            throw new ApiException(ErrorCode::Conflict, 'That record holds no file to open.');
        }

        if ($file->purged_at !== null) {
            throw new ApiException(ErrorCode::Conflict, 'That file has been removed under the retention schedule.');
        }

        // Quarantined files are served to nobody, by any route, whatever the caller holds.
        if (! $file->scan_status->mayBeServed()) {
            throw new ApiException(ErrorCode::Conflict, 'That file failed a security check and cannot be opened.');
        }

        /*
         * Sharing is stricter than reading, in two independent ways.
         *
         * A caseworker opening an unscanned attachment on a managed workstation is a risk the
         * office already took by accepting the upload; handing that same file to a partner
         * agency passes the risk to somebody else. And safeguarding material does not leave by
         * any route this system offers.
         */
        if ($forSharing && ! $file->scan_status->mayLeaveTheOffice()) {
            throw new ApiException(ErrorCode::Conflict, 'That file has not been scanned yet and cannot be shared.');
        }

        if ($forSharing && ! $file->classification->mayBeSharedOutward()) {
            throw new ApiException(ErrorCode::Forbidden, 'Documents of this kind may not be shared outside the office.');
        }

        if ($actor->subjectId === null) {
            throw new ApiException(ErrorCode::Forbidden, 'A file may only be issued to an identified account.');
        }

        return FileAccessGrant::query()->create([
            'stored_file_id' => $file->id,
            'document_version_id' => $version->id,
            'issued_to' => $actor->subjectId,
            'purpose' => $purpose,
            'redacted_for_sharing' => $forSharing,
            'expires_at' => now()->addSeconds(self::GRANT_TTL_SECONDS),
        ]);
    }

    /**
     * Exchanges a handle for bytes, once.
     *
     * Consumption happens inside a locked transaction *before* the bytes are read, so two
     * simultaneous redemptions of one handle cannot both succeed. A handle that raced would
     * otherwise be a handle that worked twice, which is the one property single-use exists to
     * deny.
     *
     * @return array{grant: FileAccessGrant, file: StoredFile, contents: string}
     */
    public function redeem(string $handle, ActorContext $actor): array
    {
        $grant = DB::transaction(function () use ($handle, $actor): FileAccessGrant {
            /** @var FileAccessGrant|null $grant */
            $grant = FileAccessGrant::query()->where('uuid', $handle)->lockForUpdate()->first();

            /*
             * Expired, already used, unknown and issued-to-somebody-else all answer NOT FOUND.
             *
             * Distinguishing them would turn this endpoint into an oracle: "expired" confirms
             * the handle was real, and that is exactly what somebody probing wants to learn
             * (OWASP API1, the same rule the rest of this API follows for out-of-scope records).
             */
            if ($grant === null || ! $grant->isRedeemableBy($actor->subjectId)) {
                throw ResourceNotFoundException::make('That link is no longer valid.');
            }

            $grant->forceFill(['consumed_at' => now()])->save();

            return $grant;
        });

        /** @var StoredFile $file */
        $file = $grant->storedFile()->firstOrFail();

        $contents = $this->files->contents($file);

        /*
         * The audit entry is written on the read, not on the issue.
         *
         * Issuing a handle is an intention; redeeming it is the disclosure. Recording only the
         * first would over-report — a grant that expired unused becomes a read that never
         * happened — and recording only requests would miss the ones served from a retry.
         */
        $this->audit->recordRead(
            $actor->subjectId,
            (string) $grant->document_version_id === '' ? '' : (string) DocumentVersion::query()
                ->whereKey($grant->document_version_id)
                ->value('uuid'),
            (string) $grant->purpose,
        );

        return ['grant' => $grant, 'file' => $file, 'contents' => $contents];
    }
}
