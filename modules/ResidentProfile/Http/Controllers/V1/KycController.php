<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Files\Application\DocumentLibrary;
use Modules\Files\Contracts\DocumentSource;
use Modules\Files\Contracts\DocumentVersionView;
use Modules\Files\Contracts\FileClassification;
use Modules\ResidentProfile\Application\KycCaseService;
use Modules\ResidentProfile\Application\ResidentMatcher;
use Modules\ResidentProfile\Contracts\KycStatus;
use Modules\ResidentProfile\Domain\KycDocumentType;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMatchCandidate;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\BarangayCodes;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * KYC: the applicant's own case, and the reviewer's queue.
 *
 * Two audiences, one service. The citizen routes resolve the case from the authenticated
 * account — there is no identifier to tamper with — and the staff routes require an
 * explicit permission and return a wider projection. Same lifecycle, same rules, different
 * authorization (ADR 0002).
 */
final class KycController
{
    public function __construct(
        private readonly KycCaseService $cases,
        private readonly ResidentMatcher $matcher,
        private readonly AuthorizationService $authorization,
        /**
         * Documents are `Files`' job, not this module's (F28).
         *
         * No `kyc_case_documents` table was added and none should be: `Files` already owns
         * slots, versioning, supersession, scan status and retention, and a second document
         * store would be a second answer to "what did this person show us".
         */
        private readonly DocumentLibrary $library,
        private readonly BarangayCodes $barangayCodes,
    ) {}

    // ── applicant ─────────────────────────────────────────────────────────────────────

