<?php

declare(strict_types=1);

namespace Modules\Files\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shared\Application\RequestContext;

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
 */
final class FilesAudit
{
    public function __construct(private readonly RequestContext $requestContext) {}

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
        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            'actor_label' => null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            'created_at' => now(),
        ]);
    }
}
