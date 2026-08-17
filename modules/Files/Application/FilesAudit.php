<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Modules\Shared\Contracts\AuditWriter;

/**
 * Writes file and document events to the append-only audit trail.
 *
 * READS ARE THE POINT HERE. Article 5.4 requires every read of another person's personal data to
 * be auditable, and a document store is where that obligation is most easily lost: bytes are
 * usually served by a web server or a CDN that never tells the application anything happened.
 * Routing every read through a redeemable grant is what makes `document.read` a row somebody can
 * find (ADR 0020 §5).
 *
 * Summaries name the act, never the content. The trail is read by operators investigating
 * something else entirely, so it must not become a second, less-guarded index of who holds which
 * documents.

 * ── TAB 29 ────────────────────────────────────────────────────────────────────────────
 *
 * THE INSERT NOW HAPPENS IN ONE PLACE. This class kept its name and its vocabulary — callers
 * still write `$this->audit->record(...)` in the words of their own module — but the row is
 * built by the one implementation of `Modules\Shared\Contracts\AuditWriter`.
 *
 * Ten hand-rolled inserts had already begun to differ, and a missing audit field is invisible:
 * a trail with a gap looks exactly like a trail of a quiet week (ADR 0034 §1).
 */
final class FilesAudit
{
    public function __construct(private readonly AuditWriter $trail) {}

    public function recordFile(?string $actorSubjectId, string $action, string $summary, string $fileUuid): void
    {
        $this->write($actorSubjectId, $action, $summary, 'Files.StoredFile', $fileUuid);
    }

    public function recordDocument(?string $actorSubjectId, string $action, string $summary, string $documentUuid): void
    {
        $this->write($actorSubjectId, $action, $summary, 'Files.Document', $documentUuid);
    }

    /**
     * Somebody opened a document.
     *
     * Recorded against the *version*, not the document: which revision was read is the question
     * asked when a decision is challenged, and "they opened the certificate" does not answer it.
     */
    public function recordRead(?string $actorSubjectId, string $versionUuid, string $purpose): void
    {
        $this->write(
            $actorSubjectId,
            'document.read',
            'Document opened ('.$purpose.')',
            'Files.DocumentVersion',
            $versionUuid,
        );
    }

    private function write(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        string $entityType,
        string $entityId,
    ): void {
        $this->trail->record(
            $actorSubjectId,
            $action,
            $summary,
            $entityType,
            $entityId,
        );
    }
}
