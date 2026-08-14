<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts — Identity's canonical store (ADR 0008, ERD § Identity).
 *
 * AN ACCOUNT IS NOT A PERSON. It is a way to authenticate. The resident it may act for
 * lives in ResidentProfile, and the two are deliberately not 1:1: a resident can exist
 * with no account (walk-in, assisted registration), and one account may later be
 * authorised to act for several residents (guardian, representative). Collapsing them
 * would force a rewrite the moment the first guardian case arrives.
 *
 * `resident_id` is therefore nullable and carries NO foreign key — it is a cross-module
 * reference (CLAUDE.md Article 2.2). Holding it here does not grant access to that
 * resident's records: authorization is a separate decision made by AccessControl from the
 * actor's permissions (ADR 0002).
 *
 * Personal-data classification: `personal`. Email and mobile identify a person and are
 * used to authenticate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Closed set, fixed by design: it decides which authentication flows apply
            // and whether MFA is mandatory. Earns a check constraint (ADR 0008 §5).
            $table->enum('account_type', ['citizen', 'staff']);

            // Both nullable: staff authenticate by email + password, citizens by mobile +
            // one-time code. Unique where present, so a contact cannot be claimed twice.
            $table->string('email', 191)->nullable()->unique();
            $table->string('mobile_number', 32)->nullable()->unique();

            // Null for accounts that have no password at all (citizen OTP flow). Hashed
            // with the application hasher; never a reversible encoding.
            $table->string('password_hash')->nullable();

            $table->string('display_name', 128);

            $table->enum('status', ['pending', 'active', 'suspended', 'deactivated'])
                ->default('pending');

            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('mobile_verified_at')->nullable();
            $table->timestampTz('last_signed_in_at')->nullable();

            // Lockout state. Counted server-side so a client cannot reset it by retrying
            // from a new device (OWASP ASVS V2.2 — anti-automation).
            $table->unsignedSmallInteger('failed_sign_in_count')->default(0);
            $table->timestampTz('locked_until')->nullable();

            // ResidentProfile's resident UUID. No FK — see the class docblock.
            $table->uuid('resident_id')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status', 'idx_accounts_status');
            $table->index('account_type', 'idx_accounts_type');
            $table->index('resident_id', 'idx_accounts_resident');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
