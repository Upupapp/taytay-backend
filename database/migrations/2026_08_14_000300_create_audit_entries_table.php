<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit entries — append-only accountability trail (ADR 0008 §8, CLAUDE.md Article 5.4).
 *
 * Required by RA 10173's accountability duty: every read of another person's personal
 * data, every credential lifecycle transition and every privileged administrative action
 * must be attributable after the fact.
 *
 * APPEND-ONLY IS STRUCTURAL, NOT A CONVENTION:
 * there is no `updated_at` and no `deleted_at`. There is nowhere to record a modification
 * because a modification must not happen — a trail that can be edited is not evidence. In
 * deployed environments the application role additionally holds only INSERT and SELECT on
 * this table; that grant is infrastructure and is recorded as a production gap.
 *
 * Personal-data classification: `internal`, but privacy-critical in a specific way — this
 * table records *that* personal data was accessed, never a copy of it. `summary` is a
 * short operator-facing description and must never contain a government identifier, a
 * credential, a full address or case narrative (Article 5.5). Storing the read data here
 * would turn the audit log into a second, less-guarded copy of the record it protects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // When the audited thing happened, which is not necessarily when the row was
            // written — a queued audit write must not misreport the time of the act.
            $table->timestampTz('occurred_at');

            // Identity account UUID. Nullable: some auditable events have no human actor
            // (a scheduled expiry). No FK — cross-module reference (Article 2.2).
            $table->uuid('actor_subject_id')->nullable();

            // Denormalised label so the trail stays readable after an account is renamed
            // or deactivated. A cache of Identity's data, and labelled as such
            // (ADR 0008 §10) — never the source of truth for a name.
            $table->string('actor_label', 128)->nullable();

            // Open vocabulary (created, updated, viewed, status-changed, exported, …):
            // new auditable actions arrive with every module, and each must not require a
            // check-constraint rewrite.
            $table->string('action', 64);

            // What was acted on. Module-qualified type plus that module's public UUID.
            $table->string('entity_type', 64);
            $table->uuid('entity_id')->nullable();

            $table->string('summary', 255);

            // Correlates to the API request a citizen can quote to a support desk
            // (docs/api/conventions.md §2) and to the application log line.
            $table->string('request_id', 128)->nullable();

            // Telemetry only. Never authority (ADR 0002) — recorded so an investigation
            // can tell a kiosk scan from an admin console action.
            $table->string('client_channel', 32)->nullable();

            // Created only. The absence of `updated_at` is the append-only guarantee.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'idx_audit_entries_entity');
            $table->index(['actor_subject_id', 'occurred_at'], 'idx_audit_entries_actor_time');
            $table->index('occurred_at', 'idx_audit_entries_occurred_at');
            $table->index('action', 'idx_audit_entries_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
