<?php

declare(strict_types=1);

namespace Modules\ResidentProfile\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ResidentProfile\Contracts\CorrectableField;
use Modules\ResidentProfile\Contracts\VerificationTier;
use Modules\ResidentProfile\Domain\IdentityFingerprint;
use Modules\ResidentProfile\Infrastructure\Eloquent\Resident;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentAlias;
use Modules\ResidentProfile\Infrastructure\Eloquent\ResidentStatusEvent;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * Writes to the canonical resident registry (ADR 0013).
 *
 * EVERY WRITE TO `residents` OUTSIDE KYC APPROVAL GOES THROUGH HERE. That is the whole
 * point: a field changed by an `update()` somewhere in a controller leaves no history, no
 * alias and a stale matching fingerprint, and the damage only surfaces months later when
 * the registry stops finding a person's own duplicates.
 *
 * Three invariants this class holds:
 *
 *  1. **Nothing changes silently.** Every field that moves writes a `resident_status_event`
 *     carrying the previous and the new value.
 *  2. **A superseded name is kept.** Changing a name part records the old one as an alias,
 *     so search still finds the person under it.
 *  3. **The fingerprint follows the identity.** Changing first name, last name or birth
 *     date re-keys `identity_fingerprint`, or duplicate detection silently goes blind.
 *
 * What it deliberately does NOT do: decide authorization. Callers ask AccessControl first.
 * Putting the permission check in here would hide it from the route file, and a reader
 * auditing the surface could no longer see which endpoints are protected (ADR 0002).
 */
final class ResidentRegistry
{
    /** Fields that are part of the matching key and force a fingerprint rebuild. */
    private const FINGERPRINT_FIELDS = ['first_name', 'last_name', 'birth_date'];

    /** Fields whose change means the previous name is worth preserving as an alias. */
    private const NAME_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    public function __construct(private readonly ResidentProfileAudit $audit) {}

