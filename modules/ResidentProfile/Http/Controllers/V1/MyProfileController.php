<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccountDirectory;
use Modules\ResidentProfile\Application\HouseholdMembershipService;
use Modules\ResidentProfile\Application\HouseholdRegistry;
use Modules\ResidentProfile\Application\RelationshipService;
use Modules\ResidentProfile\Application\ResidentCorrectionService;
use Modules\ResidentProfile\Contracts\CorrectableField;
use Modules\ResidentProfile\Contracts\RelationshipType;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\FamilyMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionField;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionRequest;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\Pagination\Page;
use Modules\Shared\Application\Pagination\PaginationParams;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;

/**
 * A resident's own canonical profile, for citizen web and mobile (ADR 0013 §4).
 *
 * THE RESIDENT IS RESOLVED FROM THE TOKEN, NEVER FROM THE REQUEST. There is no id in any
 * path or body here, so there is nothing to tamper with — the commonest way a profile
 * endpoint turns into a resident-enumeration endpoint is accepting an identifier it did
 * not need (OWASP API1).
 *
 * The projection is narrower than the staff one on purpose. A citizen sees the record the
 * LGU holds about them, minus the operational apparatus: no internal history, no reviewer
 * identities, no sector tags, no verification reasoning. Sector membership in particular is
 * withheld because two of the tags are protection flags, and a person must never learn from
 * an API payload that a case worker has categorised them.
 *
 * Both clients call this same controller. A separate mobile projection would be a second
 * definition of "what a citizen may see", and the two would drift (Article 3.1).
 */
final class MyProfileController
{
    public function __construct(
        private readonly AccountDirectory $accounts,
        private readonly ResidentCorrectionService $corrections,
        private readonly HouseholdRegistry $households,
        private readonly HouseholdMembershipService $memberships,
        private readonly RelationshipService $relationships,
    ) {}

    /**
     * The caller's own resident record.
     *
     * Returns 404 when the account has no linked resident. That is the honest answer: an
     * account that has not completed onboarding has no canonical record, and inventing an
     * empty one would let a client treat "not yet verified" as "verified with blank
     * fields".
     */
    public function show(Request $request, ActorContext $actor): JsonResponse
    {
        $resident = $this->ownResidentOrFail($actor);

        return ApiResponse::item($this->profileProjection($resident) + [
            // Told explicitly rather than left for the client to infer from which fields
            // happen to be editable — an inference every client would implement slightly
            // differently.
            'editable_fields' => CorrectableField::selfServiceValues(),
            'requestable_fields' => CorrectableField::requestableValues(),
        ]);
    }

    /**
     * Files a correction against the caller's own record.
     *
     * Self-service fields apply immediately; the rest wait for a reviewer. Which is which
     * is decided by {@see CorrectableField}, not by this controller and not by the client
     * (ADR 0013 §4).
     */
    public function requestCorrection(Request $request, ActorContext $actor): JsonResponse
    {
        $resident = $this->ownResidentOrFail($actor);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            /*
             * The closure is doing real work. Laravel validates the keys it was told about
             * and silently DROPS the rest, so without it a payload of
             * `{"changes":{"verification_tier":"verified"}}` would pass validation, arrive
             * as an empty change set, and answer 201 — teaching a client that self-promotion
             * to a verified identity had worked.
             *
             * An unknown or staff-only field is refused explicitly. Deny by default has to
             * be visible to the caller, or it is indistinguishable from a bug (Article 3.4).
             */
            'changes' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_array($value)) {
                    return;
                }

                $unknown = array_diff(array_keys($value), CorrectableField::requestableValues());

