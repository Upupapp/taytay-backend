<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\ResidentProfile\Contracts\ResidentMerged;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\KycCase;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentCorrectionRequest;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentDuplicatePair;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMatchCandidate;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentMerge;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentSector;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Application\BarangayCodes;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Finding duplicate canonical residents, and resolving one into another (ADR 0013 §3).
 *
 * THE MOST DESTRUCTIVE OPERATION IN THIS SYSTEM. A merge collapses two people into one
 * row. When the two really were one person it repairs a registry that was quietly paying
 * or refusing benefits twice; when they were not, it makes one resident disappear and
 * hands their assistance history to a stranger — and it does so by destroying the very
 * evidence that they were two people.
 *
 * That asymmetry drives every rule here:
 *
 *  * **Nothing merges automatically.** The matcher proposes pairs; a reviewer with
 *    `resident.merge` must have recorded `same-person` on the pair first (ADR 0010 §3).
 *  * **One transaction.** Reassignment across accounts, credentials, KYC cases, sectors
 *    and corrections either all lands or none of it does. A half-merge leaves credentials
 *    verifying against a soft-deleted record.
 *  * **The absorbed row is soft-deleted, never destroyed.** A merge decided in error has to
 *    remain provable.
 *  * **The absorbed name is preserved as an alias on the survivor**, so a clerk searching
 *    the old name still finds the person instead of enrolling them a third time.
 */
final class ResidentMergeService
{
    public function __construct(
        private readonly ResidentRegistry $registry,
        private readonly AccountLinkService $links,
        private readonly ResidentProfileAudit $audit,
        private readonly BarangayCodes $barangayCodes,
    ) {}

    /**
     * Records that two canonical residents may be the same person.
     *
     * Idempotent on the normalised pair, so re-running detection does not put the same
     * question in front of a reviewer twice, and a decision already made is preserved.
     */
    public function recordPair(Resident $a, Resident $b, string $rule, string $confidence): ResidentDuplicatePair
    {
        if ((int) $a->id === (int) $b->id) {
            throw new ApiException(ErrorCode::BadRequest, 'A record cannot be a duplicate of itself.');
        }

        [$lower, $higher] = ResidentDuplicatePair::normalise((int) $a->id, (int) $b->id);

        /** @var ResidentDuplicatePair $pair */
        $pair = ResidentDuplicatePair::query()->firstOrCreate(
            ['lower_resident_id' => $lower, 'higher_resident_id' => $higher],
            ['rule' => $rule, 'confidence' => $confidence, 'decision' => 'undecided'],
        );

        return $pair;
    }

