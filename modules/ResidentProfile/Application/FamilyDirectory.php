<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Database\Eloquent\Builder;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\FamilyMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentRelationship;

/**
 * Reads over the family aggregate.
 *
 * TAB 07 closes four `no counterpart` rows here, and the command is explicit about how:
 * *"The write side exists; this is the read side of the same aggregate. Do not create a second
 * family model."* So there is no new table, no new entity and no new vocabulary in this file — it
 * reads `families`, `family_memberships` and `resident_relationships`, which
 * {@see HouseholdRegistry} and {@see RelationshipService} already write.
 *
 * ── WHY THE HISTORY NEEDS NO NEW TABLE ───────────────────────────────────────────────
 *
 * Both membership tables are **effective-dated and append-only**: a row carries
 * `effective_from`, a nullable `effective_to`, an `end_reason` and `recorded_by`, and ending a
 * membership sets the end date rather than deleting the row. The kinship history is therefore
 * already in the database — it just has never been read back.
 *
 * That is worth stating because the tempting alternative is an event table, and an event table
 * beside an effective-dated one is two records of the same fact. They agree until the day
 * somebody writes to one path and not the other, and then the family's history has two versions
 * and no way to tell which is right.
 *
 * ── WHAT THE SCHEMA CANNOT ANSWER ────────────────────────────────────────────────────
 *
 * `family_memberships` has **no role column**. The console's `FamilyMember.role` is one of six
 * values — head, partner, child, dependant, elder, other-member — and this API can distinguish
 * exactly two: the resident named by `families.head_resident_id`, and everybody else.
 *
 * So `head` and `other-member` are what this projection emits, and the four it cannot know are
 * not guessed. Reporting a child as an "other member" is a gap; reporting an elder as a child
 * because the code needed a value is a false statement about a family, sitting in a record the
 * office relies on. Recorded as gap G-22 rather than papered over.
 */
final class FamilyDirectory
{
    /** @return Builder<Family> */
    public function query(): Builder
    {
        return Family::query();
    }

    /**
     * One family, with the members and relationships that make it legible.
     *
     * @return array<string, mixed>
     */
    public function detail(Family $family): array
    {
        $memberships = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->orderBy('effective_from')
            ->get();

        return [
            'id' => $family->uuid,
            'code' => $family->code,
            'label' => $family->label,
            'household_id' => $family->household?->uuid,
            'head_resident_id' => $this->uuidOf($family->head_resident_id),
            'verification_status' => $family->verification_status,
            'status' => $family->status,
            'members' => $memberships
                ->map(fn (FamilyMembership $m): array => $this->memberProjection($m, $family))
                ->all(),
            'relationships' => $this->relationshipsWithin($memberships->pluck('resident_id')->all()),
            'created_at' => $family->created_at?->toIso8601String(),
            'updated_at' => $family->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Every family a resident currently belongs to.
     *
     * **A collection whose invariant currently holds it to one**, and the gap between those two
     * sentences is a real divergence rather than sloppy typing.
     *
     * {@see HouseholdMembershipService} refuses a second open family membership, for a reason
     * grounded in money: two open memberships make a person countable twice in per-family grants,
     * which is the duplicate-resident double-payment problem one level down. The console's port is
     * explicitly plural — `familiesOf`, *"people overlap"* — and describes the grandmother counted
     * with her own family and with her daughter's as ordinary.
     *
     * Both positions are coherent and they cannot both be executed. Until the office rules
     * (recorded as G-24), this returns a collection because that is the honest shape of the
     * question, and the collection has at most one member because that is what the invariant
     * allows. Returning a single object would bake the unratified answer into the wire format.
     *
     * @return list<array<string, mixed>>
     */
    public function familiesOf(Resident $resident): array
    {
        $familyIds = FamilyMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->pluck('family_id')
            ->all();

        return Family::query()
            ->whereIn('id', $familyIds)
            ->orderBy('code')
            ->get()
            ->map(fn (Family $family): array => $this->summary($family))
            ->all();
    }

    /**
     * The append-only kinship history for one resident, newest first.
     *
     * Assembled from the two effective-dated tables rather than from an event log. Each row is a
     * *fact with a date*, not a rendered sentence: the console composes the wording, because the
     * office's phrasing for "left the family" is a product decision and this is a data endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function kinshipHistory(Resident $resident): array
    {
        $events = [];

        $memberships = FamilyMembership::query()->where('resident_id', $resident->id)->get();

        // One lookup for every family the resident has ever been in, rather than one per row.
        $familyUuids = Family::query()
            ->whereIn('id', $memberships->pluck('family_id')->unique()->all())
            ->pluck('uuid', 'id')
            ->all();

        foreach ($memberships as $membership) {
            $familyUuid = $familyUuids[$membership->family_id] ?? null;

            $events[] = [
                'kind' => 'member-joined',
                'occurred_on' => $this->asDate($membership->effective_from),
                'family_id' => $familyUuid,
                'related_resident_id' => null,
                'relationship_type' => null,
                'reason' => null,
                'recorded_by' => $membership->recorded_by,
            ];

            if ($membership->effective_to !== null) {
                $events[] = [
                    'kind' => 'member-left',
                    'occurred_on' => $this->asDate($membership->effective_to),
                    'family_id' => $familyUuid,
                    'related_resident_id' => null,
                    'relationship_type' => null,
                    'reason' => $membership->end_reason,
                    'recorded_by' => $membership->recorded_by,
                ];
            }
        }

        $relationships = ResidentRelationship::query()
            ->where('resident_id', $resident->id)
            ->orWhere('related_resident_id', $resident->id)
            ->get();

        foreach ($relationships as $relationship) {
            /*
             * Reported from this resident's side whichever way round it was recorded. A history
             * that showed "Maria was recorded as the parent of Ana" on one screen and nothing on
             * Ana's own screen would read as a missing record rather than a stored direction.
             */
            $otherId = $relationship->resident_id === $resident->id
                ? $relationship->related_resident_id
                : $relationship->resident_id;

            $events[] = [
                'kind' => 'relationship-recorded',
                'occurred_on' => $this->asDate($relationship->effective_from),
                'family_id' => null,
                'related_resident_id' => $this->uuidOf($otherId),
                'relationship_type' => $relationship->type,
                'reason' => $relationship->note,
                'recorded_by' => $relationship->recorded_by,
            ];

            if ($relationship->effective_to !== null) {
                $events[] = [
                    'kind' => 'relationship-ended',
                    'occurred_on' => $this->asDate($relationship->effective_to),
                    'family_id' => null,
                    'related_resident_id' => $this->uuidOf($otherId),
                    'relationship_type' => $relationship->type,
                    'reason' => $relationship->end_reason,
                    'recorded_by' => $relationship->recorded_by,
                ];
            }
        }

        // Newest first. A history read top-down should open on what happened last.
        usort($events, static fn (array $a, array $b): int => ($b['occurred_on'] ?? '') <=> ($a['occurred_on'] ?? ''));

        return $events;
    }

