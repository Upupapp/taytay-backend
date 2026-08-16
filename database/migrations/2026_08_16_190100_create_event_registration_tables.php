<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event registration, waitlist and attendance (ADR 0031).
 *
 * THERE IS NO `registered_count` COLUMN ON `events`, AND THAT IS THE POINT OF THE TAB.
 *
 * A counter is the obvious way to answer "is there room", and it is the wrong one. A counter and
 * the rows it counts are two sources of the same fact, and they drift: a failed insert that
 * incremented, a cancellation that forgot to decrement, a restore that decremented twice. When
 * they disagree, the counter wins — because the counter is what the capacity check reads — and the
 * office oversells a covered court by four seats without a single error in the log.
 *
 * The count is taken from committed rows, inside the same lock that decides the outcome. That is
 * exactly what "cannot exceed capacity **according to committed backend state**" asks for.
 *
 * NOTE ON `active_key`. Postgres and SQLite both treat NULLs as distinct in a unique index, so a
 * nullable column holding the resident id while the registration is live and NULL once it is
 * cancelled gives "at most one active registration per resident per event" **and** unlimited
 * cancelled history — portably, with no partial index and no `DB::statement` (Article 1). The
 * same trick TAB 14 used for open enrolments, for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Same module, so a real foreign key. Deleting an event is not a thing this system
            // does — events archive — but if one ever were, its registrations mean nothing.
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            /*
             * The person, not the login. Cross-module, so no FK (Article 2.2).
             *
             * Registration is keyed on the RESIDENT because a household may share one account and
             * one account may act for several residents (boundary map). Keying on the account
             * would let a mother and daughter registered from the same phone collapse into one
             * seat, or let one person hold two by signing in twice.
             */
            $table->uuid('resident_id');

            // Who actually pressed the button, for the audit trail. Never the identity the
            // registration belongs to.
            $table->uuid('account_subject_id')->nullable();

            /*
             * What gets read out at the door.
             *
             * Short, human-transcribable, and unique. A UUID is unusable by somebody reading it
             * from a phone screen to a volunteer with a clipboard.
             */
            $table->string('reference', 24)->unique();

            $table->string('status', 16);
            $table->timestampTz('registered_at');

            // Set when a waitlisted registration was promoted. Kept because "was I always in, or
            // did I get in later?" is a question people ask, and because it evidences that the
            // promotion ran.
            $table->timestampTz('promoted_at')->nullable();

            $table->string('attendance', 20)->default('not-checked-in');
            $table->timestampTz('attendance_marked_at')->nullable();
            $table->uuid('attendance_marked_by')->nullable();

            // Telemetry only. Article 3.3: the channel is recorded and grants nothing.
            $table->string('source_channel', 32)->nullable();

            /*
             * STAFF-ONLY. Never projected to the registrant.
             *
             * "Needs a wheelchair space", "brought three children last time" — operationally
             * useful, and written in the register's voice rather than the resident's. The
             * citizen projection is a separate method that does not name this column, and
             * `EventRegistrationTest` asserts a resident cannot read it.
             */
            $table->string('staff_notes', 1000)->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            /*
             * The resident id while this registration is LIVE, NULL once it is cancelled.
             *
             * Unique with `event_id`. Two live registrations for one person are impossible at the
             * database, not merely at the service — which is what makes the retry criterion hold
             * even for a client that sends no idempotency key.
             */
            $table->uuid('active_key')->nullable();

            $table->timestampsTz();

            $table->unique(['event_id', 'active_key'], 'uniq_event_registrations_active');

            /*
             * The waitlist queue index. `id` is the tie-break and the ordering: it is monotonic,
             * it needs no maintenance, and it cannot drift from insertion order the way a stored
             * `waitlist_position` would every time somebody in the middle cancels.
             */
            $table->index(['event_id', 'status', 'id'], 'idx_event_registrations_queue');
            $table->index(['resident_id', 'status'], 'idx_event_registrations_resident');
            $table->index(['event_id', 'attendance'], 'idx_event_registrations_attendance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