    /**
     * Opens or returns the caller's KYC case.
     *
     * Idempotent by account: tapping "register" twice returns the same case. It does NOT
     * create a resident — nothing here touches the canonical registry.
     */
    public function register(Request $request, ActorContext $actor): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:96'],
            'middle_name' => ['nullable', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'birth_date' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', 'in:female,male'],
            /*
             * EITHER, AND A CLIENT SHOULD SEND THE CODE.
             *
             * `barangay_id` is the auto-increment primary key, which Article 4
             * says must never be exposed to a client — and until `GET barangays`
             * existed, no route published any barangay identifier at all, so an
             * applicant was required to supply one they could not obtain. That
             * made this endpoint, and with it the whole Verified tier,
             * unreachable from any client.
             *
             * The directory publishes the UUID and the stable `code` slug.
             * `barangay_code` is accepted here so a resident can send back what
             * they were given, and the integer stays accepted because the admin
             * console sends it today. Expand now, contract when that console
             * moves — Article 6.
             */
            'barangay_id' => ['required_without:barangay_code', 'integer', 'exists:barangays,id'],
            'barangay_code' => ['required_without:barangay_id', 'string', 'max:64', 'exists:barangays,code'],
            'street_address' => ['required', 'string', 'max:191'],
        ]);

        $case = $this->cases->register(
            (string) $actor->subjectId,
            $this->withResolvedBarangay($validated),
        );

        return ApiResponse::item($this->applicantProjection($case), 201);
    }

    public function showOwn(Request $request, ActorContext $actor): JsonResponse
    {
        return ApiResponse::item($this->applicantProjection($this->ownCaseOrFail($actor)));
    }

    public function submitOwn(Request $request, ActorContext $actor): JsonResponse
    {
        $case = $this->ownCaseOrFail($actor);

        if (! $case->status->isEditableByApplicant()) {
            throw new ApiException(ErrorCode::Conflict, 'This application is not awaiting your input.');
        }

        return ApiResponse::item(
            $this->applicantProjection($this->cases->submit($case, (string) $actor->subjectId)),
        );
    }

    // ── the applicant's own documents (F28) ───────────────────────────────────────────

    /**
     * Attaches a document to the caller's own case.
     *
     * ---
     *
     * **The gap this closes.** `POST me/kyc/submit` takes no body and nothing attached a file to a
     * KYC case, so a claim could be opened and submitted but the applicant could not send the
     * document that settles a case the registry match does not. The only upload route in the
     * contract belonged to a `Welfare` assistance case — a different module and lifecycle — and
     * filing an identity document there would attach somebody's ID to an application they never
     * made.
     *
     * **Only while the case is the applicant's to change.** A document arriving after submission
     * would change what a reviewer already looked at, without the reviewer knowing. `draft` and
     * `needs-more-information` are exactly the two states where the office is waiting on them.
     *
     * **Classification is decided here, never asked of the applicant.** `Personal`, which is what
     * this system's own vocabulary says an identity document is. Somebody photographing their
     * PhilID should not have to know what "sensitive" means for it to be held correctly — and
     * they must not be able to under-classify it either.
     */
    public function uploadDocument(Request $request, ActorContext $actor): JsonResponse
    {
        $case = $this->editableOwnCaseOrFail($actor);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'type' => ['required', 'string', 'in:'.implode(',', KycDocumentType::values())],
        ]);

        $type = KycDocumentType::from($validated['type']);

        $file = $this->library->store(
            $request->file('file'),
            FileClassification::Personal,
            $actor,
        );

        $version = $this->library->append(
            $this->library->slotFor(KycDocumentType::OWNER_TYPE, (string) $case->uuid, $type->value),
            $file->id,
            [
                // From the route, not the body. A client claiming `scanned` would be
                // manufacturing evidence that a clerk imaged the paper at a counter.
                'source' => DocumentSource::Uploaded,
            ],
            $actor,
        );

        return ApiResponse::created($this->documentProjection($type, $version));
    }

    /**
     * What the applicant has attached, and nothing about how it was judged.
     */
    public function listDocuments(Request $request, ActorContext $actor): JsonResponse
    {
        $case = $this->ownCaseOrFail($actor);

        return ApiResponse::item(['documents' => $this->documentsFor($case)]);
    }

    /**
     * Opens something the applicant supplied themselves.
     */
    public function openDocument(Request $request, ActorContext $actor, string $type): JsonResponse
    {
        $case = $this->ownCaseOrFail($actor);
        $documentType = KycDocumentType::tryFrom($type);

        if ($documentType === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        $version = $this->library->currentVersion(
            $this->library->slotFor(KycDocumentType::OWNER_TYPE, (string) $case->uuid, $documentType->value),
        );

        if ($version === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        /*
         * Resolved from the caller's own case, so there is no document identifier in this request
         * for anybody to substitute. An applicant may open what they supplied; never a copy for
         * onward sharing, because an outward disclosure is the office's decision to make and
         * record (ADR 0020 §7).
         */
        $grant = $this->library->issueAccess($version->id, $actor, 'view', false);

        return ApiResponse::item([
            'handle' => $grant['handle'],
            'expires_at' => $grant['expires_at'],
        ]);
    }

    // ── reviewer ──────────────────────────────────────────────────────────────────────

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $pagination = PaginationParams::fromRequest($request);
        $status = $request->query('status');

        // Scope at the query, not after fetching: filtering in PHP still pulls other
        // barangays' rows from the database, and the pagination total would count them.
        $query = $this->authorization->scopeToBarangays(
            $actor,
            KycCase::query()->orderBy('submitted_at'),
            'claimed_barangay_id',
        );

        // `assigned-cases` is the narrowest scope: the barangay bound AND ownership.
        if ($actor->scope->requiresCaseAssignment()) {
            $query->where('assigned_to', $actor->subjectId);
        }

        if (is_string($status) && KycStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();

        /*
         * The undecided-candidate count as a subquery on the page's own select, rather than a
         * COUNT per row. That count runs whether or not a case has candidates, so this was one
         * unconditional extra query per row — measured 5 for one case and 10 for six (ADR 0042 §9).
         */
        $cases = $query
            ->withCount(['candidates as undecided_candidates' => fn (Builder $q) => $q->where('decision', 'undecided')])
            ->forPage($pagination->page, $pagination->perPage)
            ->get();

        return ApiResponse::page(
            new Page($cases->all(), $total, $pagination),
            fn (KycCase $case): array => $this->reviewerProjection($case),
        );
    }

    public function show(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $model = $this->caseOrFail($actor, $case);

        return ApiResponse::item(
            $this->reviewerProjection($model)
                + ['candidates' => $this->candidateProjection($model)]
                // F28. Without this the applicant's documents are a write nobody reads, which is
                // worse than not accepting them: the resident believes the office has their ID.
                + ['documents' => $this->reviewerDocumentsFor($model)],
        );
    }

    /**
     * Re-runs deterministic matching. Idempotent — decisions already made are preserved.
     */
    public function rescreen(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $model = $this->caseOrFail($actor, $case);
        $this->matcher->screen($model);

        return ApiResponse::item(['candidates' => $this->candidateProjection($model->refresh())]);
    }

    /**
     * The reviewer rules on one possible duplicate.
     *
     * Every candidate must be decided before the case can be approved, so this is the step
     * that actually prevents duplicate residents.
     */
    public function decideCandidate(Request $request, ActorContext $actor, string $case, string $candidate): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:same-person,different-person'],
        ]);

        $model = $this->caseOrFail($actor, $case);

        /** @var ResidentMatchCandidate|null $row */
        $row = $model->candidates()->where('uuid', $candidate)->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That candidate was not found.');
        }

        $row->forceFill([
            'decision' => $validated['decision'],
            'decided_by' => $actor->subjectId,
            'decided_at' => now(),
        ])->save();

        return ApiResponse::item(['candidates' => $this->candidateProjection($model->refresh())]);
    }

    /**
     * Approve — the only path to a canonical resident record.
     */
    public function approve(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycApprove);

        $validated = $request->validate([
            'link_resident_id' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->cases->approve(
            $this->caseOrFail($actor, $case),
            $actor,
            $validated['link_resident_id'] ?? null,
            $validated['message'] ?? null,
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    public function reject(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycApprove);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $model = $this->cases->reject(
            $this->caseOrFail($actor, $case),
            $actor,
            $validated['reason'],
            $validated['message'] ?? null,
        );

        return ApiResponse::item($this->reviewerProjection($model));
    }

    public function requestMoreInformation(Request $request, ActorContext $actor, string $case): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $validated = $request->validate(['message' => ['required', 'string', 'max:255']]);

        $model = $this->cases->requestMoreInformation($this->caseOrFail($actor, $case), $actor, $validated['message']);

        return ApiResponse::item($this->reviewerProjection($model));
    }

    /**
     * A reviewer opens a document the applicant attached (F28).
     *
     * Scoped to the case in the path: the slot is derived from that case's uuid, so a version
     * belonging to somebody else's case cannot be reached by naming it. There is no version
     * identifier in this request at all.
     *
     * `KycReview` like every other reviewer action here, and the read is recorded — opening a
     * resident's identity document is exactly the access an audit trail exists for.
     */
    public function openCaseDocument(Request $request, ActorContext $actor, string $case, string $type): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::KycReview);

        $model = $this->caseOrFail($actor, $case);
        $documentType = KycDocumentType::tryFrom($type);

        if ($documentType === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        $version = $this->library->currentVersion(
            $this->library->slotFor(KycDocumentType::OWNER_TYPE, (string) $model->uuid, $documentType->value),
        );

        if ($version === null) {
            throw ResourceNotFoundException::make('That document was not found.');
        }

        $grant = $this->library->issueAccess($version->id, $actor, 'kyc-review', false);

        return ApiResponse::item([
            'handle' => $grant['handle'],
            'expires_at' => $grant['expires_at'],
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * What the applicant sees.
     *
     * Their own claims, the status, and the message a reviewer chose to send them.
     * Deliberately absent: match candidates, internal transition reasons, reviewer
     * identities and any other resident's details — an applicant must never learn that
     * their record resembles somebody else's (visibility matrix §3).
     *
     * @return array<string, mixed>
     */
    private function applicantProjection(KycCase $case): array
    {
        return [
            'id' => $case->uuid,
            'status' => $case->status->value,
            'can_edit' => $case->status->isEditableByApplicant(),
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'message' => $case->applicant_message,
            'claimed' => [
                'first_name' => $case->claimed_first_name,
                'middle_name' => $case->claimed_middle_name,
                'last_name' => $case->claimed_last_name,
                'suffix' => $case->claimed_suffix,
                'birth_date' => $case->claimed_birth_date?->toDateString(),
            ],
            'resident_id' => $case->resolved_resident_id,
            'documents' => $this->documentsFor($case),
        ];
    }

    /**
     * What the applicant attached (F28).
     *
     * ---
     *
     * **Nothing about how it was judged.** `verificationStatus`, `verificationNote` and
     * `verifiedAt` are on the version and are deliberately not here: a reviewer's remark on a
     * document is deliberation, and this app shows an applicant the decision on their case rather
     * than the working that led to it (visibility matrix §1). The same rule the requirement
     * projection in `Welfare` already follows.
     *
     * Every type is listed whether or not something was attached, so a client can render the two
     * slots without knowing the vocabulary — and an applicant can see that they have sent nothing
     * yet, which is the state most likely to be misread as "sent".
     *
     * @return list<array<string, mixed>>
     */
    private function documentsFor(KycCase $case): array
    {
        $documents = [];

        foreach (KycDocumentType::cases() as $type) {
            $documents[] = $this->documentProjection(
                $type,
                $this->library->currentVersion(
                    $this->library->slotFor(KycDocumentType::OWNER_TYPE, (string) $case->uuid, $type->value),
                ),
            );
        }

        return $documents;
    }

    /**
     * The same documents, with what the applicant is not shown (F28).
     *
     * A reviewer needs the verification status and the note, because those are their own working
     * notes and their colleague's. The applicant sees neither — see [documentsFor].
     *
     * @return list<array<string, mixed>>
     */
    private function reviewerDocumentsFor(KycCase $case): array
    {
        $documents = [];

        foreach (KycDocumentType::cases() as $type) {
            $version = $this->library->currentVersion(
                $this->library->slotFor(KycDocumentType::OWNER_TYPE, (string) $case->uuid, $type->value),
            );

            $documents[] = $this->documentProjection($type, $version) + [
                'version' => $version?->version,
                'verification_status' => $version?->verificationStatus->value,
                'verification_note' => $version?->verificationNote,
                'scan_status' => $version?->file?->scanStatus->value,
            ];
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentProjection(KycDocumentType $type, ?DocumentVersionView $version): array
    {
        return [
            'type' => $type->value,
            'attached' => $version !== null,
            'received_at' => $version?->receivedAt?->toIso8601ZuluString(),
            // So a client can say "your ID is still being checked for viruses" rather than
            // offering a file that will not open.
            'is_available' => $version?->file?->isAvailable ?? false,
            'file_name' => $version?->file?->name,
        ];
    }

    /**
     * The caller's own case, and only while it is theirs to change.
     */
    private function editableOwnCaseOrFail(ActorContext $actor): KycCase
    {
        $case = $this->ownCaseOrFail($actor);

        if (! $case->status->isEditableByApplicant()) {
            throw new ApiException(ErrorCode::Conflict, 'This application is not awaiting your input.');
        }

        return $case;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewerProjection(KycCase $case): array
    {
        return [
            'id' => $case->uuid,
            'status' => $case->status->value,
            'account_id' => $case->account_id,
            'claimed_name' => $case->claimedFullName(),
            'claimed_birth_date' => $case->claimed_birth_date?->toDateString(),
            'claimed_barangay_id' => $case->claimed_barangay_id,
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'reviewed_at' => $case->reviewed_at?->toIso8601ZuluString(),
            'resident_id' => $case->resolved_resident_id,
            /*
             * From the list query's subquery when it ran. A single-case caller has no such
             * attribute and asks directly — one query for one row, which is not an N+1.
             */
            'undecided_candidates' => $case->undecided_candidates
                ?? $case->candidates()->where('decision', 'undecided')->count(),
        ];
    }

    /**
     * Candidates carry only enough of the existing resident for a reviewer to tell whether
     * it is the same person — name, birth date, barangay. Not their income, sectors or
     * case history: deciding a duplicate does not require reading somebody's welfare file.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateProjection(KycCase $case): array
    {
        return $case->candidates()->get()->map(function (ResidentMatchCandidate $candidate): array {
            /** @var Resident|null $resident */
            $resident = Resident::query()->find($candidate->resident_id);

            return [
                'id' => $candidate->uuid,
                'rule' => $candidate->rule,
                'confidence' => $candidate->confidence,
                'decision' => $candidate->decision,
                'resident' => $resident === null ? null : [
                    'id' => $resident->uuid,
                    'name' => $resident->fullName(),
                    'birth_date' => $resident->birth_date?->toDateString(),
                    'barangay_id' => $resident->barangay_id,
                    'barangay_code' => $this->barangayCodes->codeFor($resident->barangay_id),
                    'verification_tier' => $resident->verification_tier->value,
                ],
            ];
        })->all();
    }

    /**
     * Turns a client-facing barangay `code` into the internal key, and drops it.
     *
     * The rest of the module stores `barangay_id`, so the translation happens
     * here at the adapter rather than being pushed inward — a controller may
     * shape a command, and this is that. `barangay_code` never reaches the
     * application service, so there is no second identifier for the domain to
     * disagree with itself about.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withResolvedBarangay(array $validated): array
    {
        $code = $validated['barangay_code'] ?? null;
        unset($validated['barangay_code']);

        if ($code === null) {
            return $validated;
        }

        /*
         * Through `BarangayCodes` rather than a query of its own.
         *
         * `ResidentController` needs the same translation, and two hand-rolled lookups of the same
         * table are two places for the rule to drift. The map is memoised per request, so this
         * costs no query at all where a projection has already read it.
         */
        $validated['barangay_id'] = $this->barangayCodes->idForCode($code);

        return $validated;
    }

    private function ownCaseOrFail(ActorContext $actor): KycCase
    {
        /** @var KycCase|null $case */
        $case = KycCase::query()
            ->where('account_id', $actor->subjectId)
            ->orderByDesc('id')
            ->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('You have no application in progress.');
        }

        return $case;
    }

    /**
     * Loads a case and enforces scope on it.
     *
     * Every staff route goes through here — index, show, rescreen, candidate decisions,
     * approve, reject, request-information — so there is no verb a caller can switch to in
     * order to reach a case their scope excludes. Authorization at the *loader* rather than
     * per-action is what makes that guarantee hold as endpoints are added.
     *
     * Out-of-scope returns NOT FOUND, never FORBIDDEN: "exists but not yours" is enough to
     * enumerate applicants one guessed id at a time (OWASP API1).
     */
    private function caseOrFail(ActorContext $actor, string $uuid): KycCase
    {
        /** @var KycCase|null $case */
        $case = KycCase::query()->where('uuid', $uuid)->first();

        if ($case === null) {
            throw ResourceNotFoundException::make('That application was not found.');
        }

        $this->authorization->authorizeBarangay(
            $actor,
            $case->claimed_barangay_id === null ? null : (int) $case->claimed_barangay_id,
            'That application was not found.',
        );

        if ($actor->scope->requiresCaseAssignment() && $case->assigned_to !== $actor->subjectId) {
            throw ResourceNotFoundException::make('That application was not found.');
        }

        return $case;
    }
}
