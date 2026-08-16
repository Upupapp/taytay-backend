<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccountDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\ClientChannel;
use Modules\Shared\Application\IdempotencyService;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\DraftService;
use Modules\Welfare\Application\IntakeService;
use Modules\Welfare\Domain\IntakeSource;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceDraft;
use Modules\Welfare\Infrastructure\Eloquent\AssistanceIntake;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * A citizen composing and submitting their own request (ADR 0017 §2–3).
 *
 * THE SOURCE IS DERIVED, NEVER ACCEPTED. A client cannot tell us it is a walk-in or a legacy
 * import — those are provenance claims it has no standing to make. The channel header decides
 * between `citizen-web` and `citizen-mobile`, and nothing else is reachable from here.
 *
 * THE RESIDENT IS RESOLVED FROM THE TOKEN. There is no resident id in this contract, so a
 * citizen cannot file a request against somebody else's record.
 *
 * SUBMISSION IS IDEMPOTENT. A weak connection is the normal case for this endpoint, not an
 * edge one: the request reaches the server, the response is lost, the app retries. Without
 * replay protection that opens a second case — two files for one person, worked independently,
 * discovered at payout.
 */
final class MyAssistanceController
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly IntakeService $intakes,
        private readonly AccountDirectory $accounts,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function listDrafts(Request $request, ActorContext $actor): JsonResponse
    {
        $this->ownResidentIdOrFail($actor);

        return ApiResponse::item([
            'drafts' => $this->drafts->openFor($actor)
                ->map(fn (AssistanceDraft $draft): array => $this->draftProjection($draft))
                ->all(),
        ]);
    }

    /**
     * Opens a draft, or resumes the one already in progress.
     */
    public function storeDraft(Request $request, ActorContext $actor): JsonResponse
    {
        $residentId = $this->ownResidentIdOrFail($actor);

        $validated = $request->validate($this->draftRules());

        $draft = $this->drafts->openOrResume(
            $this->sourceFor($request),
            $validated + ['resident_id' => $residentId],
            $actor,
        );

        return ApiResponse::created($this->draftProjection($draft));
    }

    public function updateDraft(Request $request, ActorContext $actor, string $draft): JsonResponse
    {
        $this->ownResidentIdOrFail($actor);

        $model = $this->drafts->ownedOrFail($draft, $actor);

        $validated = $request->validate($this->draftRules());

        return ApiResponse::item($this->draftProjection(
            $this->drafts->update($model, $validated, $actor),
        ));
    }

    public function discardDraft(Request $request, ActorContext $actor, string $draft): JsonResponse
    {
        $this->ownResidentIdOrFail($actor);

        $this->drafts->discard($this->drafts->ownedOrFail($draft, $actor), $actor);

        return ApiResponse::item(['status' => 'discarded']);
    }

    /**
     * Submits a draft as a real request.
     *
     * Wrapped in the idempotency service, keyed on `Idempotency-Key`. A retry with the same key
     * and body replays the original response verbatim — the client cannot tell it from the
     * first, which is exactly the point (conventions §7).
     */
    public function submitDraft(Request $request, ActorContext $actor, string $draft): JsonResponse
    {
        $this->ownResidentIdOrFail($actor);

        $model = $this->drafts->ownedOrFail($draft, $actor);

        /*
         * Idempotency wraps the WHOLE operation, including the already-submitted check.
         *
         * Ordering matters and got this wrong once. With the submitted check outside, a retry
         * carrying the same key never reached the replay at all: the first call had already
         * marked the draft submitted, so the second took the "here is your case" path and
         * answered `200` where the original answered `201`. Safe — but not a replay, and a
         * client comparing status codes would see two different outcomes for one request.
         *
         * Inside, the stored response is returned verbatim, status included, and the caller
         * genuinely cannot tell the retry from the original (conventions §7).
         *
         * The already-submitted branch still matters for the case the key cannot cover: a
         * client whose response was lost and whose key has since expired is not making a
         * mistake — it is asking what happened. A `409` would leave it showing a failure for a
         * request that in fact succeeded, and the applicant would file again.
         */
        [$status, $body] = $this->idempotency->execute(
            $request->header('Idempotency-Key'),
            $actor->subjectId,
            'POST /api/v1/me/assistance/drafts/{draft}/submit',
            ['draft' => $draft],
            function () use ($model, $actor): array {
                if ($model->isSubmitted()) {
                    return [200, $this->submittedProjection($model)];
                }

                $intake = $this->intakes->submitDraft($model, $actor);

                return [201, $this->intakeProjection($intake)];
            },
        );

        return ApiResponse::item($body, $status);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function draftProjection(AssistanceDraft $draft): array
    {
        return [
            'id' => $draft->uuid,
            'source' => $draft->source,
            'category' => $draft->category,
            'urgency' => $draft->urgency,
            'narrative' => $draft->narrative,
            'requested_service_id' => $draft->requested_service_id,
            'consent_reference' => $draft->consent_reference,
            // Shown so a client can warn before the form is lost, rather than discovering it
            // at submission.
            'expires_at' => $draft->expires_at?->toIso8601ZuluString(),
            'submitted_at' => $draft->submitted_at?->toIso8601ZuluString(),
            'is_editable' => $draft->isEditable(),
        ];
    }

    /**
     * What the applicant is told after submitting.
     *
     * The case reference and its projected status — nothing internal. The full tracking view is
     * `GET /me/cases/{case}`, which applies the same additive projection (ADR 0016 §5).
     *
     * @return array<string, mixed>
     */
    private function intakeProjection(AssistanceIntake $intake): array
    {
        /** @var WelfareCase $case */
        $case = WelfareCase::query()->findOrFail($intake->welfare_case_id);

        return [
            'id' => $case->uuid,
            'reference' => $case->case_number,
            'status' => $case->status->citizenStatus(),
            'status_message' => $case->status->citizenMessage(),
            'submitted_at' => $intake->submitted_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedProjection(AssistanceDraft $draft): array
    {
        /** @var WelfareCase|null $case */
        $case = WelfareCase::query()->where('uuid', $draft->submitted_case_id)->first();

        return [
            'id' => $draft->submitted_case_id,
            'reference' => $case?->case_number,
            'status' => $case?->status->citizenStatus(),
            'status_message' => $case?->status->citizenMessage(),
            'submitted_at' => $draft->submitted_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function draftRules(): array
    {
        return [
            'category' => ['sometimes', 'nullable', 'string', 'max:48'],
            'urgency' => ['sometimes', 'nullable', 'string', 'in:routine,priority,emergency'],
            'narrative' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'household_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'requested_service_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'consent_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'privacy_notice_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Provenance from the channel, never from the body.
     *
     * A client claiming `walk-in` would be manufacturing evidence that a clerk saw the person.
     */
    private function sourceFor(Request $request): IntakeSource
    {
        $channel = $request->attributes->get('client_channel');

        return $channel instanceof ClientChannel && $channel === ClientChannel::CitizenMobile
            ? IntakeSource::CitizenMobile
            : IntakeSource::CitizenWeb;
    }

    private function ownResidentIdOrFail(ActorContext $actor): string
    {
        $residentId = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        if ($residentId === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        return $residentId;
    }
}
