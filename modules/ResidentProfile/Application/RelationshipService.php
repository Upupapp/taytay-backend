<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\RelationshipType;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentRelationship;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Kinship between residents (ADR 0014 §4).
 *
 * ONE DIRECTED ROW; THE INVERSE IS DERIVED. "A is parent of B" is stored. "B is child of A"
 * is computed on read and never written. Storing both gives two rows that disagree the moment
 * either is edited, with no principled rule for deciding which is then true.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: validate that a family structure is *possible*. It
 * rejects self-relations and exact duplicates, and it refuses a row whose inverse already
 * exists. It does not check that a parent is older than their child, that nobody has three
 * spouses, or that a guardian is an adult. Real Philippine households include informal
 * adoptions, absent parents recorded years later, and grandparents raising grandchildren
 * under arrangements no schema anticipates — a system that enforced a tidy model would start
 * refusing to record real families, and the staff response is always to record something
 * false that the system accepts.
 */
final class RelationshipService
{
    public function __construct(private readonly ResidentProfileAudit $audit) {}

    /**
     * Records that one resident is related to another.
     *
     * @param  string|null  $effectiveFrom  defaults to today
     */
    public function relate(
        Resident $resident,
        Resident $related,
        RelationshipType $type,
        ActorContext $actor,
        ?string $note = null,
        ?string $effectiveFrom = null,
    ): ResidentRelationship {
        return DB::transaction(function () use ($resident, $related, $type, $actor, $note, $effectiveFrom): ResidentRelationship {
            if ((int) $resident->id === (int) $related->id) {
                // Nobody is their own parent. Left unchecked this is the commonest data-entry
                // slip in a relationship screen, and it produces a cycle every later
                // household-graph traversal has to defend against.
                throw new ApiException(ErrorCode::BadRequest, 'A resident cannot be related to themselves.');
            }

            $this->assertNoExistingTie($resident, $related, $type);

            $relationship = ResidentRelationship::query()->create([
                'resident_id' => $resident->id,
                'related_resident_id' => $related->id,
                'type' => $type,
                'effective_from' => $effectiveFrom ?? now()->toDateString(),
                'note' => $note,
                'recorded_by' => $actor->subjectId,
            ]);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.relationship-recorded',
                "Relationship recorded ({$type->value})",
                (string) $resident->uuid,
            );

            return $relationship;
        });
    }

    /**
     * Ends a relationship without deleting it.
     *
     * A separation, a death or a guardianship that lapsed are all facts with a date. Deleting
     * the row would assert the relationship never existed, which is a different and false
     * claim — and it would break every assistance decision that was made on the strength of
     * it.
     */
    public function end(ResidentRelationship $relationship, ActorContext $actor, string $endReason): ResidentRelationship
    {
        if (! $relationship->isOpen()) {
            throw new ApiException(ErrorCode::Conflict, 'That relationship has already ended.');
        }

        $relationship->forceFill([
            'effective_to' => now()->toDateString(),
            'end_reason' => $endReason,
        ])->save();

        $this->audit->recordResidentWrite(
            $actor->subjectId,
            'resident.relationship-ended',
            'Relationship ended',
            null,
        );

        return $relationship->refresh();
    }

    /**
     * Everything known about how this resident is related to others, in both directions.
     *
     * Rows where the resident is the *subject* are returned as stored. Rows where they are
     * the *object* are returned with the type inverted, so a caller asking "who is this
     * person related to" gets one coherent list rather than having to reason about direction
     * themselves — which is exactly the reasoning that produces duplicate rows.
     *
     * @return list<array<string, mixed>>
     */
    public function forResident(Resident $resident, bool $openOnly = true): array
    {
        $direct = ResidentRelationship::query()
            ->where('resident_id', $resident->id)
            ->when($openOnly, fn ($q) => $q->whereNull('effective_to'))
            ->get()
            ->map(fn (ResidentRelationship $row): array => $this->project($row, $row->type, (int) $row->related_resident_id, false));

        $inverse = ResidentRelationship::query()
            ->where('related_resident_id', $resident->id)
            ->when($openOnly, fn ($q) => $q->whereNull('effective_to'))
            ->get()
            ->map(fn (ResidentRelationship $row): array => $this->project($row, $row->type->inverse(), (int) $row->resident_id, true));

        return $direct->concat($inverse)->values()->all();
    }

    /**
     * The residents this one is responsible for, as parent, guardian or provider.
     *
     * This is the list that decides what a citizen may see of other household members
     * (ADR 0014 §5) — a parent has a legitimate reason to see their child's basic profile;
     * a boarder sharing the roof does not.
     *
     * @return Collection<int, int> resident primary keys
     */
    public function dependentsOf(Resident $resident): Collection
    {
        $ids = [];

        foreach ($this->forResident($resident) as $relationship) {
            $type = RelationshipType::from((string) $relationship['type']);

            if ($type->impliesCareResponsibility()) {
                $ids[] = (int) $relationship['related_resident_pk'];
            }
        }

        return new Collection(array_values(array_unique($ids)));
    }

    /**
     * Refuses a tie that is already recorded, in either direction.
     *
     * The inverse check is the important half. Without it, staff record "Ana is mother of
     * Ben" on Ana's screen and "Ben is son of Ana" on Ben's, and the registry holds the same
     * fact twice — usually with two different effective dates, so neither can be trusted.
     */
    private function assertNoExistingTie(Resident $resident, Resident $related, RelationshipType $type): void
    {
        $direct = ResidentRelationship::query()
            ->where('resident_id', $resident->id)
            ->where('related_resident_id', $related->id)
            ->where('type', $type->value)
            ->whereNull('effective_to')
            ->exists();

        if ($direct) {
            throw new ApiException(ErrorCode::Conflict, 'That relationship is already recorded.');
        }

        $inverse = ResidentRelationship::query()
            ->where('resident_id', $related->id)
            ->where('related_resident_id', $resident->id)
            ->where('type', $type->inverse()->value)
            ->whereNull('effective_to')
            ->exists();

        if ($inverse) {
            throw new ApiException(
                ErrorCode::Conflict,
                'The inverse of that relationship is already recorded; it is the same fact.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function project(ResidentRelationship $row, RelationshipType $type, int $otherResidentPk, bool $derived): array
    {
        /** @var Resident|null $other */
        $other = Resident::query()->find($otherResidentPk);

        return [
            'id' => $row->uuid,
            'type' => $type->value,
            // Flagged so a client can tell a stored row from a computed view of one, and so
            // an "end this relationship" action knows it must act on the stored direction.
            'derived' => $derived,
            'related_resident_pk' => $otherResidentPk,
            'related_resident' => $other === null ? null : [
                'id' => $other->uuid,
                'name' => $other->fullName(),
            ],
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'note' => $row->note,
        ];
    }
}