    /** @return array<string, mixed> */
    public function summary(Family $family): array
    {
        return [
            'id' => $family->uuid,
            'code' => $family->code,
            'label' => $family->label,
            'household_id' => $family->household?->uuid,
            'head_resident_id' => $this->uuidOf($family->head_resident_id),
            // Derived from open memberships every time. There is no stored count to drift.
            'member_count' => $family->currentMemberCount(),
            'verification_status' => $family->verification_status,
            'status' => $family->status,
        ];
    }

    /** @return array<string, mixed> */
    private function memberProjection(FamilyMembership $membership, Family $family): array
    {
        return [
            'resident_id' => $this->uuidOf($membership->resident_id),
            // See the class docblock: two roles are knowable, four are not, and none is invented.
            'role' => $membership->resident_id === $family->head_resident_id ? 'head' : 'other-member',
            'joined_on' => $this->asDate($membership->effective_from),
            'left_on' => $this->asDate($membership->effective_to),
        ];
    }

    /**
     * Relationships where **both** ends are in this family.
     *
     * A relationship reaching outside is deliberately not returned here. The family view would
     * otherwise disclose the existence of a resident the caller did not ask about and may not be
     * scoped to see — a link is itself information about somebody.
     *
     * @param  list<int>  $residentIds
     * @return list<array<string, mixed>>
     */
    private function relationshipsWithin(array $residentIds): array
    {
        return ResidentRelationship::query()
            ->whereIn('resident_id', $residentIds)
            ->whereIn('related_resident_id', $residentIds)
            ->whereNull('effective_to')
            ->get()
            ->map(fn (ResidentRelationship $r): array => [
                'id' => $r->uuid,
                'resident_id' => $this->uuidOf($r->resident_id),
                'related_resident_id' => $this->uuidOf($r->related_resident_id),
                'type' => $r->type,
                'effective_from' => $this->asDate($r->effective_from),
            ])
            ->all();
    }

    /**
     * Resident primary keys to the UUIDs clients are allowed to see.
     *
     * Built **once per request** and read from memory afterwards. The obvious alternative — a
     * lookup inside each projection — is the N+1 the command singles out, and on a family view it
     * would fire once per member and twice per relationship.
     *
     * @var array<int, string>|null
     */
    private ?array $uuids = null;

    private function uuidOf(?int $residentId): ?string
    {
        if ($residentId === null) {
            return null;
        }

        $this->uuids ??= Resident::query()->pluck('uuid', 'id')->all();

        return $this->uuids[$residentId] ?? null;
    }

    private function asDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
