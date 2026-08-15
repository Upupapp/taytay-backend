<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital ID credentials and their verification log (ADR 0011).
 *
 * FEATURE-FLAGGED AND OFF BY DEFAULT (`credential.digital_id.enabled`). A digital ID is
 * optional to the service: every resident must be able to receive assistance without one,
 * so the tables exist but the routes do nothing until an LGU decision turns them on.
 *
 * WHAT A CREDENTIAL IS NOT: it is not a copy of the resident record. It holds a serial, a
 * status and validity dates. Everything a verifier is shown is fetched server-side at
 * verification time, from the one canonical resident row, and pruned to the minimum
 * (ADR 0011 §3).
 *
 * There is no biometric template column here or anywhere else in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // ResidentProfile's resident UUID. No FK — cross-module (Article 2.2).
            $table->uuid('resident_id');

            /*
             * The human-readable number printed on a card. Random, not sequential: a
             * sequential serial tells any holder how many IDs the LGU has issued and lets
             * them guess their neighbour's.
             */
            $table->string('serial', 32)->unique();

            $table->enum('status', ['issued', 'active', 'suspended', 'revoked', 'expired'])
                ->default('issued');

            $table->timestampTz('issued_at');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();

            /*
             * Which signing key sealed this credential's QR payloads. Recorded so a key can
             * be rotated — or compromised and retired — without invalidating credentials
             * signed by the others, and so an old payload can still be identified.
             *
             * The key MATERIAL is never here: it lives in the environment (Article 5.6).
             */
            $table->string('signing_key_id', 32);

            $table->uuid('issued_by')->nullable();

            $table->timestampsTz();

            $table->index(['resident_id', 'status'], 'idx_credentials_resident_status');
            $table->index('status', 'idx_credentials_status');
            $table->index('expires_at', 'idx_credentials_expires_at');
        });

        Schema::create('credential_verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Null when the payload did not resolve to a credential at all — a forged or
            // garbled scan is exactly the event worth recording.
            $table->foreignId('credential_id')->nullable()->constrained('credentials')->nullOnDelete();

            $table->enum('outcome', [
                'valid',
                'expired',
                'revoked',
                'suspended',
                'signature-invalid',
                'replayed',
                'malformed',
            ]);

            /*
             * The single-use nonce from the scanned payload, hashed.
             *
             * This is the replay guard: a QR photographed over someone's shoulder is
             * useless because its nonce has already been spent. Hashed because in the
             * clear it is a correlatable per-scan identifier.
             */
            $table->string('nonce_hash', 64)->nullable();

            // Which device or kiosk scanned it — an Identity account UUID, no FK.
            $table->uuid('verifier_subject_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            /*
             * A nonce may be spent exactly once. The unique index is the enforcement, not
             * the application check — two simultaneous scans of the same payload must not
             * both succeed, and only the database can decide that race.
             */
            $table->unique('nonce_hash', 'uniq_credential_verifications_nonce');
            $table->index(['credential_id', 'occurred_at'], 'idx_credential_verifications_credential');
            $table->index('outcome', 'idx_credential_verifications_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_verifications');
        Schema::dropIfExists('credentials');
    }
};