    /**
     * Scans the registry for residents that share a matching fingerprint.
     *
     * Deterministic only, and it decides nothing — same restraint as {@see ResidentMatcher},
     * for the same reason. Returns the pairs now awaiting a reviewer.
     *
     * @return Collection<int, ResidentDuplicatePair>
     */
    public function detectDuplicates(?int $barangayId = null): Collection
    {
        $query = Resident::query()->select(['id', 'identity_fingerprint', 'barangay_id']);

        if ($barangayId !== null) {
            $query->where('barangay_id', $barangayId);
        }

        $byFingerprint = $query->get()->groupBy('identity_fingerprint');
        $pairs = [];

        foreach ($byFingerprint as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $rows = $group->values();

            // Every unordered combination within the collision group. Groups are tiny in
            // practice — a fingerprint shared by more than two or three rows is itself a
            // data-quality alarm worth raising rather than an efficiency problem.
            for ($i = 0; $i < $rows->count(); $i++) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $pairs[] = $this->recordPair($rows[$i], $rows[$j], 'name-and-birth-date', 'exact');
                }
            }
        }

        return new Collection($pairs);
    }

    /**
     * A reviewer rules on a pair.
     *
     * `same-person` does NOT merge. It only unlocks {@see merge()} — deciding that two rows
     * describe one person and choosing which row survives are separate judgements, and the
     * second one is the irreversible half.
     */
    public function decide(
        ResidentDuplicatePair $pair,
        string $decision,
        ActorContext $actor,
        ?string $note = null,
    ): ResidentDuplicatePair {
        if (! in_array($decision, ['same-person', 'different-person'], true)) {
            throw new ApiException(ErrorCode::BadRequest, 'Decision must be same-person or different-person.');
        }

        $pair->forceFill([
            'decision' => $decision,
            'decided_by' => $actor->subjectId,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        return $pair->refresh();
    }

    /**
     * What a merge would do, field by field, without doing it.
     *
     * The reviewer's last chance to notice that the two records disagree about something
     * that matters. Conflicts are listed explicitly rather than left for a human to spot by
     * comparing two columns — a differing birth date between "the same person" is usually
     * the sign that they are not.
     *
     * @return array<string, mixed>
     */
    public function preview(Resident $survivor, Resident $absorbed): array
    {
        $fields = [
            'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'birth_date',
            'civil_status', 'barangay_id', 'street_address', 'purok_or_sitio',
            'mobile_number', 'email', 'verification_tier',
        ];

        $comparison = [];
        $conflicts = [];

        foreach ($fields as $field) {
            $survivorValue = $this->render($survivor->getAttribute($field));
            $absorbedValue = $this->render($absorbed->getAttribute($field));

            $differs = $survivorValue !== $absorbedValue;

            $comparison[$field] = [
                'survivor' => $survivorValue,
                'absorbed' => $absorbedValue,
                'differs' => $differs,
            ];

            // A value the absorbed record has and the survivor does not is not a conflict —
            // it is data about to be lost, which is worth flagging separately from a
            // genuine disagreement.
            if ($differs && $survivorValue !== null && $absorbedValue !== null) {
                $conflicts[] = $field;
            }
        }

        return [
            'survivor' => $this->identity($survivor),
            'absorbed' => $this->identity($absorbed),
            'fields' => $comparison,
            'conflicts' => $conflicts,
            /*
             * Counts for the rows this module owns, plus accounts (Identity is *above* it in
             * the graph, so asking is allowed).
             *
             * Credentials are deliberately absent. Reporting them would mean reading
             * Credential from here, which is the cycle the merge itself avoids by
             * announcing an event — and a preview is not worth reintroducing a boundary
             * violation for. The executed merge still reports how many cards moved, because
             * that number arrives from the owning module rather than being fetched from it.
             */
            'will_reassign' => [
                'accounts' => count($this->accountIdsFor($absorbed)),
                'kyc_cases' => KycCase::query()->where('resolved_resident_id', $absorbed->uuid)->count(),
                'sectors' => ResidentSector::query()->where('resident_id', $absorbed->id)->count(),
            ],
        ];
    }

    /**
     * Executes the merge. One transaction, or nothing.
     *
     * The survivor keeps its uuid — every external reference a client already holds stays
     * valid — and the absorbed row is soft-deleted after everything that pointed at it has
     * been repointed.
     */
    public function merge(
        Resident $survivor,
        Resident $absorbed,
        ActorContext $actor,
        string $reason,
        ?ResidentDuplicatePair $pair = null,
    ): ResidentMerge {
        return DB::transaction(function () use ($survivor, $absorbed, $actor, $reason, $pair): ResidentMerge {
            /*
             * Lock in a deterministic order — lower primary key first. Two reviewers merging
             * the same pair from opposite directions at the same moment would otherwise each
             * hold the row the other needs, and one request would die holding a half-built
             * merge open.
             */
            [$firstId, $secondId] = ResidentDuplicatePair::normalise((int) $survivor->id, (int) $absorbed->id);
            Resident::withTrashed()->lockForUpdate()->findOrFail($firstId);
            Resident::withTrashed()->lockForUpdate()->findOrFail($secondId);

            /*
             * Reloaded `withTrashed` so that a record absorbed by an earlier merge still
             * loads and `assertMergeable()` can refuse it as a conflict. A default query
             * would simply not find it, and a retried merge would answer "not found" —
             * which is both untrue and useless to the reviewer looking at the pair.
             */
            /** @var Resident $survivor */
            $survivor = Resident::withTrashed()->findOrFail($survivor->id);
            /** @var Resident $absorbed */
            $absorbed = Resident::withTrashed()->findOrFail($absorbed->id);

            $this->assertMergeable($survivor, $absorbed, $pair);

            // The absorbed person's name lives on as an alias of the survivor. Without this
            // the next clerk searching the old name finds nothing and enrols them again.
            $this->registry->recordAlias(
                $survivor,
                $this->registry->nameTuple($absorbed),
                'merge',
                (string) $absorbed->uuid,
            );

            $reassignedAccounts = $this->links->reassign($absorbed, $survivor);

            /*
             * Announce the merge and let each owning module repoint its own rows.
             *
             * Credential is *below* ResidentProfile in the dependency graph, so calling into
             * it directly would close a cycle the boundary map forbids. The dispatcher
             * returns each listener's result, which is how the count below is obtained
             * without this module knowing that credentials exist at all (ADR 0013 §6).
             *
             * Synchronous and inside the transaction: a queued listener would let the merge
             * commit while a card still pointed at a soft-deleted resident.
             */
            $reassignedCredentials = $this->sumListenerCounts(
                Event::dispatch(new ResidentMerged(
                    survivorResidentUuid: (string) $survivor->uuid,
                    absorbedResidentUuid: (string) $absorbed->uuid,
                    actorSubjectId: $actor->subjectId,
                )),
            );

            $reassignedKycCases = KycCase::query()
                ->where('resolved_resident_id', $absorbed->uuid)
                ->update(['resolved_resident_id' => $survivor->uuid]);

            $reassignedSectors = $this->moveSectors($absorbed, $survivor);

            // Open corrections follow the person. A pending request against a record that no
            // longer exists can never be actioned, and the resident is never told why.
            ResidentCorrectionRequest::query()
                ->where('resident_id', $absorbed->id)
                ->update(['resident_id' => $survivor->id]);

            // Match candidates from onboarding point at the absorbed row; repoint them so a
            // reviewer still sees the collision against the record that survived.
            ResidentMatchCandidate::query()
                ->where('resident_id', $absorbed->id)
                ->update(['resident_id' => $survivor->id]);

            $merge = ResidentMerge::query()->create([
                'survivor_resident_id' => $survivor->id,
                'absorbed_resident_id' => $absorbed->id,
                'duplicate_pair_id' => $pair?->uuid,
                'reason' => $reason,
                'reassigned_accounts' => $reassignedAccounts,
                'reassigned_credentials' => $reassignedCredentials,
                'reassigned_kyc_cases' => $reassignedKycCases,
                'reassigned_sectors' => $reassignedSectors,
                'merged_by' => $actor->subjectId,
                'merged_at' => now(),
            ]);

            $this->registry->recordEvent(
                $survivor,
                'absorbed-record',
                null,
                null,
                (string) $absorbed->uuid,
                $reason,
                $actor,
            );

            $this->registry->recordEvent(
                $absorbed,
                'merged-into',
                null,
                null,
                (string) $survivor->uuid,
                $reason,
                $actor,
            );

            // Deactivate before soft-deleting: a consumer that reads with `withTrashed()`
            // for history still sees an unmistakably inactive record rather than one that
            // looks live.
            $absorbed->forceFill(['is_active' => false])->save();
            $absorbed->delete();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.merged',
                'Duplicate resident record merged into the surviving record',
                (string) $survivor->uuid,
            );

            return $merge;
        });
    }

    /**
     * The conditions under which a merge is allowed to proceed at all.
     */
    private function assertMergeable(Resident $survivor, Resident $absorbed, ?ResidentDuplicatePair $pair): void
    {
        if ((int) $survivor->id === (int) $absorbed->id) {
            throw new ApiException(ErrorCode::BadRequest, 'A record cannot be merged into itself.');
        }

        if ($absorbed->trashed() || $survivor->trashed()) {
            throw new ApiException(ErrorCode::Conflict, 'One of those records has already been merged.');
        }

        /*
         * A merge requires a reviewer to have affirmatively said "same person" about this
         * exact pair. Accepting an arbitrary pair of ids would make the whole duplicate
         * review workflow decorative — the destructive operation would be reachable
         * directly by anyone holding the permission.
         */
        if ($pair === null) {
            [$lower, $higher] = ResidentDuplicatePair::normalise((int) $survivor->id, (int) $absorbed->id);

            /** @var ResidentDuplicatePair|null $pair */
            $pair = ResidentDuplicatePair::query()
                ->where('lower_resident_id', $lower)
                ->where('higher_resident_id', $higher)
                ->first();
        }

        if ($pair === null || ! $pair->isSamePerson()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'These records have not been reviewed and confirmed as the same person.',
            );
        }
    }

    /**
     * Moves sector tags, dropping the ones the survivor already carries.
     *
     * A straight UPDATE would violate the `(resident_id, sector)` unique key the moment
     * both records were tagged `senior-citizen` — which, for two rows describing one
     * person, is the normal case rather than the edge case.
     */
    private function moveSectors(Resident $absorbed, Resident $survivor): int
    {
        $existing = ResidentSector::query()
            ->where('resident_id', $survivor->id)
            ->pluck('sector')
            ->all();

        $moved = ResidentSector::query()
            ->where('resident_id', $absorbed->id)
            ->whereNotIn('sector', $existing === [] ? [''] : $existing)
            ->update(['resident_id' => $survivor->id]);

        // Whatever is left is a tag the survivor already has. Remove the duplicate rather
        // than leaving it attached to a soft-deleted record where it will be missed by
        // every sectoral count.
        ResidentSector::query()->where('resident_id', $absorbed->id)->delete();

        return $moved;
    }

    /**
     * Totals the row counts listeners reported.
     *
     * Non-integer results are ignored rather than coerced: a listener that returns null (or
     * nothing) is saying "I moved nothing I can count", and turning that into a 0 would be
     * the same number for a different reason. Anything else is a listener contract error and
     * must not silently inflate a figure this system records as evidence.
     */
    private function sumListenerCounts(mixed $responses): int
    {
        if (! is_array($responses)) {
            return 0;
        }

        $total = 0;

        foreach ($responses as $response) {
            if (is_int($response)) {
                $total += $response;
            }
        }

        return $total;
    }

    /** @return list<string> */
    private function accountIdsFor(Resident $resident): array
    {
        return $this->links->forResident($resident)
            ->filter(static fn ($link): bool => $link->isActive())
            ->map(static fn ($link): string => (string) $link->account_id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Resident $resident): array
    {
        return [
            'id' => $resident->uuid,
            'name' => $resident->fullName(),
            'birth_date' => $resident->birth_date?->toDateString(),
            'barangay_id' => $resident->barangay_id,
            'barangay_code' => $this->barangayCodes->codeFor($resident->barangay_id),
            'verification_tier' => $resident->verification_tier->value,
            // Shown so the reviewer can see whether the two rows were ever matched to each
            // other by the deterministic key, without re-running the hash themselves.
            'fingerprint_matches' => IdentityFingerprint::forName(
                (string) $resident->first_name,
                (string) $resident->last_name,
                (string) $resident->birth_date?->toDateString(),
            ) === $resident->identity_fingerprint,
        ];
    }

    private function render(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
