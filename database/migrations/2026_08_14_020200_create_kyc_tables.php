<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC — the onboarding case, its documents, and the match candidates a reviewer decides on
 * (ADR 0010).
 *
 * THE CENTRAL SEPARATION: an account (Identity), a KYC case (here) and a canonical
 * resident (`residents`) are three different things.
 *
 *   account   — someone can sign in
 *   kyc_case  — someone *claims* to be a particular person, and it is being checked
 *   resident  — the LGU's record of a person it serves
 *
 * Registration creates the first two. Only a human reviewer creates or links the third.
 * That is what stops a duplicate verified resident appearing every time somebody
 * re-registers with a slightly different spelling.
 *
 * Personal-data classification: **sensitive**. The `claimed_*` columns hold what an
 * unverified applicant asserted about themselves, which is exactly as sensitive as the
 * verified version and rather less trustworthy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Identity account UUID. No FK — cross-module reference (Article 2.2).
            $table->uuid('account_id');

            /*
             * The lifecycle (ADR 0010 §2). An explicit state machine with recorded
             * transitions, never a status assigned directly.
             */
            $table->enum('status', [
                'draft',
                'submitted',
                'screening',
                'manual-review',
                'needs-more-information',
                'approved',
                'rejected',
                'withdrawn',
                'expired',
            ])->default('draft');

            // What the applicant claims. Held separately from `residents` until a reviewer
            // accepts it — writing straight into the canonical record is how unverified
            // assertions quietly become official data.
            $table->string('claimed_first_name', 96);
            $table->string('claimed_middle_name', 96)->nullable();
            $table->string('claimed_last_name', 96);
            $table->string('claimed_suffix', 16)->nullable();
            $table->date('claimed_birth_date');
            $table->enum('claimed_sex', ['female', 'male']);
            $table->foreignId('claimed_barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->string('claimed_street_address', 191);

            // Same normalised hash as residents.identity_fingerprint, so candidate lookup
            // is an indexed equality search rather than a scan.
            $table->string('identity_fingerprint', 64)->index('idx_kyc_cases_fingerprint');

            // Set only when a reviewer approves. Identifier only, consistent with every
            // other cross-record reference here.
            $table->uuid('resolved_resident_id')->nullable();

            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();

            // Shown to the applicant. Deliberately separate from internal reviewer notes,
            // which live on the transition rows — the applicant sees the decision, not the
            // deliberation (visibility matrix §1).
            $table->string('applicant_message', 255)->nullable();

            $table->timestampTz('submitted_at')->nullable();

            /*
             * Retention. A KYC case holds identity documents that exist to answer one
             * question — is this person who they say they are — and must not be kept
             * indefinitely once it is answered (RA 10173 storage limitation).
             */
            $table->timestampTz('purge_after')->nullable();
            $table->timestampTz('purged_at')->nullable();

            $table->timestampsTz();

            /*
             * One OPEN case per account is an application invariant rather than a unique
             * index: the key would have to include a nullable "closed" marker, and on
             * PostgreSQL NULLs compare distinct, so the constraint would silently permit
             * exactly the duplicates it was meant to stop (ADR 0008 §5). It is enforced in
             * KycCaseService inside a transaction and asserted by test.
             */
            $table->index(['account_id', 'status'], 'idx_kyc_cases_account_status');
            $table->index('status', 'idx_kyc_cases_status');
            $table->index('purge_after', 'idx_kyc_cases_purge_after');
        });

        Schema::create('kyc_case_transitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('kyc_case_id')->constrained('kyc_cases')->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            // Internal. Never shown to the applicant (visibility matrix §3).
            $table->string('reason', 255)->nullable();

            $table->uuid('actor_subject_id')->nullable();
            $table->timestampTz('occurred_at');

            // Append-only: created_at only, nowhere to record a change.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['kyc_case_id', 'occurred_at'], 'idx_kyc_transitions_case');
        });

        Schema::create('kyc_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('kyc_case_id')->constrained('kyc_cases')->cascadeOnDelete();

            $table->string('document_type', 48);

            /*
             * A POINTER, NOT THE IMAGE. The file lives on the private `object-storage`
             * disk (ADR 0004) and reaches a viewer through an authorization-gated endpoint
             * or a short-lived signed URL. Putting scans in the database would put them in
             * every backup, replica and dump.
             */
            $table->string('storage_path', 255);
            $table->string('content_hash', 64);
            $table->unsignedInteger('byte_size');
            $table->string('mime_type', 96);

            /*
             * Deliberately absent: extracted document numbers. The reviewer reads the
             * image; storing the number as well would create a second copy of a government
             * identifier for no operational gain (data minimisation).
             */

            $table->enum('review_status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->string('review_note', 255)->nullable();

            // Set when the object is purged from storage but the row is kept as evidence
            // that a document was supplied and reviewed.
            $table->timestampTz('deleted_from_storage_at')->nullable();

            $table->timestampsTz();

            $table->index(['kyc_case_id', 'review_status'], 'idx_kyc_documents_case');
        });

        Schema::create('resident_match_candidates', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('kyc_case_id')->constrained('kyc_cases')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            // Which deterministic rule produced this candidate. Recorded so a reviewer can
            // see *why* the system thinks these might be the same person, rather than
            // being handed an unexplained number.
            $table->string('rule', 48);

            /*
             * A coarse band, not a probability. "0.87" invites a reviewer to read it as
             * certainty and click through; "exact" and "partial" invite them to look.
             */
            $table->enum('confidence', ['exact', 'strong', 'partial']);

            $table->enum('decision', ['undecided', 'same-person', 'different-person'])
                ->default('undecided');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();

            // One candidate row per (case, resident): re-running screening must refresh the
            // row, not stack duplicates in front of the reviewer.
            $table->unique(['kyc_case_id', 'resident_id'], 'uniq_match_candidates_case_resident');
            $table->index('decision', 'idx_match_candidates_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_match_candidates');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_case_transitions');
        Schema::dropIfExists('kyc_cases');
    }
};
