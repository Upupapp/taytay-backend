<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Http\UploadedFile;
use Modules\Files\Contracts\DocumentVersionView;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\StoredFileView;
use Modules\Files\Contracts\VerificationStatus;
use Modules\Files\Infrastructure\Eloquent\Document;
use Modules\Files\Infrastructure\Eloquent\DocumentVersion;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;

/**
 * THE PUBLISHED ENTRY POINT TO THIS MODULE. Everything crosses the boundary here.
 *
 * Other modules speak UUIDs and receive {@see DocumentVersionView} / {@see StoredFileView}. They
 * never hold a Files Eloquent model, which is what Article 2.1 requires and, more usefully, what
 * makes two guarantees structural rather than habitual:
 *
 *  * a consuming module **cannot reach the bytes on its own** — no disk, no storage key, no path
 *    is published, so "direct object-storage paths are not public" survives whatever gets built
 *    on top of this;
 *  * a consuming module **cannot rewrite history** — there is no model to call `delete()` or
 *    `update()` on, so the append-only guarantee does not depend on every future caller
 *    remembering it.
 *
 * IT STILL AUTHORISES NOTHING. Only the module owning the record knows whether a caller may see
 * it. Calling a method here IS the assertion that the check has happened, which is why each takes
 * an actor rather than a permission.
 */
final class DocumentLibrary
{
    public function __construct(
        private readonly FileStore $files,
        private readonly DocumentService $documents,
        private readonly DocumentAccess $access,
        private readonly DocumentPresenter $presenter,
    ) {}

    // ── storing bytes ─────────────────────────────────────────────────────────────────

    /**
     * Validates and stores an upload. Returns the view; the caller never sees the row.
     *
     * The classification is the caller's to state, because only the owning module knows what the
     * document is — a default applied here over a safeguarding photograph would be a silent
     * declassification nobody would see.
     */
    public function store(UploadedFile $upload, FileClassification $classification, ActorContext $actor): StoredFileView
    {
        return $this->presenter->file($this->files->store($upload, $classification, $actor));
    }

    // ── documents ─────────────────────────────────────────────────────────────────────

    /**
     * The document slot for an owning record, created on first use. Returns its UUID.
     */
    public function slotFor(string $ownerType, string $ownerId, string $documentType): string
    {
        return (string) $this->documents->slot($ownerType, $ownerId, $documentType)->uuid;
    }

    /**
     * Appends a version, superseding whatever stood in its place.
     *
     * There is no `replace()` here and there must not be — see {@see DocumentService} for why
     * the superseded version is the evidence that matters.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function append(
        string $documentUuid,
        ?string $fileUuid,
        array $attributes,
        ActorContext $actor,
    ): DocumentVersionView {
        return $this->presenter->version($this->documents->append(
            $this->documentOrFail($documentUuid),
            $fileUuid === null ? null : $this->fileOrFail($fileUuid),
            $attributes,
            $actor,
        ));
    }

    /**
     * A reviewer accepts or refuses a version.
     */
    public function decide(
        string $versionUuid,
        VerificationStatus $status,
        ActorContext $actor,
        ?string $note,
    ): DocumentVersionView {
        return $this->presenter->version(
            $this->documents->decide($this->versionOrFail($versionUuid), $status, $actor, $note),
        );
    }

    public function currentVersion(?string $documentUuid): ?DocumentVersionView
    {
        if ($documentUuid === null) {
            return null;
        }

        $version = $this->documentOrFail($documentUuid)->currentVersion();

        return $version === null ? null : $this->presenter->version($version);
    }

    /**
     * Every version ever presented, oldest first — including superseded ones.
     *
     * @return list<DocumentVersionView>
     */
    public function history(string $documentUuid): array
    {
        return $this->documents->history($this->documentOrFail($documentUuid))
            ->map(fn (DocumentVersion $version): DocumentVersionView => $this->presenter->version($version))
            ->values()->all();
    }

    /**
     * A version, but only if it sits in the given document.
     *
     * The seam that stops a version UUID from one case opening through another: the caller has
     * already resolved which slot it may touch, and this refuses anything outside it. Without it
     * the case id in a route would be decoration.
     */
    public function versionWithin(string $versionUuid, ?string $documentUuid): ?DocumentVersionView
    {
        if ($documentUuid === null) {
            return null;
        }

        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::query()->where('uuid', $versionUuid)->first();

        if ($version === null) {
            return null;
        }

        $owner = (string) Document::query()->whereKey($version->document_id)->value('uuid');

        return $owner === $documentUuid ? $this->presenter->version($version) : null;
    }

    /**
     * How closely the file behind a version must be held, or null where it holds none.
     *
     * Published so a consumer can require an extra permission before opening safeguarding
     * material without having to reach into the file itself.
     */
    public function classificationOf(string $versionUuid): ?FileClassification
    {
        /** @var StoredFile|null $file */
        $file = $this->versionOrFail($versionUuid)->file()->first();

        return $file?->classification;
    }

    // ── access ────────────────────────────────────────────────────────────────────────

    /**
     * Issues a single-use handle for a version's file.
     *
     * @return array{handle: string, expires_at: string, redacted_for_sharing: bool}
     */
    public function issueAccess(
        string $versionUuid,
        ActorContext $actor,
        string $purpose = 'view',
        bool $forSharing = false,
    ): array {
        $grant = $this->access->issue($this->versionOrFail($versionUuid), $actor, $purpose, $forSharing);

        return [
            'handle' => (string) $grant->uuid,
            'expires_at' => (string) $grant->expires_at?->toIso8601ZuluString(),
            'redacted_for_sharing' => (bool) $grant->redacted_for_sharing,
        ];
    }

    /**
     * Exchanges a handle for bytes, once.
     *
     * @return array{file: StoredFileView, contents: string}
     */
    public function redeem(string $handle, ActorContext $actor): array
    {
        $redeemed = $this->access->redeem($handle, $actor);

        return [
            'file' => $this->presenter->file($redeemed['file']),
            'contents' => $redeemed['contents'],
        ];
    }

    // ── lookups ───────────────────────────────────────────────────────────────────────

    private function documentOrFail(string $uuid): Document
    {
        /** @var Document|null $document */
        $document = Document::query()->where('uuid', $uuid)->first();

        if ($document === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        return $document;
    }

    private function versionOrFail(string $uuid): DocumentVersion
    {
        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::query()->where('uuid', $uuid)->first();

        if ($version === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        return $version;
    }

    private function fileOrFail(string $uuid): StoredFile
    {
        /** @var StoredFile|null $file */
        $file = StoredFile::query()->where('uuid', $uuid)->first();

        if ($file === null) {
            throw ResourceNotFoundException::make('That file was not found.');
        }

        return $file;
    }
}
