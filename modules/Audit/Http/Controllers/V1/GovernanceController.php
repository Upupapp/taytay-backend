<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Audit\Application\GovernanceRegistry;
use Modules\Audit\Application\RetentionPolicy;
use Modules\Audit\Infrastructure\Eloquent\ConsentRecord;
use Modules\Audit\Infrastructure\Eloquent\LegalHold;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * Privacy notices, consent and retention (ADR 0034 §4–§6).
 *
 * TWO AUDIENCES. A resident reads the current notice, acknowledges it, and manages their own
 * consents — all scoped to the token, with no identifier to tamper with. The DPO publishes
 * notices, reads the retention schedule and places legal holds.
 *
 * There is deliberately **no endpoint that grants consent on somebody else's behalf**. A staff
 * member recording that a resident consented is a staff member asserting something only the
 * resident can assert, and a consent record created that way is evidence of nothing.
 */
final class GovernanceController
{
    public function __construct(
        private readonly GovernanceRegistry $governance,
        private readonly RetentionPolicy $retention,
        private readonly AuthorizationService $authorization,
    ) {}

    // ── citizen ───────────────────────────────────────────────────────────────────────

    /**
     * The notice in force, and whether this person has seen it.
     *
     * PUBLIC. A privacy notice that required an account to read would be one a person could not
     * consult before deciding whether to create an account.
     */
    public function currentNotice(Request $request, ActorContext $actor): JsonResponse
    {
        $notice = $this->governance->currentNotice();

        return ApiResponse::item([
            'notice' => $notice === null ? null : [
                'version' => $notice->version,
                'title' => $notice->title,
                'summary' => $notice->summary,
                // A pointer, not the text: the notice is a published document maintained by the
                // DPO, and duplicating its wording here creates a second version to keep in step.
                'document_url' => $notice->document_url,
                'effective_from' => $notice->effective_from?->toIso8601ZuluString(),
            ],
            'acknowledged' => $actor->subjectId === null
                ? false
                : $this->governance->hasAcknowledgedCurrent((string) $actor->subjectId),
            /*
             * The bases, published. A resident is entitled to know that most of what this office
             * does with their data is not something they were asked to agree to — and an interface
             * that implied otherwise would be the misrepresentation ADR 0034 §4 is about.
             */
            'legal_bases' => (array) config('privacy.legal_bases', []),
            'consent_purposes' => $this->governance->consentPurposes(),
        ]);
    }

    public function acknowledge(Request $request, ActorContext $actor): JsonResponse
    {
        $acknowledgement = $this->governance->acknowledge((string) $actor->subjectId, $actor);

        return ApiResponse::item([
            'acknowledged_at' => $acknowledgement?->acknowledged_at?->toIso8601ZuluString(),
        ]);
    }

    public function myConsents(Request $request, ActorContext $actor): JsonResponse
    {
        $pagination = PaginationParams::fromRequest($request);
        // Scoped at the query to the token's subject. There is no identifier in this contract.
        $query = $this->governance->consentsFor((string) $actor->subjectId);

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ConsentRecord $record): array => $this->consentProjection($record),
        );
    }

    public function grantConsent(Request $request, ActorContext $actor): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', 'string', 'max:64'],
            'evidence' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::created($this->consentProjection($this->governance->grant(
            (string) $actor->subjectId,
            $validated['purpose'],
            $actor,
            evidence: $validated['evidence'] ?? null,
        )));
    }

    public function withdrawConsent(Request $request, ActorContext $actor, string $purpose): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->consentProjection($this->governance->withdraw(
            (string) $actor->subjectId,
            $purpose,
            $actor,
            $validated['reason'] ?? null,
        )));
    }

    // ── the DPO ───────────────────────────────────────────────────────────────────────

    public function publishNotice(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::PrivacyManage);

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:24'],
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:1000'],
            'document_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
        ]);

        $notice = $this->governance->publishNotice($validated, $actor);

        return ApiResponse::created([
            'version' => $notice->version,
            'title' => $notice->title,
            'effective_from' => $notice->effective_from?->toIso8601ZuluString(),
        ]);
    }

    /**
     * The retention schedule, as the DPO reads it.
     */
    public function retentionSchedule(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::PrivacyManage);

        /*
         * Served from the running system rather than from a document, so "is this approved?" is
         * answered by the thing that would act on the answer.
         */
        return ApiResponse::item($this->retention->schedule());
    }

    public function holds(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::PrivacyManage);

        $pagination = PaginationParams::fromRequest($request);
        $query = $this->governance->holds();

        if ($request->boolean('active')) {
            $query->whereNull('lifted_at');
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (LegalHold $hold): array => $this->holdProjection($hold),
        );
    }

    public function placeHold(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::PrivacyManage);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'max:64'],
            'entity_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:96'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::created($this->holdProjection($this->governance->placeHold(
            $validated['entity_type'],
            $validated['entity_id'] ?? null,
            $validated['reference'],
            $validated['reason'],
            $actor,
            $validated['subject_id'] ?? null,
        )));
    }

    public function liftHold(Request $request, ActorContext $actor, string $hold): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::PrivacyManage);

        /** @var LegalHold|null $model */
        $model = LegalHold::query()->where('uuid', $hold)->first();

        if ($model === null) {
            throw ResourceNotFoundException::make('That legal hold was not found.');
        }

        $validated = $request->validate([
            // Lifting a hold is what allows a record to be destroyed. It must say why.
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->holdProjection(
            $this->governance->liftHold($model, $validated['reason'], $actor),
        ));
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function consentProjection(ConsentRecord $record): array
    {
        return [
            'id' => $record->uuid,
            'purpose' => $record->purpose,
            'granted_at' => $record->granted_at?->toIso8601ZuluString(),
            // A timestamp, never a missing row: "did she ever agree, and when did she change her
            // mind" is the question a complaint asks.
            'withdrawn_at' => $record->withdrawn_at?->toIso8601ZuluString(),
            'is_live' => $record->isLive(),
            'notice_version' => $record->notice_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holdProjection(LegalHold $hold): array
    {
        return [
            'id' => $hold->uuid,
            'entity_type' => $hold->entity_type,
            'entity_id' => $hold->entity_id,
            'subject_id' => $hold->subject_id,
            'reference' => $hold->reference,
            'reason' => $hold->reason,
            'placed_by' => $hold->placed_by,
            'placed_at' => $hold->placed_at?->toIso8601ZuluString(),
            'lifted_at' => $hold->lifted_at?->toIso8601ZuluString(),
            'lift_reason' => $hold->lift_reason,
            'is_active' => $hold->isActive(),
        ];
    }
}
