<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\DocumentVersionView;
use Modules\Files\Contracts\FileClassification;
use Modules\Files\Contracts\UploadPolicy;
use Modules\Identity\Application\AccountDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\CaseRequirementService;
use Modules\Welfare\Infrastructure\Eloquent\CaseRequirement;
use Modules\Welfare\Infrastructure\Eloquent\DocumentRequest;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * An applicant supplying what their own case still needs (ADR 0020 §8).
 *
 * THE ACCEPTANCE CRITERION THIS CLASS EXISTS FOR: a citizen may upload only into their own
 * permitted requirement slots.
 *
 * That is held by the lookup, not by a check afterwards. Every method resolves the resident from
 * the token, then finds the case *by that resident*, then finds the requirement *by that case*.
 * Another applicant's case id resolves to nothing rather than to a 403 that confirms it exists,
 * and there is no identifier anywhere in the contract that widens what the caller can reach.
 *
 * WHAT AN APPLICANT MAY NOT DO HERE, all deliberate:
 *
 *  * **Verify.** Uploading is presenting, not accepting. Every citizen upload lands `pending`.
 *  * **Rule a conditional requirement in or out.** That is the step that can waive a safeguard.
 *  * **Record an `encoded` or `external-verification` document.** Those two assert that a member
 *    of staff saw or confirmed something, and an applicant cannot make that claim about
 *    themselves. A citizen upload is always `uploaded`, taken from the route rather than the
 *    body — the same rule as the intake source in ADR 0017.
 *  * **See the office's handling.** No reviewer, no internal note, no scan status.
 */
final class MyRequirementController
{
    public function __construct(
        private readonly CaseRequirementService $requirements,
        private readonly DocumentLibrary $library,
        private readonly AccountDirectory $accounts,
    ) {}

    /**
     * What this applicant still owes on their own case.
     */
    public function index(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $model = $this->ownCaseOrFail($actor, $case);

        $requirements = $this->requirements->forCase($model);

        // Resolved once for the page. This one is opened on a phone, over mobile data, by the
        // applicant checking whether the office has accepted their papers yet.
        $versions = $this->requirements->currentVersionsFor($requirements);

        return ApiResponse::item([
            'requirements' => $requirements
                ->map(fn (CaseRequirement $r): array => $this->projection($r, $versions))->all(),
            'requests' => DocumentRequest::query()
                ->where('welfare_case_id', $model->id)
                ->where('state', 'open')
                ->orderByDesc('requested_at')
                ->get()
                ->map(fn (DocumentRequest $r): array => [
                    // What they were actually told, in the words used — so the app can show the
                    // same sentence a clerk said at the counter rather than a paraphrase.
                    'message' => $r->message,
                    'needed_by' => $r->needed_by?->toDateString(),
                    'requested_at' => $r->requested_at?->toIso8601ZuluString(),
                ])->all(),
            'accepts' => UploadPolicy::toArray(),
        ]);
    }

    /**
     * The applicant supplies a document for one of their own requirements.
     */
    public function upload(Request $request, ActorContext $actor, string $case, string $requirement): JsonResponse
    {
        $model = $this->ownCaseOrFail($actor, $case);
        $slot = $this->ownRequirementOrFail($model, $requirement);

        $request->validate([
            'file' => ['required', 'file'],
            'replaces_because' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /*
         * Classification is decided from the case, never asked of the applicant. Somebody
         * uploading a medical certificate onto restricted work should not have to know what
         * "sensitive" means for it to be held correctly.
         */
        $file = $this->library->store(
            $request->file('file'),
            $model->isRestricted() ? FileClassification::Sensitive : FileClassification::Personal,
            $actor,
        );

        $version = $this->requirements->recordDocument($slot, $file->id, [
            // From the route, not the body. A client claiming `scanned` would be manufacturing
            // evidence that a clerk imaged the paper at the counter.
            'source' => DocumentSource::Uploaded,
            'replaces_because' => $request->input('replaces_because'),
        ], $actor);

        return ApiResponse::created($this->versionProjection($version));
    }

    /**
     * The applicant opens something they themselves supplied.
     */
    public function open(Request $request, ActorContext $actor, string $case, string $requirement, string $version): JsonResponse
    {
        $model = $this->ownCaseOrFail($actor, $case);
        $slot = $this->ownRequirementOrFail($model, $requirement);

        // The version must sit in THIS requirement's slot on THIS applicant's case. Without the
        // slot check the case id would be decoration and any version uuid would open.
        $found = $this->library->versionWithin($version, $slot->document_id);

        if ($found === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        // An applicant may open what they supplied, never a copy for onward sharing — an outward
        // disclosure is the office's decision to make and record (ADR 0020 §7).
        $grant = $this->library->issueAccess($found->id, $actor, 'view', false);

        return ApiResponse::item([
            'handle' => $grant['handle'],
            'expires_at' => $grant['expires_at'],
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * Additively built, like every other citizen view here (ADR 0016 §5).
     *
     * Absent by construction: the internal reviewer, the verification note when a document is
     * still pending, the scan status, the applicability reason, and the fact that a conditional
     * requirement was ruled out at all — an applicant told "you did not need that after all"
     * mid-case reads as the office changing its mind about them.
     *
     * @param  array<string, DocumentVersionView>|null  $versions  resolved for the whole page, or
     *                                                             null when rendering a single row
     * @return array<string, mixed>
     */
    private function projection(CaseRequirement $requirement, ?array $versions = null): array
    {
        $version = $versions === null
            ? $this->requirements->currentVersion($requirement)
            : ($versions[(string) $requirement->uuid] ?? null);

        // From the version already in hand — `isSatisfied()` would resolve the same document again.
        $satisfied = $this->requirements->satisfiedBy($version);

        return [
            'id' => $requirement->uuid,
            'label' => $requirement->label,
            'instructions' => $requirement->citizen_instructions,
            'is_required' => $requirement->obligation->value !== 'optional',
            'is_provided' => $version !== null,
            'is_accepted' => $satisfied,
            // Shown only once somebody has actually decided, so a pending document does not
            // read as a refusal while it waits its turn.
            'status_message' => $this->statusMessage($version, $satisfied),
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
            'submitted_at' => $version->receivedAt?->toIso8601ZuluString(),
            'file_name' => $version->file?->name,
            'byte_size' => $version->file?->byteSize,
            // The note is shown ONLY on a rejection, where it is the instruction for what to
            // bring instead. On an acceptance it is an internal remark.
            'message' => $version->verificationStatus->requiresNote() ? $version->verificationNote : null,
        ];
    }

    private function statusMessage(?DocumentVersionView $version, bool $satisfied): ?string
    {
        if ($version === null) {
            return 'Not yet provided.';
        }

        if ($satisfied) {
            return 'Received and accepted.';
        }

        return match ($version->verificationStatus->value) {
            'rejected' => 'This needs to be provided again.',
            default => 'Received. Waiting to be checked.',
        };
    }

    private function ownCaseOrFail(ActorContext $actor, string $uuid): WelfareCase
    {
        $residentId = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        if ($residentId === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()
            ->where('uuid', $uuid)
            // Ownership is part of the lookup, not a check after it.
            ->where('resident_id', $residentId)
            ->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That request was not found.');
        }

        return $case;
    }

    private function ownRequirementOrFail(WelfareCase $case, string $uuid): CaseRequirement
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
