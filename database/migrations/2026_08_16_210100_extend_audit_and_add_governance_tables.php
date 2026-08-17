<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit enrichment, privacy notices, consent and retention governance (ADR 0034).
 *
 * THE AUDIT TABLE GAINS FIVE COLUMNS AND NONE OF THEM HOLDS A VALUE. That is the shape of every
 * addition here: an account type, a risk band, a list of field *names*, a reason for a sensitive
 * act, and the network identifiers policy permits. Never the old value, never the new one, never
 * the case narrative, never the identifier that was changed.
 *
 * The master command is explicit — "do NOT copy full case notes, passwords, raw ID numbers or
 * entire resident objects into generic audit payloads" — and the reason bears stating: an audit
 * trail is read by operators investigating something else entirely, is retained longer than most
 * records, and is exported for compliance review. A trail that duplicates the data it protects is
 * a second, less-guarded copy of it.
 *
 * `changed_fields` is therefore a comma-separated list of COLUMN NAMES, and
 * `AuditRedactionTest` fails the build if a persisted value looks like a real identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            /*
             * Citizen or staff, at the moment of the act.
             *
             * Denormalised on purpose. An account that was a citizen in March and is a clerk today
             * must still read as a citizen in March's trail — resolving it live would silently
             * rewrite history every time somebody's role changed.
             */
            $table->string('actor_account_type', 24)->nullable()->after('actor_subject_id');

            /*
             * routine | high
             *
             * Declared by the action catalogue rather than judged per call, so "which of these
             * matter" has one answer. The master command lists the high-risk acts; they are
             * enumerated in `AuditAction` and this column is derived from that.
             */
            $table->string('risk', 16)->default('routine')->after('action');

            /*
             * WHICH FIELDS CHANGED, BY NAME. Never what they changed from or to.
             *
             * "Somebody altered this resident's birth date on Tuesday" is the finding an
             * investigation needs; the birth date itself is already in the record being
             * investigated, and putting it here as well means a leak of the audit trail is a leak
             * of the data.
             */
            $table->string('changed_fields', 512)->nullable()->after('summary');

            /*
             * Why, for acts that require one.
             *
             * Only ever populated from a reason the actor typed for THIS purpose — never lifted
             * from a case note or a rejection justification, which are written for a colleague and
             * belong to the record rather than to the trail.
             */
            $table->string('reason', 500)->nullable()->after('changed_fields');

            /*
             * WHERE POLICY PERMITS, and the default is that it does not.
             *
             * An IP address is personal data under RA 10173. Recording one on every routine read
             * builds a movement log of the office's own staff; recording one on a sensitive
             * download is proportionate evidence. So it is captured only for `high` risk entries
             * and only when `audit.capture_network` is on — a decision for the LGU's DPO, not a
             * default this repository picks (ADR 0034 §3).
             */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['risk', 'occurred_at'], 'idx_audit_entries_risk');
        });

        /*
         * ── privacy notices ───────────────────────────────────────────────────────────
         *
         * The versions of the privacy notice this system has shown people. Immutable once
         * published: an acknowledgement points at a version, and editing that version in place
         * would silently rewrite what somebody agreed to.
         */
        Schema::create('privacy_notices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Human-readable and ordered, e.g. `2026.1`. Referenced by every acknowledgement.
            $table->string('version', 24)->unique();

            $table->string('title', 160);
            // A pointer, not the text. The notice itself is a published document maintained by the
            // DPO; duplicating its wording here creates a second version to keep in step.
            $table->string('document_url', 500)->nullable();
            $table->string('summary', 1000);

            $table->timestampTz('effective_from');
            // Null while current. Set when a later version supersedes it — never deleted, because
            // acknowledgements of it must stay explicable.
            $table->timestampTz('superseded_at')->nullable();

            $table->timestampsTz();

            $table->index('effective_from', 'idx_privacy_notices_effective');
        });

        /*
         * ── acknowledgements ──────────────────────────────────────────────────────────
         *
         * That a person was SHOWN a version, not that they consented to anything. The distinction
         * is the point — see `consent_records` below.
         */
        Schema::create('privacy_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('privacy_notice_id')->constrained('privacy_notices')->cascadeOnDelete();

            // Cross-module reference to an Identity account. No FK (Article 2.2).
            $table->uuid('subject_id');

            $table->timestampTz('acknowledged_at');
            // Telemetry, as everywhere else. Which surface somebody was using when they read it.
            $table->string('client_channel', 32)->nullable();

            $table->timestampsTz();

            // One acknowledgement per person per version. Re-acknowledging is a no-op, not a
            // second row, so a count of acknowledgements is a count of people.
            $table->unique(['privacy_notice_id', 'subject_id'], 'uniq_privacy_ack');
            $table->index('subject_id', 'idx_privacy_ack_subject');
        });

        /*
         * ── consent ───────────────────────────────────────────────────────────────────
         *
         * **ONLY WHERE CONSENT IS ACTUALLY THE LEGAL BASIS**, which for an LGU is the minority of
         * processing and never the important parts (ADR 0034 §4).
         *
         * Recording statutory processing as "consent" is the classic privacy-engineering error,
         * and it is not a labelling mistake — it is a promise. Consent implies a right to
         * withdraw, and an office that offers withdrawal for processing it is legally obliged to
         * perform must either break the promise or break the law. Both are worse than never
         * having made it.
         */
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->uuid('subject_id');
            // The resident the consent is about, which may not be the account that gave it — a
            // guardian consenting for a minor is a real arrangement (ADR 0013 §5).
            $table->uuid('resident_id')->nullable();

            /*
             * What was consented to. A closed vocabulary from `config/privacy.php` rather than
             * free text, so "what has this person agreed to" is answerable by a query and a
             * withdrawal can actually find what to withdraw.
             */
            $table->string('purpose', 64);

            $table->timestampTz('granted_at');
            /*
             * Withdrawal is a TIMESTAMP, not a deleted row. "Did she ever agree, and when did she
             * change her mind" is the question a complaint asks, and a deleted row answers
             * neither.
             */
            $table->timestampTz('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 500)->nullable();

            // Which notice version was in force when it was given.
            $table->string('notice_version', 24)->nullable();
            $table->string('evidence', 255)->nullable();

            $table->string('client_channel', 32)->nullable();
            $table->timestampsTz();

            /*
             * At most one LIVE consent per subject per purpose, using the same portable derived-key
             * trick as TAB 14 and TAB 26: the purpose while active, NULL once withdrawn. NULLs are
             * distinct in a unique index on both Postgres and SQLite, so withdrawn history
             * accumulates freely and two live grants are impossible.
             */
            $table->string('active_key', 64)->nullable();

            $table->unique(['subject_id', 'active_key'], 'uniq_consent_active');
            $table->index(['resident_id', 'purpose'], 'idx_consent_resident_purpose');
        });

        /*
         * ── legal holds ───────────────────────────────────────────────────────────────
         *
         * A record under hold is not purged, whatever its retention category says.
         *
         * THE HOLD OUTRANKS THE SCHEDULE, always and in one direction only. Retention deletion is
         * irreversible: a record destroyed on schedule during an ongoing investigation cannot be
         * un-destroyed, whereas a record kept too long can still be deleted tomorrow. So the
         * asymmetry is deliberate — a hold can only ever prevent deletion, never cause one.
         */
        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // What is held. `entity_type` matches the audit vocabulary so the two can be read
            // together; `entity_id` null means the whole subject.
            $table->string('entity_type', 64);
            $table->uuid('entity_id')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->string('reference', 96);
            $table->string('reason', 500);

            $table->uuid('placed_by')->nullable();
            $table->timestampTz('placed_at');

            // Lifting is recorded, never deleted: "who lifted the hold, and when" is the question
            // after a record turns out to have been destroyed.
            $table->uuid('lifted_by')->nullable();
            $table->timestampTz('lifted_at')->nullable();
            $table->string('lift_reason', 500)->nullable();

            $table->timestampsTz();

            $table->index(['entity_type', 'entity_id'], 'idx_legal_holds_entity');
            $table->index(['subject_id', 'lifted_at'], 'idx_legal_holds_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('privacy_acknowledgements');
        Schema::dropIfExists('privacy_notices');

        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex('idx_audit_entries_risk');
            $table->dropColumn([
                'actor_account_type', 'risk', 'changed_fields', 'reason', 'ip_address', 'user_agent',
            ]);
        });
    }
};
