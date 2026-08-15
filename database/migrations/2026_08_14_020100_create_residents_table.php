<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Residents — ResidentProfile's canonical record of a person the LGU serves (ADR 0010).
 *
 * THE ONE COPY. A resident's name, birth date and address live here and nowhere else.
 * Assistance requests, credentials and disbursements carry `resident_id`; a name copied
 * onto any of them is a second source of truth that will disagree after the first
 * correction (ADR 0008 §10).
 *
 * Personal-data classification: **sensitive**. This table is why most of the privacy rules
 * in CLAUDE.md Article 5 exist.
 *
 * `verification_tier` is a resident-level fact and deliberately NOT the same thing as
 * Identity's `email_verified`/`mobile_verified`, which only prove control of a contact
 * channel. Proving you can receive an SMS is not proving who you are.
 *
 * There is NO column for a full PhilSys number (RA 11055) and no column for a biometric
 * template. A field that does not exist cannot leak, and cannot be demanded later by
 * someone who assumes it is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Name held in parts: Filipino naming needs the suffix separate, and reports
            // and matching both need the family name on its own.
            $table->string('first_name', 96);
            $table->string('middle_name', 96)->nullable();
            $table->string('last_name', 96);
            $table->string('suffix', 16)->nullable();

            $table->enum('sex', ['female', 'male']);
            $table->date('birth_date');
            $table->enum('civil_status', ['single', 'married', 'widowed', 'separated', 'annulled', 'cohabiting']);

            // Same-module FK: jurisdiction is reference data, and a barangay with residents
            // must not vanish underneath them.
            $table->foreignId('barangay_id')->constrained('barangays')->restrictOnDelete();
            $table->string('street_address', 191);
            $table->string('purok_or_sitio', 96)->nullable();

            $table->string('mobile_number', 32)->nullable();
            $table->string('email', 191)->nullable();

            /*
             * PhilSys reference — LAST FOUR DIGITS ONLY (RA 11055). Encrypted even so:
             * four digits plus a name and birth date is a meaningful correlation key
             * against other datasets, and this column exists only to help a clerk confirm
             * they are looking at the right card.
             */
            $table->text('philsys_last_four')->nullable();

            // Integer centavos (ADR 0008). Used for means testing, so it is personal data
            // with a stated purpose, not a nice-to-have.
            $table->bigInteger('monthly_income_centavos')->nullable();

            /*
             * How well identity has been established. An explicit enumerated state, never
             * a boolean pair (Article 6), because "unverified" and "rejected after review"
             * are different facts with different consequences.
             */
            $table->enum('verification_tier', ['unverified', 'partially-verified', 'verified'])
                ->default('unverified');
            $table->timestampTz('verified_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Deactivation is a state, never a deletion: welfare record retention is
            // statutory and a deleted resident orphans the history that proves what was
            // paid to whom (ADR 0008 §3).
            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * The deterministic matching key (ADR 0010).
             *
             * A hash of normalised (last name + first name + birth date), stored so that
             * candidate lookup is an indexed equality search rather than a scan, and so
             * the index itself does not spell out residents' names.
             *
             * Deliberately NOT unique: two different people can share a name and birthday,
             * and a unique constraint here would silently block the second one from ever
             * registering. Duplicate detection is a review workflow, not a database error.
             */
            $table->string('identity_fingerprint', 64)->index('idx_residents_fingerprint');

            $table->index(['last_name', 'first_name'], 'idx_residents_name');
            $table->index('birth_date', 'idx_residents_birth_date');
            $table->index(['barangay_id', 'is_active'], 'idx_residents_barangay_active');
            $table->index('verification_tier', 'idx_residents_tier');
        });

        Schema::create('resident_sectors', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();

            // Open vocabulary: sectors are defined by statute and new ones arrive by
            // legislation, so widening a check constraint would be a table rewrite.
            $table->string('sector', 48);

            $table->timestampsTz();

            // The duplicate-relationship guard: tagging a resident twice with the same
            // sector would double every sectoral count the LGU reports.
            $table->unique(['resident_id', 'sector'], 'uniq_resident_sectors');
            $table->index('sector', 'idx_resident_sectors_sector');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_sectors');
        Schema::dropIfExists('residents');
    }
};
