<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\FamilyMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Who lives where, and since when (ADR 0014 §2).
 *
 * THE RULE THIS CLASS EXISTS TO HOLD: **membership is never edited or deleted, only closed
 * and reopened.** Moving a resident writes an `effective_to` on the old row and inserts a new
 * one. Nothing rewrites history.
 *
 * That is not tidiness. Assistance is distributed against a household at a moment in time,
 * and the question asked afterwards is always "who was living there when this was released".
 * A mutable `household_id` on `residents` answers that question with today's answer, which
 * is the wrong one for every audit, every appeal and every duplicate-claim investigation.
 *
 * Two invariants enforced here rather than in the schema, because neither is expressible as
 * a portable constraint (the first needs a partial unique index; the second spans tables):
 *
 *  1. A resident has **at most one open household membership** at any time. A person cannot
 *     live in two households simultaneously, and allowing it would double-count them in
 *     every household-based distribution.
 *  2. A resident may only join a family whose household they currently live in. A family
 *     membership that outlives the residence it belongs to is how somebody keeps drawing a
 *     family grant from an address they left.
 */
final class HouseholdMembershipService
{
    public function __construct(private readonly ResidentProfileAudit $audit) {}

    /**
     * Admits a resident to a household.
     *
     * Idempotent: a resident already living here gets their existing membership back rather
     * than a second open row, so a double-tapped "add member" cannot double-count them.
     */
    public function addMember(
        Household $household,
        Resident $resident,
        ActorContext $actor,
        ?string $effectiveFrom = null,
    ): HouseholdMembership {
        return DB::transaction(function () use ($household, $resident, $actor, $effectiveFrom): HouseholdMembership {
            $open = $this->openMembershipFor($resident);

            if ($open !== null) {
                if ((int) $open->household_id === (int) $household->id) {
                    return $open;
                }

                /*
                 * Refused rather than silently transferred. A move is a decision with a date
                 * and a reason, and quietly performing one as a side effect of "add member"
                 * would lose both — and would move a resident out of a household somebody
                 * else is still counting.
                 */
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That resident already belongs to another household. Transfer them instead.',
                );
            }

            $membership = HouseholdMembership::query()->create([
                'household_id' => $household->id,
                'resident_id' => $resident->id,
                'effective_from' => $effectiveFrom ?? now()->toDateString(),
                'recorded_by' => $actor->subjectId,
            ]);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.member-added',
                'Resident added to a household',
                (string) $household->uuid,
            );

            return $membership;
        });
    }

    /**
     * Ends a resident's residence in a household.
     *
     * Closes their family memberships inside that household too: a family unit is defined
     * within a household, so leaving the address ends membership of the units in it. Leaving
     * those open is how a person keeps appearing on a family roster at an address they no
     * longer live at.
     */
    public function removeMember(
        Household $household,
        Resident $resident,
        ActorContext $actor,
        string $endReason = 'moved-out',
        ?string $effectiveTo = null,
    ): HouseholdMembership {
        return DB::transaction(function () use ($household, $resident, $actor, $endReason, $effectiveTo): HouseholdMembership {
            /** @var HouseholdMembership|null $membership */
            $membership = HouseholdMembership::query()
                ->where('household_id', $household->id)
                ->where('resident_id', $resident->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw new ApiException(ErrorCode::Conflict, 'That resident is not a current member of this household.');
            }

            $closingDate = $this->closingDate($membership->effective_from, $effectiveTo);

            $membership->forceFill([
                'effective_to' => $closingDate,
                'end_reason' => $endReason,
            ])->save();

            $this->closeFamilyMembershipsIn($household, $resident, $closingDate, $endReason);

            // Headship follows residence. A head who has moved out is a contact the LGU will
            // keep writing to at an address they left.
            if ((int) $household->head_resident_id === (int) $resident->id) {
                $household->forceFill(['head_resident_id' => null])->save();
            }

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.member-removed',
                'Resident removed from a household',
                (string) $household->uuid,
            );

            return $membership->refresh();
        });
    }

    /**
     * Moves a resident from one household to another, in one transaction.
     *
     * The two halves must not be separable. A transfer that closed the old membership and
     * then failed would leave a real person belonging to no household at all — invisible to
     * every household-based distribution until somebody noticed.
     */
    public function transfer(
        Household $to,
        Resident $resident,
        ActorContext $actor,
        string $reason = 'transferred',
        ?string $effectiveFrom = null,
    ): HouseholdMembership {
        return DB::transaction(function () use ($to, $resident, $actor, $reason, $effectiveFrom): HouseholdMembership {
            $open = $this->openMembershipFor($resident);

            if ($open === null) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That resident does not currently belong to a household; add them instead.',
                );
            }

            if ((int) $open->household_id === (int) $to->id) {
                throw new ApiException(ErrorCode::Conflict, 'That resident already belongs to this household.');
            }

            /** @var Household $from */
            $from = Household::query()->findOrFail($open->household_id);

            $date = $effectiveFrom ?? now()->toDateString();

            $this->removeMember($from, $resident, $actor, $reason, $date);

            $membership = $this->addMember($to, $resident, $actor, $date);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.member-transferred',
                'Resident transferred between households',
                (string) $to->uuid,
            );

            return $membership;
        });
    }

    // ── families ──────────────────────────────────────────────────────────────────────

    public function addToFamily(
        Family $family,
        Resident $resident,
        ActorContext $actor,
        ?string $effectiveFrom = null,
    ): FamilyMembership {
        return DB::transaction(function () use ($family, $resident, $actor, $effectiveFrom): FamilyMembership {
            /** @var Household $household */
            $household = Household::query()->findOrFail($family->household_id);

            $livesThere = HouseholdMembership::query()
                ->where('household_id', $household->id)
                ->where('resident_id', $resident->id)
                ->whereNull('effective_to')
                ->exists();

            if (! $livesThere) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'A resident must belong to the household before joining one of its families.',
                );
            }

            /** @var FamilyMembership|null $existing */
            $existing = FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('resident_id', $resident->id)
                ->whereNull('effective_to')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            /*
             * A resident may belong to only one family at a time — within this household or
             * any other. Two open family memberships would make them countable twice in
             * per-family grants, which is the same double-payment problem as duplicate
             * residents, one level down.
             */
            $otherFamily = FamilyMembership::query()
                ->where('resident_id', $resident->id)
                ->whereNull('effective_to')
                ->exists();

            if ($otherFamily) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'That resident already belongs to a family. End that membership first.',
                );
            }

            return FamilyMembership::query()->create([
                'family_id' => $family->id,
                'resident_id' => $resident->id,
                'effective_from' => $effectiveFrom ?? now()->toDateString(),
                'recorded_by' => $actor->subjectId,
            ]);
        });
    }

    public function removeFromFamily(
        Family $family,
        Resident $resident,
        ActorContext $actor,
        string $endReason = 'left-family',
    ): FamilyMembership {
        /** @var FamilyMembership|null $membership */
        $membership = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->first();

        if ($membership === null) {
            throw new ApiException(ErrorCode::Conflict, 'That resident is not a current member of this family.');
        }

        $membership->forceFill([
            'effective_to' => $this->closingDate($membership->effective_from, null),
            'end_reason' => $endReason,
        ])->save();

        if ((int) $family->head_resident_id === (int) $resident->id) {
            $family->forceFill(['head_resident_id' => null])->save();
        }

        return $membership->refresh();
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────

    /**
     * Everyone living in a household now.
     *
     * @return Collection<int, HouseholdMembership>
     */
    public function currentMembers(Household $household): Collection
    {
        return HouseholdMembership::query()
            ->where('household_id', $household->id)
            ->whereNull('effective_to')
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * A resident's full residence history, newest first.
     *
     * @return Collection<int, HouseholdMembership>
     */
    public function historyFor(Resident $resident): Collection
    {
        return HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    public function openMembershipFor(Resident $resident): ?HouseholdMembership
    {
        /** @var HouseholdMembership|null $membership */
        $membership = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->lockForUpdate()
            ->first();

        return $membership;
    }

    /**
     * Ends every family membership this resident holds inside one household.
     */
    private function closeFamilyMembershipsIn(
        Household $household,
        Resident $resident,
        string $closingDate,
        string $endReason,
    ): void {
        $familyIds = Family::query()->where('household_id', $household->id)->pluck('id')->all();

        if ($familyIds === []) {
            return;
        }

        FamilyMembership::query()
            ->whereIn('family_id', $familyIds)
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => $closingDate, 'end_reason' => $endReason]);
    }

    /**
     * Never before the membership began.
     *
     * A row whose `effective_to` precedes its `effective_from` describes a residence that
     * ended before it started; every period query then silently excludes it, and the member
     * disappears from history rather than merely from the present.
     */
    private function closingDate(mixed $effectiveFrom, ?string $requested): string
    {
        $start = $effectiveFrom instanceof Carbon ? $effectiveFrom : Carbon::parse((string) $effectiveFrom);
        $end = $requested === null ? Carbon::now() : Carbon::parse($requested);

        return $end->lt($start) ? $start->toDateString() : $end->toDateString();
    }
}
