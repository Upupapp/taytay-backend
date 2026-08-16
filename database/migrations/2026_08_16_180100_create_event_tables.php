<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Official LGU events (ADR 0030).
 *
 * THERE IS NO `registration_availability` COLUMN, AND THAT IS THE DESIGN. The master command asks
 * for availability to be *derived* from the configured times and capacity rather than stored as a
 * contradictory copy, and the reason is that a stored one is wrong the moment the clock moves past
 * it: a column saying `open` at 17:00 for a registration that closed at 16:59 needs something to
 * notice and rewrite it, and whatever that something is will one day not run.
 *
 * Derived, the answer is always current, a missed job is impossible, and there is no second
 * source to disagree with the first.
 *
 * TIMESTAMPS ARE UTC AND THE TIMEZONE IS RECORDED SEPARATELY. Article 4 requires UTC storage; an
 * event also has a *local scheduling context* — "9am at the covered court" means 9am in Manila,
 * and a daylight-free timezone does not make the distinction unnecessary, it makes it cheap to
 * get right and easy to forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * The public identifier a citizen sees in a URL.
             *
             * Human-readable on purpose: an event is public information that people share by
             * pasting a link, and a UUID in a poster QR code helps nobody. It is unique and
             * immutable once published — changing it would break every link already handed out.
             */
            $table->string('slug', 120)->unique();

            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->text('description');
            $table->string('category', 48);

            // Files module UUID. No FK — cross-module (Article 2.2).
            $table->uuid('cover_file_id')->nullable();

            /*
             * Required whenever there IS a cover. Not nullable-and-hopeful: an event poster a
             * blind resident cannot read is an event they were not invited to (ADR 0028 §7, same
             * rule).
             */
            $table->string('cover_alt_text', 255)->nullable();

            // UTC, always (Article 4).
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            /*
             * The local scheduling context, stored because it is a fact about the event rather
             * than a rendering preference. An event moved to another timezone would be a
             * different event.
             */
            $table->string('timezone', 48)->default('Asia/Manila');

            $table->string('venue_name', 160);
            $table->string('venue_address', 255);

            /*
             * An optional link, never coordinates.
             *
             * A URL somebody typed is a pointer to a public place. A latitude and longitude
             * column would be the beginning of the location model TAB 17 refused, arriving
             * through a door marked "convenience" (ADR 0022 §1).
             */
            $table->string('map_url', 500)->nullable();

            $table->string('contact_office', 160)->nullable();
            $table->string('contact_person', 160)->nullable();
            $table->string('contact_number', 32)->nullable();

            /*
             * ── registration configuration ────────────────────────────────────────────────
             *
             * Configuration only. Whether registration is open *right now* is computed from these
             * plus a live count — see the class docblock.
             */
            $table->boolean('registration_required')->default(false);
            $table->timestampTz('registration_opens_at')->nullable();
            $table->timestampTz('registration_closes_at')->nullable();

            // Null means uncapped, which is different from zero. A capacity of zero is an event
            // nobody may attend, and somebody will eventually mean it.
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(false);

            $table->string('participation_note', 500)->nullable();
            $table->string('participant_instructions', 1000)->nullable();

            $table->string('status', 16)->default('draft');

            $table->uuid('author_subject_id')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            /*
             * Why it was called off, and it is shown to the public.
             *
             * A cancelled event that was already published stays visible — people arranged their
             * day around it, and removing it silently means somebody travels to a covered court
             * to find nobody there.
             */
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestampsTz();

            $table->index(['status', 'starts_at'], 'idx_events_public');
            $table->index(['category', 'starts_at'], 'idx_events_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
