<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\HouseholdSummary;
use Modules\ResidentProfile\Infrastructure\Eloquent\Family;
use Modules\ResidentProfile\Infrastructure\Eloquent\FamilyMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Household;
use Modules\ResidentProfile\Infrastructure\Eloquent\HouseholdMembership;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Households and the families inside them (ADR 0014).
 *
 * Owns creation, dwelling/utility facts, headship, verification and lifecycle status.
 * Membership — who lives here, and since when — belongs to
 * {@see HouseholdMembershipService}, because it is effective-dated and has entirely
 * different rules.
 *
 * A HEAD IS NOT AN OWNER. Headship is a reporting convenience: DSWD forms want a named
 * contact per household. It confers no authority over the other members' records — a head who
 * could read their household's files would be a privacy hole shaped like a family
 * (CLAUDE.md Article 5.3).
 */
final class HouseholdRegistry
{
    /**
     * The facts that make a household profile "complete" enough to act on.
     *
     * Used only to compute the progress figure staff see. Never an eligibility input: a
     * family whose water source nobody recorded is not less entitled to relief.
     */
    private const COMPLETENESS_FIELDS = [
        'street_address', 'purok_or_sitio', 'dwelling_type', 'tenure_status',
        'electricity_source', 'water_source', 'toilet_facility', 'head_resident_id',
    ];

    public function __construct(private readonly ResidentProfileAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ActorContext $actor): Household
    {
        return DB::transaction(function () use ($attributes, $actor): Household {
            $household = Household::query()->create($attributes);

            $household->forceFill(['profile_completeness' => $this->completeness($household)])->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.created',
                'Household record created',
                (string) $household->uuid,
            );

            return $household;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(Household $household, array $changes, ActorContext $actor): Household
    {
        return DB::transaction(function () use ($household, $changes, $actor): Household {
            /** @var Household $household */
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);

            $household->fill($changes);
            $household->profile_completeness = $this->completeness($household);
            $household->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.updated',
                'Household record updated',
                (string) $household->uuid,
            );

            return $household->refresh();
        });
    }

    /**
     * Names the head of the household.
     *
     * The nominee must be a current member. A head who does not live there is either a data
     * error or an attempt to attach an outsider to a household's assistance, and both are
     * worth refusing rather than recording.
     *
     * `null` clears headship, which is a legitimate state: a household whose head has died
     * still exists and still receives assistance, and forcing staff to name a replacement
     * immediately produces an invented head the LGU then addresses letters to.
     */
    public function changeHead(Household $household, ?Resident $head, ActorContext $actor): Household
    {
        return DB::transaction(function () use ($household, $head, $actor): Household {
            /** @var Household $household */
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);

            if ($head !== null && ! $this->isCurrentMember($household, $head)) {
                throw new ApiException(
                    ErrorCode::Conflict,
                    'The head of a household must be a current member of it.',
                );
            }

            $household->forceFill(['head_resident_id' => $head?->id])->save();
            $household->forceFill(['profile_completeness' => $this->completeness($household)])->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'household.head-changed',
                $head === null ? 'Household head cleared' : 'Household head changed',
                (string) $household->uuid,
            );

            return $household->refresh();
        });
    }

    public function changeVerification(Household $household, string $status, ActorContext $actor): Household
    {
        $household->forceFill([
            'verification_status' => $status,
            // Cleared when the status is anything but verified: a `verified_at` left behind
            // reads as "verified once" on every later screen and report.
            'verified_at' => $status === 'field-verified' ? now() : null,
            'verified_by' => $status === 'field-verified' ? $actor->subjectId : null,
        ])->save();

        $this->audit->recordResidentWrite(
            $actor->subjectId,
            'household.verification-changed',
            'Household verification status changed',
            (string) $household->uuid,
        );

        return $household->refresh();
    }

    /**
     * Dissolves or archives a household. Never a delete — assistance history references the
     * household that received it (ADR 0008 §3).
     */
    public function changeStatus(Household $household, string $status, string $reason, ActorContext $actor): Household
    {
        $household->forceFill(['status' => $status, 'status_reason' => $reason])->save();

        $this->audit->recordResidentWrite(
            $actor->subjectId,
            'household.status-changed',
            "Household status changed to {$status}",
            (string) $household->uuid,
        );

        return $household->refresh();
    }

    // ── families ──────────────────────────────────────────────────────────────────────

    /**
     * Creates a family inside a household.
     *
     * Several per household is expected, not exceptional.
     */
    public function createFamily(Household $household, array $attributes, ActorContext $actor): Family
    {
        return DB::transaction(function () use ($household, $attributes, $actor): Family {
            $family = Family::query()->create($attributes + ['household_id' => $household->id]);

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'family.created',
                'Family unit created',
                (string) $family->uuid,
            );

            return $family;
        });
    }

    public function changeFamilyHead(Family $family, ?Resident $head, ActorContext $actor): Family
    {
        return DB::transaction(function () use ($family, $head, $actor): Family {
            /** @var Family $family */
            $family = Family::query()->lockForUpdate()->findOrFail($family->id);

            if ($head !== null) {
                $isMember = FamilyMembership::query()
                    ->where('family_id', $family->id)
                    ->where('resident_id', $head->id)
                    ->whereNull('effective_to')
                    ->exists();

                if (! $isMember) {
                    throw new ApiException(
                        ErrorCode::Conflict,
                        'The head of a family must be a current member of it.',
                    );
                }
            }

            $family->forceFill(['head_resident_id' => $head?->id])->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'family.head-changed',
                $head === null ? 'Family head cleared' : 'Family head changed',
                (string) $family->uuid,
            );

            return $family->refresh();
        });
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────

    /**
     * The published cross-module view.
     */
    public function summaryFor(string $householdUuid): ?HouseholdSummary
    {
        /** @var Household|null $household */
        $household = Household::query()->where('uuid', $householdUuid)->first();

        if ($household === null) {
            return null;
        }

        return new HouseholdSummary(
            id: (string) $household->uuid,
            code: (string) $household->code,
            barangayId: $household->barangay_id === null ? null : (int) $household->barangay_id,
            memberCount: $household->currentMemberCount(),
            status: (string) $household->status,
        );
    }

    /**
     * The household a resident currently lives in, if any.
     */
    public function currentHouseholdFor(Resident $resident): ?Household
    {
        $householdId = HouseholdMembership::query()
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->value('household_id');

        if ($householdId === null) {
            return null;
        }

        /** @var Household|null $household */
        $household = Household::query()->find($householdId);

        return $household;
    }

    /**
     * Base listing query. Returns a builder so the caller can apply its barangay scope at
     * the query, before anything is fetched or counted (ADR 0012).
     *
     * @return Builder<Household>
     */
    public function query(): Builder
    {
        return Household::query()->orderBy('code');
    }

    public function isCurrentMember(Household $household, Resident $resident): bool
    {
        return HouseholdMembership::query()
            ->where('household_id', $household->id)
            ->where('resident_id', $resident->id)
            ->whereNull('effective_to')
            ->exists();
    }

    /**
     * A percentage, rounded down, of the profile facts that have been recorded.
     *
     * Cached on write and derivable from the row, which is what ADR 0008 §10 requires of any
     * stored derivative.
     */
    private function completeness(Household $household): int
    {
        $recorded = 0;

        foreach (self::COMPLETENESS_FIELDS as $field) {
            $value = $household->getAttribute($field);

            if ($value !== null && $value !== '') {
                $recorded++;
            }
        }

        return (int) floor(($recorded / count(self::COMPLETENESS_FIELDS)) * 100);
    }
}