                if ($unknown !== []) {
                    $fail('These fields cannot be corrected: '.implode(', ', $unknown).'.');
                }
            }],
            'changes.first_name' => ['sometimes', 'string', 'max:96'],
            'changes.middle_name' => ['sometimes', 'nullable', 'string', 'max:96'],
            'changes.last_name' => ['sometimes', 'string', 'max:96'],
            'changes.suffix' => ['sometimes', 'nullable', 'string', 'max:16'],
            'changes.sex' => ['sometimes', 'string', 'in:female,male'],
            'changes.birth_date' => ['sometimes', 'date', 'before:today'],
            'changes.civil_status' => ['sometimes', 'string', 'in:single,married,widowed,separated,annulled,cohabiting'],
            'changes.barangay_id' => ['sometimes', 'integer', 'exists:barangays,id'],
            'changes.street_address' => ['sometimes', 'string', 'max:191'],
            'changes.purok_or_sitio' => ['sometimes', 'nullable', 'string', 'max:96'],
            'changes.mobile_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'changes.email' => ['sometimes', 'nullable', 'email', 'max:191'],
        ]);

        /** @var array<string, mixed> $changes */
        $changes = $validated['changes'] ?? [];

        $correction = $this->corrections->request(
            $resident,
            $changes,
            $actor,
            $validated['note'] ?? null,
        );

        return ApiResponse::created($this->correctionProjection($correction));
    }

    /**
     * The caller's own correction requests.
     */
    public function listCorrections(Request $request, ActorContext $actor): JsonResponse
    {
        $resident = $this->ownResidentOrFail($actor);

        $pagination = PaginationParams::fromRequest($request);

        $query = ResidentCorrectionRequest::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($pagination->page, $pagination->perPage)->get();

        return ApiResponse::page(
            new Page($rows->all(), $total, $pagination),
            fn (ResidentCorrectionRequest $row): array => $this->correctionProjection($row),
        );
    }

    /**
     * Withdraws a pending request the caller filed.
     */
    public function withdrawCorrection(Request $request, ActorContext $actor, string $correction): JsonResponse
    {
        $resident = $this->ownResidentOrFail($actor);

        /** @var ResidentCorrectionRequest|null $row */
        $row = ResidentCorrectionRequest::query()
            ->where('uuid', $correction)
            // Scoped to the caller's own resident, so another citizen's request id resolves
            // to nothing rather than to a 403 that confirms it exists.
            ->where('resident_id', $resident->id)
            ->first();

        if ($row === null) {
            throw ResourceNotFoundException::make('That correction request was not found.');
        }

        return ApiResponse::item($this->correctionProjection($this->corrections->withdraw($row, $actor)));
    }

    /**
     * The caller's own household, minimised (ADR 0014 §5).
     *
     * THE RULE THIS ENDPOINT EXISTS TO ENFORCE: sharing a roof is not consent to be looked
     * up. A citizen sees the address they live at, who else lives there by name, and their
     * own relationship to each — and nothing else about those people.
     *
     * Withheld from every co-member: verification tier, sector tags, income, contact details,
     * correction history, and anything about assistance. A boarder must not be able to learn
     * from this payload that the landlady is a VAWC survivor, or that the man in the next room
     * has an assistance request pending.
     *
     * The single exception is narrow and stated: for members this citizen is recorded as
     * responsible for — their child, their ward, someone they provide for — birth date and
     * verification tier are included, because a parent legitimately needs to know whether
     * their child's onboarding is finished. That gate is
     * {@see RelationshipType::impliesCareResponsibility()}, resolved server-side from
     * recorded relationships. It is never inferred from co-residence: living with a child is
     * not the same as being their parent.
     */
    public function showHousehold(Request $request, ActorContext $actor): JsonResponse
    {
        $resident = $this->ownResidentOrFail($actor);

        $household = $this->households->currentHouseholdFor($resident);

        if ($household === null) {
            throw ResourceNotFoundException::make('You are not currently recorded in a household.');
        }

        // Resolved once, server-side, from recorded kinship.
        $caredFor = $this->relationships->dependentsOf($resident)->all();

        // The caller's own relationship to each co-member, so a client does not have to make
        // a second call per person and reconstruct the direction itself.
        $relationshipByPk = [];

        foreach ($this->relationships->forResident($resident) as $row) {
            $relationshipByPk[(int) $row['related_resident_pk']] = (string) $row['type'];
        }

        $members = $this->memberships->currentMembers($household)
            ->map(function (HouseholdMembership $membership) use ($resident, $caredFor, $relationshipByPk, $household): ?array {
                $memberPk = (int) $membership->resident_id;

                /** @var Resident|null $member */
                $member = Resident::query()->find($memberPk);

                if ($member === null) {
                    return null;
                }

                $isSelf = $memberPk === (int) $resident->id;

                $entry = [
                    'id' => $member->uuid,
                    'name' => $member->fullName(),
                    'is_self' => $isSelf,
                    'is_head' => (int) $household->head_resident_id === $memberPk,
                    'relationship_to_me' => $isSelf ? null : ($relationshipByPk[$memberPk] ?? null),
                ];

                if ($isSelf || in_array($memberPk, $caredFor, true)) {
                    $entry['birth_date'] = $member->birth_date?->toDateString();
                    $entry['verification_tier'] = $member->verification_tier->value;
                }

                return $entry;
            })
            ->filter()
            ->values()
            ->all();

        return ApiResponse::item([
            'id' => $household->uuid,
            'code' => $household->code,
            'barangay_id' => $household->barangay_id,
            'street_address' => $household->street_address,
            'purok_or_sitio' => $household->purok_or_sitio,
            'member_count' => $household->currentMemberCount(),
            /*
             * Deliberately absent: `verification_status`, `profile_completeness`,
             * `dwelling_type`, `tenure_status` and the utility columns. Those are the LGU's
             * field assessment of the home, and a household that learns it has been recorded
             * as "makeshift" or "rejected" has been handed a judgement about itself through
             * an API rather than by a case worker who can explain it.
             */
            'families' => $this->ownFamilies($household, $resident),
            'members' => $members,
        ]);
    }

    /**
     * The families in this household, with membership shown only for the caller's own.
     *
     * A citizen may see that their household contains three family units — that is a fact
     * about their home. Which of their co-residents belongs to which unit is a fact about
     * those people, so only the caller's own family lists its members.
     *
     * @return list<array<string, mixed>>
     */
    private function ownFamilies(Household $household, Resident $resident): array
    {
        $ownFamilyIds = FamilyMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->pluck('family_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return Family::query()
            ->where('household_id', $household->id)
            ->where('status', 'active')
            ->get()
            ->map(function (Family $family) use ($ownFamilyIds): array {
                $isOwn = in_array((int) $family->id, $ownFamilyIds, true);

                return [
                    'id' => $family->uuid,
                    'label' => $family->label,
                    'is_mine' => $isOwn,
                    'member_count' => $family->currentMemberCount(),
                ];
            })
            ->values()
            ->all();
    }

    // ── projections ───────────────────────────────────────────────────────────────────

    /**
     * What a citizen sees of their own record.
     *
     * Absent: sector tags, internal history, income, PhilSys digits, the matching
     * fingerprint, and every reviewer identity. `verification_tier` IS included — a person
     * is entitled to know whether the LGU considers their identity established, and a
     * client cannot explain why a digital ID is unavailable without it.
     *
     * @return array<string, mixed>
     */
    private function profileProjection(Resident $resident): array
    {
        return [
            'id' => $resident->uuid,
            'first_name' => $resident->first_name,
            'middle_name' => $resident->middle_name,
            'last_name' => $resident->last_name,
            'suffix' => $resident->suffix,
            'sex' => $resident->sex,
            'birth_date' => $resident->birth_date?->toDateString(),
            'civil_status' => $resident->civil_status,
            'barangay_id' => $resident->barangay_id,
            'street_address' => $resident->street_address,
            'purok_or_sitio' => $resident->purok_or_sitio,
            'mobile_number' => $resident->mobile_number,
            'email' => $resident->email,
            'verification_tier' => $resident->verification_tier->value,
            'is_active' => (bool) $resident->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionProjection(ResidentCorrectionRequest $request): array
    {
        return [
            'id' => $request->uuid,
            'status' => $request->status->value,
            'note' => $request->note,
            // The reviewer's note is shown: a refusal the resident cannot read is a
            // decision they cannot act on. The reviewer's identity is not.
            'review_note' => $request->review_note,
            'reviewed_at' => $request->reviewed_at?->toIso8601ZuluString(),
            'created_at' => $request->created_at?->toIso8601ZuluString(),
            'changes' => $request->fields()->get()
                ->map(fn (ResidentCorrectionField $field): array => [
                    'field' => $field->field,
                    'current_value' => $field->current_value,
                    'proposed_value' => $field->proposed_value,
                ])->all(),
        ];
    }

    /**
     * The resident this account acts for.
     *
     * Resolved through Identity's published directory rather than by reading the accounts
     * table, because reaching for another module's model would hand this controller the
     * whole authentication record (Article 2.1).
     */
    private function ownResidentOrFail(ActorContext $actor): Resident
    {
        $residentUuid = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        if ($residentUuid === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        /** @var Resident|null $resident */
        $resident = Resident::query()->where('uuid', $residentUuid)->first();

        if ($resident === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        return $resident;
    }
}
