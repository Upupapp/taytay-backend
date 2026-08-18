<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\DocumentVersionView;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\UploadPolicy;
use Modules\Files\Contracts\VerificationStatus;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseRequirementService;
use Modules\Welfare\Application\DocumentRequestService;
use Modules\Welfare\Domain\RequirementApplicability;
use Modules\Welfare\Infrastructure\Eloquent\CaseRequirement;
use Modules\Welfare\Infrastructure\Eloquent\DocumentRequest;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Requirements, documents and the office's requests, for staff (ADR 0020 §6–7).
 *
 * THIS CONTROLLER IS WHERE FILE ACCESS IS AUTHORISED. The Files module publishes no routes,
 * because it cannot answer "may this caller see this document" — only the module owning the
 * record can, and here that means the case's barangay scope. Every method resolves the case
 * first and lets scope decide, then calls the store.
 *
 * FOUR PERMISSIONS, SPLIT WHERE THE CONSEQUENCE CHANGES:
 *
 *  * `document.manage` — record a document. Front-line staff; taking papers is the job.
 *  * `document.verify` — accept or refuse one. Deliberately not the clerk who received it.
 *  * `document.view-sensitive` — open a safeguarding image, beyond knowing it exists.
 *  * `document.share` — issue a copy that leaves the office. Held by nobody yet, on purpose.
 */
final class CaseRequirementController
{
    public function __construct(
        private readonly CaseRequirementService $requirements,
        private readonly DocumentRequestService $requests,
        private readonly DocumentLibrary $library,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);
        $model = $this->caseOrFail($actor, $case);

        $requirements = $this->requirements->forCase($model);

        // Resolved ONCE for the page. Every lookup the projection needs comes from this map.
        $versions = $this->requirements->currentVersionsFor($requirements);

