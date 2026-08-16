<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Illuminate\Support\Facades\DB;
use Modules\Events\Domain\RegistrationStatus;
use Modules\Events\Infrastructure\Eloquent\EventRegistration;
use Modules\ResidentProfile\Contracts\ResidentMerged;

/**
 * A merge repoints this module's registrations (ADR 0019 §4, ADR 0031 §7).
 *
 * A LISTENER RATHER THAN A CALL, because Events depends on ResidentProfile and the reverse call
 * would close a cycle the boundary map forbids. It runs synchronously inside the merge
 * transaction, so a committed merge never leaves a registration pointing at a soft-deleted
 * resident — a person on the door list whom nobody can look up.
 *
 * THE HARD PART IS NOT THE UPDATE, IT IS THE COLLISION. If both records were registered for the
 * same event — which is precisely what a duplicate resident looks like, the same person signing up
 * twice under two records — a blind repoint breaches `uniq_event_registrations_active` and takes
 * the whole merge down. Worse, a repoint that swallowed the constraint error would leave the merge
 * half-applied.
 *
 * So collisions are resolved deliberately: **the earlier registration survives.** Not the
 * survivor's, the *earlier* one. The queue position a person earned belongs to the person, not to
 * whichever of their two records the office happened to keep — and demoting somebody to the back
 * of a waitlist because an administrator merged their file is a real harm from an invisible cause.
 */
final class ReassignEventRegistrationsOnResidentMerge
{
    public function handle(ResidentMerged $event): int
    {
        return DB::transaction(function () use ($event): int {
            $moved = 0;

            /** @var list<EventRegistration> $absorbed */
            $absorbed = EventRegistration::query()
                ->where('resident_id', $event->absorbedResidentUuid)
                ->orderBy('id')
                ->get()
                ->all();

            foreach ($absorbed as $registration) {
                $rival = $registration->active_key === null
                    ? null
                    : EventRegistration::query()
                        ->where('event_id', $registration->event_id)
                        ->where('active_key', $event->survivorResidentUuid)
                        ->first();

                if ($rival !== null) {
                    // Both records hold a live place at the same event. The earlier one keeps it.
                    if ((int) $registration->id < (int) $rival->id) {
                        $this->standDown($rival);
                    } else {
                        $this->standDown($registration);
                    }
                }

                $registration->forceFill([
                    'resident_id' => $event->survivorResidentUuid,
                    // Follows the status: still live means still colliding, cancelled means NULL.
                    'active_key' => $registration->status === RegistrationStatus::Cancelled
                        ? null
                        : $event->survivorResidentUuid,
                ])->save();

                $moved++;
            }

            return $moved;
        });
    }

    /**
     * Retires the losing registration, saying so.
     *
     * Cancelled rather than deleted: somebody looking at this later must be able to see that two
     * places existed and why one stopped, not find a single row and wonder.
     */
    private function standDown(EventRegistration $registration): void
    {
        $registration->forceFill([
            'status' => RegistrationStatus::Cancelled,
            'active_key' => null,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Duplicate registration collapsed by a resident merge.',
        ])->save();
    }
}
