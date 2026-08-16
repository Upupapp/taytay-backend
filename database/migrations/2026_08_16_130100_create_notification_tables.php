<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications and multi-channel delivery (ADR 0025).
 *
 * THE LINE THIS SCHEMA IS BUILT AROUND: `notifications` holds rendered text because it is read
 * back over an authenticated API; **a push payload holds a type and an identifier and nothing
 * else.** They are not the same content, and the difference is Article 8.4 — no PII, case
 * narrative, document identifier or welfare detail may reach a third-party push channel.
 *
 * A notification body reading "Your AICS assistance of ₱5,000 is ready for release at the
 * barangay hall" is correct in the app and a disclosure on a lock screen, on a shared phone, in
 * Google's logs. So the two are stored apart and rendered apart, and `notification_dispatches`
 * records that a push was sent without recording what it said.
 *
 * THREE TABLES:
 *
 *  * `notifications` — the record. One row per recipient per event.
 *  * `notification_dispatches` — one row per channel attempt, with the provider's verdict.
 *  * `notification_preferences` — what a person has opted out of, where opting out is allowed.
 *
 * THERE IS NO DEVICE TABLE HERE. Identity already owns `devices`, with a fingerprint, a trust
 * stamp, a revocation and a `push_token` column — and a device is an authentication concept before
 * it is a delivery one. A second registry would drift the moment somebody revoked a device in one
 * place and kept receiving push from the other (Article 6: one fact, one owning table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Identity account UUID. No FK — cross-module (Article 2.2), and a notification must
            // outlive a deactivated account for the audit question "were they told?".
            $table->uuid('recipient_subject_id');

            $table->string('type', 64);

            /*
             * The class decides whether it can be switched off.
             *
             * `mandatory` is a service or security notice the office must be able to send — a
             * scheduled release date, a security alert on an account. `optional` is everything
             * else. Storing the class on the notification rather than deriving it from the type
             * means a policy change is a data question, not a deploy.
             */
            $table->enum('category', ['mandatory', 'optional'])->default('optional');

            /*
             * RENDERED TEXT, held because this is read back over an authenticated API.
             *
             * It is NOT what goes to a push provider. See the class docblock: the same sentence
             * that is correct in the app is a disclosure on a lock screen.
             */
            $table->string('title', 160);
            $table->string('body', 500);

            /*
             * The deep link: a type and an identifier, which is also exactly what a push payload
             * may carry. The client opens the record through its own module's endpoint, where
             * authorization is rechecked.
             */
            $table->string('subject_type', 48)->nullable();
            $table->uuid('subject_id')->nullable();

            $table->string('priority', 16)->default('normal');

            $table->timestampTz('read_at')->nullable();

            $table->timestampsTz();

            $table->index(['recipient_subject_id', 'read_at'], 'idx_notifications_unread');
            $table->index(['recipient_subject_id', 'created_at'], 'idx_notifications_recent');
        });

        /*
         * One row per channel attempt.
         *
         * Separate from the notification because a notification is one decision and delivery is
         * several outcomes: the in-app copy always lands, the email may bounce, the push may find
         * a token that was revoked when the phone was wiped. Collapsing them into a status column
         * on the notification would mean the first failure overwrites the record of the success.
         */
        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();

            $table->string('channel', 24);
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');

            /*
             * The provider's own identifier for the message, where it gives one. Useful for
             * chasing a support ticket with the provider.
             *
             * NOT the payload, and there is deliberately no column for one. A stored push body
             * would be a second copy of exactly the content this design keeps out of the push
             * channel in the first place.
             */
            $table->string('provider_message_id', 128)->nullable();

            // Why it failed, in the provider's terms. Operational text; never the message.
            $table->string('failure_reason', 255)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            $table->timestampsTz();

            $table->unique(['notification_id', 'channel'], 'uniq_dispatch_per_channel');
            $table->index(['status', 'last_attempted_at'], 'idx_dispatch_retry');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('subject_id');

            // What they are choosing about: a notification type, or `*` for a whole channel.
            $table->string('notification_type', 64);
            $table->string('channel', 24);

            /*
             * Opt-OUT, not opt-in: a row exists only when somebody has switched something off.
             *
             * An absent row means "on", which is the right default for a service that has to be
             * able to tell people things — and it means a new notification type does not silently
             * reach nobody because no preference row exists for it yet.
             */
            $table->boolean('enabled')->default(true);

            $table->timestampsTz();

            $table->unique(['subject_id', 'notification_type', 'channel'], 'uniq_notification_prefs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('notifications');
    }
};