        return ApiResponse::item([
            'requirements' => $requirements
                ->map(fn (CaseRequirement $r): array => $this->projection($r, $versions))->all(),
            // Counted from the same resolved versions rather than by asking again. `outstandingFor()`
            // re-runs the list query and re-resolves every document, which doubled an already
            // per-row cost on the one page where a case's whole document set is rendered.
            'outstanding_count' => $requirements->filter(
                fn (CaseRequirement $r): bool => $this->requirements->outstandingGiven(
                    $r,
                    $versions[(string) $r->uuid] ?? null,
                ),
            )->count(),
            // Published so a client enforces the same limits this server does, rather than a
            // copy that drifts. Both clients check before uploading as a courtesy; this endpoint
            // is the boundary.
            'accepts' => UploadPolicy::toArray(),
        ]);
    }

    /**
     * Copies a programme's requirement template onto the case.
     */
    public function attachTemplate(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentManage);
        $model = $this->caseOrFail($actor, $case);

        $validated = $request->validate([
            'program_id' => ['required', 'string', 'max:64'],
        ]);

        $requirements = $this->requirements->attachTemplate($model, $validated['program_id'], $actor);
        $versions = $this->requirements->currentVersionsFor($requirements);

        return ApiResponse::created([
            'requirements' => $requirements
                ->map(fn (CaseRequirement $r): array => $this->projection($r, $versions))->all(),
        ]);
    }

    /**
     * Records a document against a requirement — appending, never replacing.
     */
    public function recordDocument(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentManage);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        $validated = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', DocumentSource::values())],
            'file' => ['sometimes', 'file'],
            'document_type' => ['sometimes', 'string', 'max:48'],
            'document_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
            'expiry_unknown' => ['sometimes', 'boolean'],
            'replaces_because' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $source = DocumentSource::from($validated['source']);

        /*
         * Classification is decided here, by the module that knows what the document is — the
         * store refuses to guess, because a default of "operational" over a safeguarding
         * photograph would be a silent declassification nobody would see.
         */
        $file = $request->hasFile('file')
            ? $this->library->store($request->file('file'), $this->classificationFor($model), $actor)
            : null;

        $version = $this->requirements->recordDocument($slot, $file?->id, [
            'source' => $source,
            'document_type' => $validated['document_type'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'issued_on' => $validated['issued_on'] ?? null,
            'expires_on' => $validated['expires_on'] ?? null,
            'expiry_unknown' => $validated['expiry_unknown'] ?? false,
            'replaces_because' => $validated['replaces_because'] ?? null,
        ], $actor);

        return ApiResponse::created($this->versionProjection($version));
    }

    /**
     * Every version ever presented against a requirement, including superseded ones.
     */
    public function history(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        if ($slot->document_id === null) {
            return ApiResponse::item(['versions' => []]);
        }

        return ApiResponse::item([
            'versions' => array_map(
                fn (DocumentVersionView $v): array => $this->versionProjection($v),
                $this->library->history((string) $slot->document_id),
            ),
        ]);
    }

    /**
     * A reviewer accepts or refuses the current version.
     */
    public function verify(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentVerify);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:verified,rejected'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $version = $this->requirements->currentVersion($slot);

        if ($version === null) {
            throw ResourceNotFoundException::make('Nothing has been presented against that requirement.');
        }

        return ApiResponse::item($this->versionProjection($this->library->decide(
            $version->id,
            VerificationStatus::from($validated['status']),
            $actor,
            $validated['note'] ?? null,
        )));
    }

    /**
     * Rules a conditional requirement in or out.
     */
    public function decideApplicability(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentVerify);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        $validated = $request->validate([
            'applicability' => ['required', 'string', 'in:applies,does-not-apply'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->projection($this->requirements->decideApplicability(
            $slot,
            RequirementApplicability::from($validated['applicability']),
            $validated['reason'],
            $actor,
        )));
    }

    /**
     * Issues a single-use handle for the current version's file.
     *
     * A HANDLE RATHER THAN A URL, because reading somebody's document is an act this system must
     * be able to refuse and must record (Article 5.4). Object storage never tells the application
     * that a fetch happened, so a durable signed link would make every read invisible.
     */
    public function openDocument(Request $request, ActorContext $actor, string $case, string $requirement, string $version): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        $forSharing = $request->boolean('for_sharing');

        if ($forSharing) {
            $this->authorization->authorize($actor, Permission::DocumentShare);
        }

        /*
         * The version must belong to THIS requirement's slot. Without this the case id in the
         * path would be decoration, and any version uuid would open from any case a caller can
         * reach — the guessed-id path the acceptance criterion is about.
         */
        $found = $this->library->versionWithin($version, $slot->document_id);

        if ($found === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        // Knowing a safeguarding document exists and opening the image are different acts, and
        // the second is a further permission and a further audit entry.
        if ($this->library->classificationOf($found->id) === FileClassification::Sensitive) {
            $this->authorization->authorize($actor, Permission::DocumentViewSensitive);
        }

        $grant = $this->library->issueAccess($found->id, $actor, $forSharing ? 'share' : 'view', $forSharing);

        return ApiResponse::item([
            'version_id' => $found->id,
            'file_name' => $found->file?->name,
            'mime_type' => $found->file?->mimeType,
            // Opaque, single-use, issued to this account, dead in two minutes. Never a durable
            // public URL — the private disk has no public base URL to build one from.
            'handle' => $grant['handle'],
            'expires_at' => $grant['expires_at'],
            'redacted_for_sharing' => $grant['redacted_for_sharing'],
            'warning' => $forSharing
                ? 'This copy is marked as leaving the office. The disclosure is recorded against your account.'
                : 'Opening this document is recorded against your account.',
        ]);
    }

    // ── the office asking for something ───────────────────────────────────────────────

    public function listRequests(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::RequestView);
        $model = $this->caseOrFail($actor, $case);

        $requests = $this->requests->forCase($model);

        // The requirement uuid for every request on the page, in one query. Asked per row it was
        // one unconditional query each — 5 for one request, 10 for six (ADR 0042 §9).
        $requirementUuids = CaseRequirement::query()
            ->whereKey($requests->pluck('welfare_case_requirement_id')->filter()->unique()->values()->all())
            ->pluck('uuid', 'id')
            ->map(strval(...))
            ->all();

        return ApiResponse::item([
            'requests' => $requests
                ->map(fn (DocumentRequest $r): array => $this->requestProjection($r, $requirementUuids))->all(),
        ]);
    }

    public function requestDocument(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentManage);
        $model = $this->caseOrFail($actor, $case);
        $slot = $this->requirementOrFail($model, $requirement);

        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:in-person,sms,phone-call,barangay-relay'],
            'message' => ['required', 'string', 'max:500'],
            'needed_by' => ['sometimes', 'nullable', 'date'],
        ]);

        return ApiResponse::created($this->requestProjection(
            $this->requests->open($model, $slot, $validated, $actor),
        ));
    }

    public function withdrawRequest(Request $request, ActorContext $actor, string $case, string $documentRequest): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::DocumentManage);
        $model = $this->caseOrFail($actor, $case);

        /** @var DocumentRequest|null $row */
        $row = DocumentRequest::query()
            ->where('uuid', $documentRequest)
            ->where('welfare_case_id', $model->id)
            ->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That request was not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::item($this->requestProjection(
            $this->requests->withdraw($row, $validated['reason'], $actor),
        ));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, DocumentVersionView>|null  $versions  resolved for the whole page, or
     *                                                             null when rendering a single row
     * @return array<string, mixed>
     */
    private function projection(CaseRequirement $requirement, ?array $versions = null): array
    {
        /*
         * `null` means no map was supplied — a single-row caller, which may do its own lookup.
         * An absent KEY inside a supplied map means the page was asked and this requirement has
         * no live document. Falling back for that would pay for the batch and do the work anyway
         * (ADR 0042 §1).
         */
        $version = $versions === null
            ? $this->requirements->currentVersion($requirement)
            : ($versions[(string) $requirement->uuid] ?? null);

        return [
            'id' => $requirement->uuid,
            'code' => $requirement->requirement_code,
            'label' => $requirement->label,
            'obligation' => $requirement->obligation->value,
            'template_version' => $requirement->template_version,
            'citizen_instructions' => $requirement->citizen_instructions,
            'applicability' => $requirement->applicability->value,
            'applicability_reason' => $requirement->applicability_reason,
            // Derived from the version already in hand. Asking `isSatisfied()`/`isOutstanding()`
            // here would resolve it twice more for a value this line already holds.
            'is_satisfied' => $this->requirements->satisfiedBy($version),
            'is_outstanding' => $this->requirements->outstandingGiven($requirement, $version),
            'current_version' => $version === null ? null : $this->versionProjection($version),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionProjection(DocumentVersionView $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'source' => $version->source->value,
            // Already masked by Files, so no client can render the stored four characters as
            // though they were a whole number, and the full number was never kept (ADR 0020 §4).
            'document_number' => $version->documentNumber,
            'issued_on' => $version->issuedOn?->toDateString(),
            'expires_on' => $version->expiresOn?->toDateString(),
            'validity' => $version->validity->value,
            'verification_status' => $version->verificationStatus->value,
            'verification_note' => $version->verificationNote,
            'verified_at' => $version->verifiedAt?->toIso8601ZuluString(),
            'received_at' => $version->receivedAt?->toIso8601ZuluString(),
            'superseded_at' => $version->supersededAt?->toIso8601ZuluString(),
            'superseded_reason' => $version->supersededReason,
            'file' => $version->file === null ? null : [
                'name' => $version->file->name,
                'mime_type' => $version->file->mimeType,
                'byte_size' => $version->file->byteSize,
                'page_count' => $version->file->pageCount,
                // So a reviewer knows whether they are opening something unexamined, rather
                // than finding out from an incident report.
                'scan_status' => $version->file->scanStatus->value,
                'is_available' => $version->file->isAvailable,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, string>|null  $requirementUuids  resolved for the whole page, or null
     *                                                     when rendering a single request
     */
    private function requestProjection(DocumentRequest $request, ?array $requirementUuids = null): array
    {
        return [
            'id' => $request->uuid,
            'requirement_id' => $requirementUuids === null
                ? (string) CaseRequirement::query()
                    ->whereKey($request->welfare_case_requirement_id)->value('uuid')
                : ($requirementUuids[$request->welfare_case_requirement_id] ?? ''),
            'state' => $request->state,
            'channel' => $request->channel,
            'message' => $request->message,
            'needed_by' => $request->needed_by?->toDateString(),
            'requested_at' => $request->requested_at?->toIso8601ZuluString(),
            'closed_at' => $request->closed_at?->toIso8601ZuluString(),
            'withdrawn_reason' => $request->withdrawn_reason,
            // Named for its subject: a case task is overdue when staff are late, this when the
            // applicant is. A queue mixing them tells a supervisor nothing.
            'is_applicant_overdue' => $request->isApplicantOverdue(),
        ];
    }

    /**
     * Sensitive when the case is restricted work; personal otherwise.
     *
     * Decided from the case rather than asked of the uploader, because a clerk uploading a
     * medical certificate onto a VAWC case should not have to remember to classify it, and the
     * consequence of them forgetting is exactly the disclosure this is meant to prevent.
     */
    private function classificationFor(WelfareCase $case): FileClassification
    {
        return $case->isRestricted() ? FileClassification::Sensitive : FileClassification::Personal;
    }

    private function caseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That case was not found.');
        }

        // Out of scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay($actor, $case->barangay_id, 'That case was not found.');

        return $case;
    }

    private function requirementOrFail(WelfareCase $case, string $uuid): CaseRequirement
    {
        /** @var CaseRequirement|null $requirement */
        $requirement = CaseRequirement::query()
            ->where('uuid', $uuid)
            ->where('welfare_case_id', $case->id)
            ->first();

        if ($requirement === null) {
            throw ResourceNotFoundException::make('That requirement was not found.');
        }

        return $requirement;
    }
}
