<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\ResidentDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\ReferralDisclosure;
use Modules\Welfare\Application\ReferralService;
use Modules\Welfare\Domain\DisclosureBasis;
use Modules\Welfare\Domain\ReferralStatus;
use Modules\Welfare\Domain\SharedField;
use Modules\Welfare\Infrastructure\Eloquent\Referral;
use Modules\Welfare\Infrastructure\Eloquent\ReferralNote;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Referrals, for staff (ADR 0021).
 *
 * FOUR PERMISSIONS, AND THE SPLITS ARE THE DESIGN:
 *
 *  * `referral.view` — read the queue and the directory.
 *  * `referral.manage` — draft, record what the receiving office reports, close out.
 *  * `referral.send` — **transmit**. The one irreversible act, and therefore its own decision.
 *  * `referral.disclose-protected` — release a home address, sector membership or assistance
 *    history.
 *
 * Plus `document.share` to attach a file, which is not a fifth permission but the *same* one
 * TAB 15 defined: attaching a document to a referral IS an outward disclosure of that file.
 * Treating it as a different act because it happens on a different screen would lose the whole
 * point of that permission.
 */
final class ReferralController
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ReferralDisclosure $disclosure,
        private readonly ResidentDirectory $residents,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The queue: overdue first, then most urgent, then oldest.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralView);

        $pagination = PaginationParams::fromRequest($request);

        $query = $request->boolean('overdue_only')
            ? $this->referrals->overdueQuery()
            : $this->referrals->query();

        foreach (['status', 'urgency', 'destination_type', 'resident_id'] as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        if ($request->boolean('open_only')) {
            $query->whereNotIn('status', [ReferralStatus::Closed->value, ReferralStatus::Declined->value]);
        }

        $query = $this->referrals->inWorkingOrder($this->scoped($query, $actor));

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (Referral $referral): array => $this->projection($referral),
        );
    }

    public function show(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralView);

        $model = $this->referralOrFail($actor, $referral);

        return ApiResponse::item($this->projection($model) + [
            'disclosure' => [
                'basis' => $model->disclosure_basis?->value,
                'note' => $model->disclosure_note,
                'recorded_at' => $model->disclosure_recorded_at?->toIso8601ZuluString(),
                'fields' => $this->disclosure->plan($model),
                'attachments' => $this->disclosure->attachmentPlan($model),
            ],
            'blockers' => $this->disclosure->blockersFor($model),
            'notes' => $model->notes()->get()->map(fn (ReferralNote $note): array => [
                'id' => $note->uuid,
                'audience' => $note->audience,
                'body' => $note->body,
                'recorded_at' => $note->recorded_at?->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'max:64'],
            'case_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'provider_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Required only when there is no directory entry to take it from.
            'destination_name' => ['required_without:provider_id', 'string', 'max:160'],
            'destination_type' => ['sometimes', 'string', 'max:48'],
            'destination_contact' => ['sometimes', 'nullable', 'string', 'max:160'],
            'urgency' => ['sometimes', 'string', 'in:routine,priority,urgent'],
            'service_requested' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $resident = $this->residents->summaryFor($validated['resident_id']);

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        // A referral can only be raised for somebody inside the caller's scope.
        $this->authorization->authorizeBarangay($actor, $resident->barangayId, 'That resident was not found.');

        return ApiResponse::created($this->projection(
            $this->referrals->draft($validated, $actor),
        ));
    }

    public function update(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'service_requested' => ['sometimes', 'string', 'max:255'],
            'reason' => ['sometimes', 'string', 'max:500'],
            'urgency' => ['sometimes', 'string', 'in:routine,priority,urgent'],
            'destination_contact' => ['sometimes', 'nullable', 'string', 'max:160'],
            'follow_up_on' => ['sometimes', 'nullable', 'date'],
        ]);

        return ApiResponse::item($this->projection($this->referrals->update($model, $validated, $actor)));
    }

    // ── the disclosure record ─────────────────────────────────────────────────────────

    public function recordAuthority(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'basis' => ['required', 'string', 'in:'.implode(',', DisclosureBasis::values())],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->projection($this->disclosure->recordAuthority(
            $model,
            DisclosureBasis::from($validated['basis']),
            $validated['note'],
            $actor,
        )));
    }

    public function shareField(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'field' => ['required', 'string', 'in:'.implode(',', SharedField::values())],
            'because' => ['required', 'string', 'max:255'],
        ]);

        $this->disclosure->shareField(
            $model,
            SharedField::from($validated['field']),
            $validated['because'],
            // Resolved here, so authorization stays at the boundary and the service stays a
            // pure statement of the rule.
            $this->authorization->allows($actor, Permission::ReferralDiscloseProtected),
            $actor,
        );

        return ApiResponse::item(['fields' => $this->disclosure->plan($model)]);
    }

    public function withholdField(Request $request, ActorContext $actor, string $referral, string $field): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $this->disclosure->withholdField($model, SharedField::from($field));

        return ApiResponse::item(['fields' => $this->disclosure->plan($model)]);
    }

    public function attachDocument(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        /*
         * THE SAME PERMISSION TAB 15 DEFINED, because it is the same act.
         *
         * Nobody holds `document.share` yet (gap G-26), so referral attachments are refused
         * today — deliberately. The alternative was a second, quieter permission that happens to
         * do the same thing, which is how a control that was decided once gets undone by a
         * feature.
         */
        $this->authorization->authorize($actor, Permission::DocumentShare);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'document_id' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:160'],
            'because' => ['required', 'string', 'max:255'],
        ]);

        $this->disclosure->attachDocument(
            $model,
            $validated['document_id'],
            $validated['label'],
            $validated['because'],
            $actor,
        );

        return ApiResponse::item(['attachments' => $this->disclosure->attachmentPlan($model)]);
    }

    public function detachDocument(Request $request, ActorContext $actor, string $referral, string $document): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);
        $this->disclosure->detachDocument($model, $document);

        return ApiResponse::item(['attachments' => $this->disclosure->attachmentPlan($model)]);
    }

    /**
     * The sheet that leaves the building.
     */
    public function summary(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralView);

        $model = $this->referralOrFail($actor, $referral);

        return ApiResponse::item($this->disclosure->compose($model, $actor));
    }

    // ── lifecycle ─────────────────────────────────────────────────────────────────────

    public function send(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        // The one irreversible act, and therefore its own decision.
        $this->authorization->authorize($actor, Permission::ReferralSend);

        $model = $this->referralOrFail($actor, $referral);

        return ApiResponse::item($this->projection($this->referrals->send($model, $actor)));
    }

    public function recordStatus(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', ReferralStatus::values())],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::item($this->projection($this->referrals->recordStatus(
            $model,
            ReferralStatus::from($validated['status']),
            $actor,
            $validated['outcome'] ?? null,
        )));
    }

    public function addNote(Request $request, ActorContext $actor, string $referral): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReferralManage);

        $model = $this->referralOrFail($actor, $referral);

        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:internal,receiving-office'],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $note = $this->referrals->addNote($model, $validated['audience'], $validated['body'], $actor);

        return ApiResponse::created([
            'id' => $note->uuid,
            'audience' => $note->audience,
            'body' => $note->body,
            'recorded_at' => $note->recorded_at?->toIso8601ZuluString(),
        ]);
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function projection(Referral $referral): array
    {
        return [
            'id' => $referral->uuid,
            'reference_number' => $referral->reference_number,
            'resident_id' => $referral->resident_id,
            'destination_type' => $referral->destination_type,
            'destination_name' => $referral->destination_name,
            'destination_contact' => $referral->destination_contact,
            'provider_id' => $referral->provider_id,
            'status' => $referral->status->value,
            'urgency' => $referral->urgency->value,
            'service_requested' => $referral->service_requested,
            'reason' => $referral->reason,
            'referred_at' => $referral->referred_at?->toIso8601ZuluString(),
            'sent_at' => $referral->sent_at?->toIso8601ZuluString(),
            'follow_up_on' => $referral->follow_up_on?->toDateString(),
            'responded_at' => $referral->responded_at?->toIso8601ZuluString(),
            'outcome' => $referral->outcome,
            'is_overdue' => $referral->isOverdue(),
            'available_transitions' => array_map(
                static fn (ReferralStatus $status): string => $status->value,
                $referral->status->allowedNext(),
            ),
        ];
    }

    /**
     * Scope a referral query through the client's barangay.
     *
     * @param  Builder<Referral>  $query
     * @return Builder<Referral>
     */
    private function scoped(Builder $query, ActorContext $actor): Builder
    {
        if ($actor->scope->isUnrestricted()) {
            return $query;
        }

        /*
         * Through the case where there is one, and otherwise not at all.
         *
         * A referral raised with no case carries no barangay evidence of its own — the client's
         * barangay is ResidentProfile's fact, and denormalising it here would be a second copy
         * that stops moving when the family does (ADR 0008 §10). A restricted actor sees the
         * ones they can account for; guessing would be worse than admitting the limit.
         */
        $scopedCases = $this->authorization->scopeToBarangays(
            $actor,
            WelfareCase::query()->select('id'),
        );

        return $query->whereIn('welfare_case_id', $scopedCases);
    }

    private function referralOrFail(ActorContext $actor, string $uuid): Referral
    {
        /** @var Referral|null $referral */
        $referral = Referral::query()->where('uuid', $uuid)->first();

        if ($referral === null) {
            throw ResourceNotFoundException::make('That referral was not found.');
        }

        $resident = $this->residents->summaryFor((string) $referral->resident_id);

        // Out of scope reads as NOT FOUND, never FORBIDDEN (OWASP API1).
        $this->authorization->authorizeBarangay($actor, $resident?->barangayId, 'That referral was not found.');

        return $referral;
    }
}
