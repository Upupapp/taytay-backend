<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\ResidentProfile\Application\AccountLinkService;
use Modules\ResidentProfile\Application\ResidentProfileAudit;
use Modules\ResidentProfile\Application\ResidentRegistry;
use Modules\ResidentProfile\Contracts\CivilStatus;
use Modules\ResidentProfile\Contracts\CorrectableField;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Infrastructure\Eloquent\AccountResidentLink;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentAlias;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentStatusEvent;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\BarangayCodes;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * The staff-facing canonical resident registry (ADR 0013).
 *
 * Two projections, and the difference between them is the point. A LIST returns the
 * minimum needed to pick the right person out of a search — name, barangay, tier. A DETAIL
 * returns the operational record and is audited as a read of another person's personal
 * data, because that is exactly what it is (Article 5.4).
 *
 * Income, encrypted PhilSys digits and sensitive sector tags appear in neither by default:
 * a clerk confirming an address has no business seeing a VAWC flag, and a projection is the
 * only place that restraint can actually be enforced.
 */
final class ResidentController
{
    public function __construct(
        private readonly ResidentRegistry $registry,
        private readonly AccountLinkService $links,
        private readonly AuthorizationService $authorization,
        private readonly ResidentProfileAudit $audit,
        private readonly BarangayCodes $barangayCodes,
    ) {}

    /**
     * Search and list, always inside the caller's barangay scope.
     */
    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $pagination = PaginationParams::fromRequest($request);

        // Scope at the query. Filtering after the fetch still reads other barangays' rows
        // out of the database and would make the pagination total count them (ADR 0012).
        $query = $this->authorization->scopeToBarangays($actor, $this->registry->query());

        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            $term = '%'.strtolower(trim($search)).'%';