    /**
     * Creates a canonical resident directly — the assisted/walk-in path.
     *
     * Starts at `unverified` and there is no parameter to say otherwise. A resident becomes
     * verified only through a reviewer's explicit decision, whether that is KYC approval or
     * {@see changeVerification()}. Letting a create call assert a tier would give any staff
     * member with `resident.manage` a one-step route to a verified record with no evidence
     * behind it (ADR 0010 §4).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ActorContext $actor): Resident
    {
        return DB::transaction(function () use ($attributes, $actor): Resident {
            $resident = Resident::query()->create($attributes + [
                'verification_tier' => VerificationTier::Unverified,
                'identity_fingerprint' => IdentityFingerprint::forName(
                    (string) $attributes['first_name'],
                    (string) $attributes['last_name'],
                    (string) $attributes['birth_date'],
                ),
            ]);

            $this->recordEvent($resident, 'created', null, null, null, 'Record created by staff', $actor);

            $this->audit->recordResidentWrite($actor->subjectId, 'resident.created', 'Resident record created', $resident->uuid);

            return $resident;
        });
    }

    /**
     * Applies a set of field changes, recording each one.
     *
     * Returns the resident refreshed. A change whose new value equals the current one is
     * skipped rather than recorded: a no-op history row is noise, and enough of it makes
     * the real changes unfindable.
     *
     * @param  array<string, mixed>  $changes  keyed by {@see CorrectableField} value
     */
    public function applyChanges(Resident $resident, array $changes, ActorContext $actor, ?string $reason = null): Resident
    {
        return DB::transaction(function () use ($resident, $changes, $actor, $reason): Resident {
            /** @var Resident $resident */
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            // Snapshot the name BEFORE anything moves: the alias must record what the
            // record used to say, and reading it afterwards would record the new name as
            // the old one.
            $nameBefore = $this->nameTuple($resident);
            $touchedName = false;
            $touchedFingerprint = false;
            $applied = 0;

            foreach ($changes as $field => $newValue) {
                $enum = CorrectableField::tryFrom((string) $field);

                // Deny by default: a field outside the closed set is refused, never
                // silently written. This is the last line of defence behind validation —
                // if a controller ever forgets its allow-list, mass assignment still
                // cannot reach `verification_tier` from here.
                if ($enum === null) {
                    throw new ApiException(
                        ErrorCode::BadRequest,
                        "`{$field}` is not a field that can be corrected.",
                    );
                }

                $previous = $this->scalar($resident->getAttribute($enum->value));
                $next = $this->scalar($newValue);

                if ($previous === $next) {
                    continue;
                }

                $resident->setAttribute($enum->value, $newValue);
                $applied++;

                if (in_array($enum->value, self::NAME_FIELDS, true)) {
                    $touchedName = true;
                }

                if (in_array($enum->value, self::FINGERPRINT_FIELDS, true)) {
                    $touchedFingerprint = true;
                }

                $this->recordEvent($resident, 'field-corrected', $enum->value, $previous, $next, $reason, $actor);
            }

            if ($applied === 0) {
                return $resident;
            }

            if ($touchedName) {
                $this->recordAlias($resident, $nameBefore, 'correction', null);
            }

            if ($touchedFingerprint) {
                // Re-key, or the registry stops finding this person's duplicates and the
                // failure is completely silent.
                $resident->identity_fingerprint = IdentityFingerprint::forName(
                    (string) $resident->first_name,
                    (string) $resident->last_name,
                    (string) $this->scalar($resident->birth_date),
                );
            }

            $resident->save();

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.updated',
                "Resident record updated ({$applied} field(s))",
                $resident->uuid,
            );

            return $resident->refresh();
        });
    }

    /**
     * Moves the verification tier.
     *
     * Requires a reason in every direction, including upward. "Why is this person
     * verified" is the question a benefit dispute turns on, and an unexplained promotion is
     * indistinguishable from an unauthorised one after the fact.
     */
    public function changeVerification(
        Resident $resident,
        VerificationTier $tier,
        ActorContext $actor,
        string $reason,
    ): Resident {
        return DB::transaction(function () use ($resident, $tier, $actor, $reason): Resident {
            /** @var Resident $resident */
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            $previous = $resident->verification_tier;

            if ($previous === $tier) {
                return $resident;
            }

            $resident->forceFill([
                'verification_tier' => $tier,
                // Cleared on demotion: a `verified_at` left behind on an unverified record
                // reads as "verified once" to every later screen and report.
                'verified_at' => $tier === VerificationTier::Verified ? now() : null,
            ])->save();

            $this->recordEvent(
                $resident,
                'verification-changed',
                'verification_tier',
                $previous->value,
                $tier->value,
                $reason,
                $actor,
            );

            $this->audit->recordResidentWrite(
                $actor->subjectId,
                'resident.verification-changed',
                'Resident verification tier changed',
                $resident->uuid,
            );

            return $resident->refresh();
        });
    }

    /**
     * Deactivates or reactivates a resident.
     *
     * Never a delete. Welfare retention is statutory and a removed resident orphans the
     * history proving what was paid to whom (ADR 0008 §3).
     */
    public function setActive(Resident $resident, bool $isActive, ActorContext $actor, string $reason): Resident
    {
        return DB::transaction(function () use ($resident, $isActive, $actor, $reason): Resident {
            /** @var Resident $resident */
            $resident = Resident::query()->lockForUpdate()->findOrFail($resident->id);

            if ((bool) $resident->is_active === $isActive) {
                return $resident;
            }

            $resident->forceFill(['is_active' => $isActive])->save();

            $this->recordEvent(
                $resident,
                $isActive ? 'reactivated' : 'deactivated',
                'is_active',
                $isActive ? 'false' : 'true',
                $isActive ? 'true' : 'false',
                $reason,
                $actor,
            );

            return $resident->refresh();
        });
    }

    /**
     * The record's history, newest first.
     *
     * @return Collection<int, ResidentStatusEvent>
     */
    public function history(Resident $resident): Collection
    {
        return ResidentStatusEvent::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return Collection<int, ResidentAlias>
     */
    public function aliases(Resident $resident): Collection
    {
        return ResidentAlias::query()
            ->where('resident_id', $resident->id)
            ->orderByDesc('recorded_at')
            ->get();
    }

    /**
     * Preserves a name tuple as an alias.
     *
     * Public because the merge service needs it for the absorbed record's name, and a
     * second implementation over there would be a second definition of what an alias is.
     *
     * @param  array<string, mixed>  $name
     */
    public function recordAlias(Resident $resident, array $name, string $source, ?string $sourceReference): void
    {
        // A "correction" that only reformatted the middle name still produces a tuple worth
        // keeping, but an alias identical to the record's current name is pure noise.
        ResidentAlias::query()->firstOrCreate(
            [
                'resident_id' => $resident->id,
                'first_name' => $name['first_name'],
                'middle_name' => $name['middle_name'],
                'last_name' => $name['last_name'],
                'suffix' => $name['suffix'],
            ],
            [
                'birth_date' => $name['birth_date'] ?? null,
                'source' => $source,
                'source_reference' => $sourceReference,
                'recorded_at' => now(),
            ],
        );
    }

    /**
     * Writes one history row. The only way an event is recorded.
     */
    public function recordEvent(
        Resident $resident,
        string $event,
        ?string $field,
        ?string $previousValue,
        ?string $newValue,
        ?string $reason,
        ActorContext $actor,
    ): void {
        ResidentStatusEvent::query()->create([
            'resident_id' => $resident->id,
            'event' => $event,
            'field' => $field,
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'reason' => $reason,
            'actor_subject_id' => $actor->subjectId,
            'occurred_at' => now(),
        ]);
    }

    /**
     * The base listing query, ordered for a human reading a directory.
     *
     * Returns a builder rather than results so the caller can apply its scope at the query
     * (AuthorizationService::scopeToBarangays) before anything is fetched or counted.
     *
     * @return Builder<Resident>
     */
    public function query(): Builder
    {
        return Resident::query()->orderBy('last_name')->orderBy('first_name');
    }

    /**
     * The name a record currently carries, as the alias table stores it.
     *
     * @return array<string, mixed>
     */
    public function nameTuple(Resident $resident): array
    {
        return [
            'first_name' => $resident->first_name,
            'middle_name' => $resident->middle_name,
            'last_name' => $resident->last_name,
            'suffix' => $resident->suffix,
            'birth_date' => $resident->birth_date,
        ];
    }

    /**
     * Renders a value the way the history columns store it — short text, comparable.
     *
     * Dates go through `toDateString()` so a Carbon and the '1990-01-15' a client sent
     * compare equal; without it every save would record a spurious change.
     */
    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
