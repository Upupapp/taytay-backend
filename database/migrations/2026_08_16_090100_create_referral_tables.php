<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals and the service provider directory (ADR 0021).
 *
 * A REFERRAL IS THE ONE RECORD THAT LEAVES THE BUILDING. Every other table here describes
 * something the MSWDO holds; this one describes something it hands to another organisation, after
 * which it no longer controls who reads it and nothing can be taken back.
 *
 * That single fact shapes the whole schema. The disclosure is not a flag on the referral — it is
 * an explicit, itemised, reasoned record of exactly what was released and on what authority,
 * because "we referred them to DSWD" is not an answer to "what did DSWD receive about my client".
 *
 * FIVE TABLES:
 *
 *  * `service_providers` — the directory. A directory rather than a free-text field because
 *    "PhilHealth Rizal", "Philhealth - Rizal" and "PHIC Rizal" are three spellings of one office,
 *    and once they exist nobody can be told whether anyone has heard back.
 *  * `referrals` — the routing itself, its lifecycle and its follow-up commitment.
 *  * `referral_shared_fields` — **one row per field released**, each with a reason.
 *  * `referral_attachments` — **one row per document released**, each with a reason.
 *  * `referral_notes` — append-only, and split by who may read them.
 *
 * The last three are separate tables rather than JSON columns on the referral precisely because
 * they are the audit trail of a disclosure. A JSON blob is not queryable by "which referrals
 * released an address", which is the first question asked after a protection incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('name', 160);

            // The kind of office this is — DSWD field office, hospital MSW, PESO, WCPD. Drives
            // which referrals may sensibly be sent here.
            $table->string('destination_type', 48);

            $table->enum('status', ['active', 'suspended', 'retired'])->default('active');

            $table->string('address', 255)->nullable();
            $table->foreignId('barangay_id')->nullable()->constrained('barangays')->nullOnDelete();

            $table->string('contact_person', 120)->nullable();
            $table->string('contact_position', 120)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 160)->nullable();

            /*
             * How long this office usually takes. Feeds the default follow-up date.
             *
             * **This office's observation, not the provider's promise.** Naming it that way
             * matters: a worker chasing on day 8 should know they are applying an internal
             * convention, not enforcing an agreement the provider would recognise.
             */
            $table->unsignedSmallInteger('usual_response_days')->nullable();

            $table->string('notes', 500)->nullable();

            // Who last confirmed this entry is still correct. A directory nobody re-checks is a
            // list of disconnected numbers within two years.
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();

            $table->unique('name', 'uniq_service_providers_name');
            $table->index(['destination_type', 'status'], 'idx_service_providers_type');
        });

        /*
         * ── what each provider does, and how to reach it ──────────────────────────────────
         *
         * CHILD TABLES RATHER THAN JSON COLUMNS ON THE PROVIDER, and the first draft of this
         * migration got that wrong. ADR 0008 §13 permits JSON only for an opaque external
         * payload, a replayed HTTP response, or annotation nobody queries — and neither of these
         * is any of those.
         *
         * `channels` is a **closed vocabulary** validated on the way in, which is precisely the
         * kind of relationship a JSON array cannot constrain. `services_offered` is what a worker
         * actually searches when choosing where to send a family — "who does bill reduction" is
         * the question the directory exists to answer, and inside a blob it is a table scan and a
         * LIKE against punctuation.
         *
         * Both are one row per value with a unique key, so the vocabulary is enforceable and the
         * search is indexed.
         */
        Schema::create('service_provider_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_provider_id')->constrained('service_providers')->cascadeOnDelete();

            // How a referral reaches them. Recorded because it decides what this office can
            // promise: a referral relayed on paper cannot be chased by phone the same afternoon.
            $table->enum('channel', ['letter', 'email', 'phone', 'in-person', 'system']);

            $table->unique(['service_provider_id', 'channel'], 'uniq_provider_channels');
            $table->index('channel', 'idx_provider_channels_channel');
        });

        Schema::create('service_provider_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_provider_id')->constrained('service_providers')->cascadeOnDelete();

            /*
             * What this office ACTUALLY does, in the words staff would use. Open vocabulary —
             * "medical social work", "bill reduction", "temporary shelter" — because no fixed
             * list survives contact with what partner agencies actually offer.
             *
             * Carried so a referral is not sent to an office that does not do this work, which
             * costs the family a trip they cannot afford and loses days nobody gets back.
             */
            $table->string('service', 120);

            $table->unique(['service_provider_id', 'service'], 'uniq_provider_services');
            $table->index('service', 'idx_provider_services_service');
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Quoted by the receiving office when they call back, so it is short, printable and
            // stable for the life of the referral.
            $table->string('reference_number', 32)->unique();

            /*
             * ALWAYS LINKED TO A CLIENT. The acceptance criterion, and not merely a convention:
             * a referral with no resident is a disclosure about nobody in particular, which
             * cannot be audited, cannot be answered to a subject-access request, and cannot be
             * repointed when two records turn out to be one person.
             *
             * ResidentProfile's UUID. No FK — cross-module (Article 2.2). Repointed on merge by
             * ReassignWelfareRecordsOnResidentMerge (ADR 0019 §4).
             */
            $table->uuid('resident_id');

            /*
             * The case, where there is one — and there is not always.
             *
             * A family may be referred to a hospital's medical social worker with no assistance
             * request open at the time. Requiring a case would force staff to open a fictitious
             * one, and a fictitious case distorts every count built on cases afterwards.
             */
            $table->foreignId('welfare_case_id')->nullable()->constrained('welfare_cases')->nullOnDelete();

            // The directory entry, where the destination is one the office keeps on file. No FK —
            // ServiceCatalog owns the directory (Article 2.2).
            $table->uuid('provider_id')->nullable();

            /*
             * Denormalised from the provider, deliberately and permanently.
             *
             * A referral is a record of what was sent, to whom, on a date. If the directory entry
             * is later renamed or retired, the referral must still say where it actually went —
             * so this is a SNAPSHOT, not a cache, and it is never refreshed (ADR 0021 §2).
             */
            $table->string('destination_type', 48);
            $table->string('destination_name', 160);
            $table->string('destination_contact', 160)->nullable();

            $table->string('status', 32)->default('draft');
            $table->string('urgency', 16)->default('routine');

            // What is being asked for, in the words the receiving office will read.
            $table->string('service_requested', 255);
            $table->string('reason', 500);

            /*
             * ── the lawful basis ──────────────────────────────────────────────────────────
             *
             * Required before a referral may be SENT, and absent on a draft. Consent is the
             * ordinary case; the other two exist because insisting on written consent from
             * somebody unconscious in an emergency room, or from a child at risk, would be its
             * own kind of failure (RA 10173).
             */
            $table->enum('disclosure_basis', ['client-consent', 'statutory-mandate', 'vital-interest'])->nullable();

            // What the client was told, or which law applies, or what the risk was. Mandatory
            // with the basis — a basis with no note is a checkbox, not a record.
            $table->string('disclosure_note', 500)->nullable();
            $table->uuid('disclosure_recorded_by')->nullable();
            $table->timestampTz('disclosure_recorded_at')->nullable();

            $table->uuid('referred_by')->nullable();
            $table->timestampTz('referred_at');
            $table->timestampTz('sent_at')->nullable();

            /*
             * When THIS office intends to chase. Derived from urgency, then editable.
             *
             * A default, not a rule: a provider that answers in a day and one that answers in a
             * month are both real, and neither is described by a constant.
             */
            $table->date('follow_up_on')->nullable();

            $table->timestampTz('responded_at')->nullable();
            $table->string('outcome', 500)->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'follow_up_on'], 'idx_referrals_overdue');
            $table->index(['resident_id', 'status'], 'idx_referrals_resident');
            $table->index(['welfare_case_id', 'status'], 'idx_referrals_case');
            $table->index(['provider_id', 'status'], 'idx_referrals_provider');
        });

        /*
         * ── what was released, itemised ───────────────────────────────────────────────────
         *
         * ONE ROW PER FIELD, each with a mandatory reason.
         *
         * A single "share full profile" switch would be ticked once and forgotten. Naming each
         * field separately makes every one of them a decision somebody made and can be asked
         * about — and makes "which referrals released a home address" an indexed query rather
         * than a manual read of every disclosure.
         */
        Schema::create('referral_shared_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();

            $table->string('field', 48);

            // Why the RECEIVING office needs it. An unexplained field is not shared.
            $table->string('because', 255);

            $table->uuid('chosen_by')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['referral_id', 'field'], 'uniq_referral_shared_fields');
        });

        Schema::create('referral_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();

            // The Files module's document UUID. No FK — cross-module.
            $table->uuid('document_id');

            // What the receiving office will see it called on the sheet.
            $table->string('label', 160);
            $table->string('because', 255);

            $table->uuid('chosen_by')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['referral_id', 'document_id'], 'uniq_referral_attachments');
        });

        /*
         * Append-only, and SPLIT BY AUDIENCE.
         *
         * A handoff note is written for the receiving office and travels with the referral. An
         * internal note is this office talking to itself about a case — a worker's doubt, a
         * safeguarding concern, a judgement about a family. The two must not share a column
         * with a flag added later, because the flag is what gets forgotten on the day somebody
         * exports the lot.
         */
        Schema::create('referral_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('referral_id')->constrained('referrals')->cascadeOnDelete();

            $table->enum('audience', ['internal', 'receiving-office']);
            $table->string('body', 1000);

            $table->uuid('author_subject_id')->nullable();
            $table->timestampTz('recorded_at');

            // No updated_at: append-only.
            $table->timestampTz('created_at')->nullable();

            $table->index(['referral_id', 'audience'], 'idx_referral_notes_audience');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_notes');
        Schema::dropIfExists('referral_attachments');
        Schema::dropIfExists('referral_shared_fields');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('service_provider_services');
        Schema::dropIfExists('service_provider_channels');
        Schema::dropIfExists('service_providers');
    }
};