            $query->where(function ($builder) use ($term): void {
                $builder
                    ->whereRaw('lower(last_name) like ?', [$term])
                    ->orWhereRaw('lower(first_name) like ?', [$term])
                    /*
                     * Aliases are searched too. A clerk holding a three-year-old form types
                     * the name it carries; if the registry only matches the current name,
                     * the clerk concludes the resident is not enrolled and creates the
                     * duplicate this module exists to prevent.
                     */
                    ->orWhereIn('id', ResidentAlias::query()
                        ->select('resident_id')
                        ->whereRaw('lower(last_name) like ?', [$term])
                        ->orWhereRaw('lower(first_name) like ?', [$term]));
            });
        }

        $barangayId = $request->query('barangay_id');

        if (is_numeric($barangayId)) {
            // Narrowing only — the scope constraint above is already applied, so asking for
            // a barangay outside it yields nothing rather than widening anything.
            $query->where('barangay_id', (int) $barangayId);
        }

        $tier = $request->query('verification_tier');

        if (is_string($tier) && VerificationTier::tryFrom($tier) !== null) {
            $query->where('verification_tier', $tier);
        }

        $status = $request->query('status');

        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        $total = (clone $query)->count();
        $residents = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($residents->all(), $total, $pagination),
            fn (Resident $resident): array => $this->listProjection($resident),
        );
    }

    public function show(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $model = $this->residentOrFail($actor, $resident);

        // Opening somebody else's record is the event a data-privacy complaint asks about,
        // so it is recorded before the payload is built.
        $this->audit->recordResidentRead($actor->subjectId, (string) $model->uuid);

        return ApiResponse::item($this->detailProjection($model));
    }

    /**
     * Creates a resident directly — the assisted or walk-in enrolment path.
     *
     * The record starts `unverified` no matter what the caller sends: there is no field
     * here to assert a tier, because that would be a one-step route to a verified record
     * with no evidence behind it (ADR 0010 §4).
     */
    public function store(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:96'],
            'middle_name' => ['nullable', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'sex' => ['required', 'string', 'in:female,male'],
            'birth_date' => ['required', 'date', 'before:today'],
            'civil_status' => ['required', 'string', CivilStatus::rule()],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'street_address' => ['required', 'string', 'max:191'],
            'purok_or_sitio' => ['nullable', 'string', 'max:96'],
            'mobile_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        // A clerk must not be able to enrol somebody into a barangay they cannot serve —
        // that is how a record lands where its own office cannot see it.
        $this->authorization->authorizeBarangay(
            $actor,
            (int) $validated['barangay_id'],
            'That barangay was not found.',
        );

        $model = $this->registry->create($validated, $actor);

        return ApiResponse::created($this->detailProjection($model));
    }

    /**
     * Corrects fields on the canonical record.
     *
     * The accepted set is derived from {@see CorrectableField}, not hand-written here, so
     * this endpoint cannot drift away from the citizen-facing one and start accepting a
     * field the enum does not classify (Article 3.4).
     */
    public function update(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentManage);

        $model = $this->residentOrFail($actor, $resident);

        $validated = $request->validate($this->correctionRules() + [
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        if ($validated !== [] && array_key_exists('barangay_id', $validated)) {
            // Moving a resident out of the caller's scope would be a one-way trip: they
            // could not open the record afterwards to undo it.
            $this->authorization->authorizeBarangay(
                $actor,
                (int) $validated['barangay_id'],
                'That barangay was not found.',
            );
        }

        $updated = $this->registry->applyChanges($model, $validated, $actor, $reason);

        return ApiResponse::item($this->detailProjection($updated));
    }

    /**
     * Moves the verification tier. Requires its own permission and always a reason.
     */
    public function changeVerification(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentVerify);

        $validated = $request->validate([
            'verification_tier' => ['required', 'string', 'in:unverified,partially-verified,verified'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $model = $this->registry->changeVerification(
            $this->residentOrFail($actor, $resident),
            VerificationTier::from($validated['verification_tier']),
            $actor,
            $validated['reason'],
        );

        return ApiResponse::item($this->detailProjection($model));
    }

    /**
     * Deactivates or reactivates. Never a delete (ADR 0008 §3).
     */
    public function changeActivation(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentVerify);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $model = $this->registry->setActive(
            $this->residentOrFail($actor, $resident),
            (bool) $validated['is_active'],
            $actor,
            $validated['reason'],
        );

        return ApiResponse::item($this->detailProjection($model));
    }

    /**
     * The record's change history and preserved former names.
     */
    public function history(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentView);

        $model = $this->residentOrFail($actor, $resident);

        $this->audit->recordResidentRead($actor->subjectId, (string) $model->uuid);

        return ApiResponse::item([
            'events' => $this->registry->history($model)
                ->map(fn (ResidentStatusEvent $event): array => [
                    'id' => $event->uuid,
                    'event' => $event->event,
                    'field' => $event->field,
                    'previous_value' => $event->previous_value,
                    'new_value' => $event->new_value,
                    'reason' => $event->reason,
                    'actor_subject_id' => $event->actor_subject_id,
                    'occurred_at' => $event->occurred_at?->toIso8601ZuluString(),
                ])->all(),
            'aliases' => $this->registry->aliases($model)
                ->map(fn (ResidentAlias $alias): array => [
                    'id' => $alias->uuid,
                    'name' => $alias->fullName(),
                    'birth_date' => $alias->birth_date?->toDateString(),
                    'source' => $alias->source,
                    'recorded_at' => $alias->recorded_at?->toIso8601ZuluString(),
                ])->all(),
        ]);
    }

    // ── account links ─────────────────────────────────────────────────────────────────

    public function listLinks(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentLinkReview);

        $model = $this->residentOrFail($actor, $resident);

        return ApiResponse::item([
            'links' => $this->links->forResident($model)
                ->map(fn (AccountResidentLink $link): array => $this->linkProjection($link))
                ->all(),
        ]);
    }

    public function storeLink(Request $request, ActorContext $actor, string $resident): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentLinkReview);

        $validated = $request->validate([
            'account_id' => ['required', 'string', 'max:64'],
        ]);

        $link = $this->links->link(
            $this->residentOrFail($actor, $resident),
            $validated['account_id'],
            'staff-link',
            $actor,
        );

        return ApiResponse::created($this->linkProjection($link));
    }

    public function revokeLink(Request $request, ActorContext $actor, string $resident, string $link): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ResidentLinkReview);

        $model = $this->residentOrFail($actor, $resident);

        /** @var AccountResidentLink|null $row */
        $row = AccountResidentLink::query()
            ->where('uuid', $link)
            ->where('resident_id', $model->id)
            ->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That link was not found.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::item(
            $this->linkProjection($this->links->revoke($row, $actor, $validated['reason'])),
        );
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * Enough to pick the right person out of a search, and no more.
     *
     * @return array<string, mixed>
     */
    private function listProjection(Resident $resident): array
    {
        return [
            'id' => $resident->uuid,
            'name' => $resident->fullName(),
            'birth_date' => $resident->birth_date?->toDateString(),
            'barangay_id' => $resident->barangay_id,
            'barangay_code' => $this->barangayCodes->codeFor($resident->barangay_id),
            'verification_tier' => $resident->verification_tier->value,
            'is_active' => (bool) $resident->is_active,
        ];
    }

    /**
     * The operational record.
     *
     * Absent by construction: `philsys_last_four`, `identity_fingerprint` and
     * `monthly_income_centavos`. The first two are hidden on the model as well, so a future
     * `toArray()` cannot leak them; income is means-testing evidence that belongs to the
     * assistance workflow, under its own permission, not to whoever can open a profile.
     *
     * @return array<string, mixed>
     */
    private function detailProjection(Resident $resident): array
    {
        return $this->listProjection($resident) + [
            'first_name' => $resident->first_name,
            'middle_name' => $resident->middle_name,
            'last_name' => $resident->last_name,
            'suffix' => $resident->suffix,
            'sex' => $resident->sex,
            'civil_status' => $resident->civil_status,
            'street_address' => $resident->street_address,
            'purok_or_sitio' => $resident->purok_or_sitio,
            'mobile_number' => $resident->mobile_number,
            'email' => $resident->email,
            'verified_at' => $resident->verified_at?->toIso8601ZuluString(),
            'created_at' => $resident->created_at?->toIso8601ZuluString(),
            'updated_at' => $resident->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkProjection(AccountResidentLink $link): array
    {
        return [
            'id' => $link->uuid,
            'account_id' => $link->account_id,
            'origin' => $link->origin,
            'status' => $link->status,
            'linked_by' => $link->linked_by,
            'linked_at' => $link->linked_at?->toIso8601ZuluString(),
            'revoked_by' => $link->revoked_by,
            'revoked_at' => $link->revoked_at?->toIso8601ZuluString(),
            'revocation_reason' => $link->revocation_reason,
        ];
    }

    /**
     * Validation rules derived from the correctable-field catalog.
     *
     * @return array<string, list<string>>
     */
    private function correctionRules(): array
    {
        return [
            CorrectableField::FirstName->value => ['sometimes', 'string', 'max:96'],
            CorrectableField::MiddleName->value => ['sometimes', 'nullable', 'string', 'max:96'],
            CorrectableField::LastName->value => ['sometimes', 'string', 'max:96'],
            CorrectableField::Suffix->value => ['sometimes', 'nullable', 'string', 'max:16'],
            CorrectableField::Sex->value => ['sometimes', 'string', 'in:female,male'],
            CorrectableField::BirthDate->value => ['sometimes', 'date', 'before:today'],
            CorrectableField::CivilStatus->value => ['sometimes', 'string', CivilStatus::rule()],
            CorrectableField::BarangayId->value => ['sometimes', 'integer', 'exists:barangays,id'],
            CorrectableField::StreetAddress->value => ['sometimes', 'string', 'max:191'],
            CorrectableField::PurokOrSitio->value => ['sometimes', 'nullable', 'string', 'max:96'],
            CorrectableField::MobileNumber->value => ['sometimes', 'nullable', 'string', 'max:32'],
            CorrectableField::Email->value => ['sometimes', 'nullable', 'email', 'max:191'],
        ];
    }

    /**
     * Loads a resident and enforces the caller's barangay scope on it.
     *
     * Every staff route goes through here, so there is no verb a caller can switch to in
     * order to reach a record their scope excludes. Out-of-scope returns NOT FOUND rather
     * than FORBIDDEN: "exists but not yours" is enough to enumerate the municipality's
     * residents one guessed id at a time (OWASP API1).
     */
    private function residentOrFail(ActorContext $actor, string $uuid): Resident
    {
        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $uuid)->first();

        if ($resident === null) {
            throw ResourceNotFoundException::make('That resident was not found.');
        }

        $this->authorization->authorizeBarangay(
            $actor,
            $resident->barangay_id === null ? null : (int) $resident->barangay_id,
            'That resident was not found.',
        );

        return $resident;
    }
}
