<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secrets that let an account prove itself: devices, MFA factors, recovery codes,
 * password resets and one-time verification codes (ADR 0008 §9).
 *
 * ONE RULE GOVERNS EVERY TABLE HERE: nothing that can be replayed is stored in a form the
 * database can hand back. Reset tokens, recovery codes and one-time codes are stored as
 * SHA-256 hashes; TOTP shared secrets and push tokens are stored encrypted because they
 * must be recoverable to be used. A dump of these tables must not let the holder
 * authenticate as anybody.
 *
 * Bearer tokens themselves live in Sanctum's `personal_access_tokens`, which already
 * stores a SHA-256 hash rather than the token — reused deliberately rather than
 * reimplemented, because a hand-rolled token store is a needless place to get hashing,
 * expiry or revocation wrong.
 *
 * Foreign keys here are all intra-module (Identity → Identity), so they are real
 * constraints and cascade: a device or factor has no meaning without its account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // Hash of the client-supplied installation identifier. Hashed because it is a
            // stable per-install identifier — in the clear it is a tracking key.
            $table->string('fingerprint_hash', 64);

            $table->string('display_name', 128);
            $table->enum('platform', ['ios', 'android', 'web', 'other'])->default('other');

            // FCM registration token. Encrypted: it authorises sending to that device, so
            // a database dump must not become a push-spoofing capability (ADR 0004).
            $table->text('push_token')->nullable();

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('trusted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            // One row per installation per account — re-registering the same device must
            // update it, never accumulate duplicates (ADR 0008 §5).
            $table->unique(['account_id', 'fingerprint_hash'], 'uniq_devices_account_fingerprint');
            $table->index('revoked_at', 'idx_devices_revoked_at');
        });

        Schema::create('mfa_factors', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->enum('type', ['totp']);

            // Encrypted, not hashed: TOTP verification needs the shared secret back.
            $table->text('secret');

            // Null until the enrolling user proves they can generate a code. An
            // unconfirmed factor must never gate a sign-in, or a failed enrolment locks
            // the account holder out of their own account.
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            // Replay guard: the last accepted time-step, so a code cannot be used twice
            // inside its validity window (RFC 6238 §5.2).
            $table->unsignedBigInteger('last_used_timestep')->nullable();

            $table->timestampsTz();

            // One factor of each type per account. Revocation deletes the row — a revoked
            // factor has no evidentiary value beyond its audit entry.
            $table->unique(['account_id', 'type'], 'uniq_mfa_factors_account_type');
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // SHA-256 of the code. Single use; shown to the user exactly once at
            // generation and never retrievable afterwards.
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();

            $table->timestampsTz();

            $table->index(['account_id', 'used_at'], 'idx_mfa_recovery_account_used');
        });

        Schema::create('password_resets', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');

            // Set on use. The row is kept rather than deleted so a replay attempt can be
            // distinguished from an unknown token and audited.
            $table->timestampTz('consumed_at')->nullable();

            // Coarse origin for abuse investigation. Never a precise location.
            $table->string('requested_ip', 45)->nullable();

            $table->timestampsTz();

            $table->index(['account_id', 'consumed_at'], 'idx_password_resets_account');
            $table->index('expires_at', 'idx_password_resets_expires_at');
        });

        Schema::create('verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();

            // One mechanism for citizen sign-in and for proving a contact detail. Keeping
            // them in one table means expiry, attempt limits and single-use are
            // implemented once rather than diverging per flow.
            $table->enum('purpose', ['sign-in', 'verify-email', 'verify-mobile']);

            $table->string('code_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();

            // Attempts against this code, so guessing burns the code rather than being
            // unlimited (OWASP ASVS V2.2).
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampsTz();

            $table->index(['account_id', 'purpose', 'consumed_at'], 'idx_verification_codes_lookup');
            $table->index('expires_at', 'idx_verification_codes_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_factors');
        Schema::dropIfExists('devices');
    }
};
