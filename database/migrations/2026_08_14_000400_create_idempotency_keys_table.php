<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys — replay protection for retryable writes (ADR 0008 §7).
 *
 * `docs/api/conventions.md` §7 promises that a state-changing request carrying an
 * `Idempotency-Key` can be safely retried, and the Flutter client already sends one. That
 * promise needs somewhere to remember the first outcome, and it is foundation
 * infrastructure rather than a disbursement-TAB concern: a retried assistance submission
 * on a dropped mobile connection must not create a second application, and a retried
 * release must not pay public money twice.
 *
 * Personal-data classification: `personal`. `response_body` holds the payload the caller
 * already received, which for a citizen endpoint contains their own data. Three
 * consequences, all deliberate:
 *   * `expires_at` is mandatory and rows are purged after it — this is a short-lived
 *     replay cache, not a second copy of the record (data minimisation, RA 10173);
 *   * it is scoped to `subject_id`, so one caller can never replay another's response;
 *   * it is never logged.
 *
 * JSON EXCEPTION (ADR 0008 §13): `response_body` is a verbatim cached HTTP response — one
 * of the three listed permitted uses. It is opaque to the application, never filtered,
 * joined, summed or authorized on. It is registered in
 * tests/Architecture/DatabaseConventionsTest.php with this justification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Client-supplied key. Opaque to us; only its uniqueness per caller matters.
            $table->string('idempotency_key', 255);

            // Identity account UUID, or null for an unauthenticated endpoint. No FK —
            // cross-module reference (Article 2.2).
            $table->uuid('subject_id')->nullable();

            // Method + path, so the same key on a different operation is a different
            // record rather than a false replay of an unrelated response.
            $table->string('endpoint', 191);

            // Hash of the request payload. A repeated key with a *different* body is a
            // client bug and must be rejected, not answered with the old result.
            $table->string('request_fingerprint', 64);

            // Null until the first attempt completes; a second request arriving meanwhile
            // waits or is refused rather than executing concurrently.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();

            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            $table->timestampsTz();

            /*
             * The replay guard. Scoped by caller so keys cannot collide across callers,
             * and by endpoint so one key cannot be reused for a different operation.
             *
             * `subject_id` is nullable, and PostgreSQL treats NULLs as distinct, so
             * unauthenticated callers are NOT protected by this constraint — which is
             * correct: an anonymous caller has no identity to scope a replay to, so
             * unauthenticated endpoints must not rely on idempotency keys for safety.
             * Documented here so the limitation is not mistaken for a bug.
             */
            $table->unique(
                ['subject_id', 'endpoint', 'idempotency_key'],
                'uniq_idempotency_subject_endpoint_key',
            );

            $table->index('expires_at', 'idx_idempotency_keys_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
