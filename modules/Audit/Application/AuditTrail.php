<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Domain\AuditRisk;
use Modules\Shared\Application\RequestContext;
use Modules\Shared\Contracts\AuditWriter;

/**
 * The one writer to the audit trail (ADR 0034 §1).
 *
 * **BEFORE THIS CLASS THERE WERE TEN.** Every module had its own `…Audit` class hand-rolling the
 * same `DB::table('audit_entries')->insert()`, and they had already begun to differ: some set
 * `actor_label`, some did not; the truncation length was repeated in ten places; and nothing
 * anywhere decided which acts were high-risk.
 *
 * That is the drift this project has been bitten by before (`feedback: a capability gate needs one
 * reader`). Ten writers means ten places a new column must be filled in, and the tenth will be
 * missed — and a missing audit field is invisible, because an audit trail with a gap looks exactly
 * like an audit trail of a quiet week.
 *
 * The module classes remain as thin, well-named seams so callers keep writing
 * `$this->audit->record(...)` in their own vocabulary. What changed is that all ten now end here.
 *
 * ── WHAT THIS CLASS REFUSES TO STORE ──────────────────────────────────────────────────
 *
 * The master command: *do not copy full case notes, passwords, raw ID numbers or entire resident
 * objects into generic audit payloads.* This class enforces that rather than trusting callers,
 * because the trail is read by operators investigating something else, retained longer than most
 * records, and exported for compliance review. **A trail that duplicates the data it protects is a
 * second, less-guarded copy of it.**
 *
 * `changed_fields` takes field NAMES only. `reason` takes a reason typed for this purpose. There is
 * no parameter anywhere here for an old value or a new one.
 */
final class AuditTrail implements AuditWriter
{
    /** Field-name lists are capped so a bulk update cannot write a kilobyte of column names. */
    private const MAX_CHANGED_FIELDS = 24;

    public function __construct(private readonly RequestContext $requestContext) {}

    /**
     * Records one audited act.
     *
     * @param  list<string>  $changedFields  COLUMN NAMES, never values. Anything that is not a
     *                                       plausible column name is dropped rather than stored,
     *                                       because the one way a value reaches this trail is a
     *                                       caller passing one where a name was expected.
     */
    public function record(
        ?string $actorSubjectId,
        string $action,
        string $summary,
        string $entityType,
        ?string $entityId = null,
        array $changedFields = [],
        ?string $reason = null,
        ?string $accountType = null,
    ): void {
        $risk = AuditActionCatalog::riskFor($action);

        DB::table('audit_entries')->insert([
            'uuid' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'actor_subject_id' => $actorSubjectId,
            /*
             * Denormalised at the moment of the act. An account that was a citizen in March and is
             * a clerk today must still read as a citizen in March's trail — resolving it live
             * would silently rewrite history every time somebody changed roles.
             */
            'actor_account_type' => $accountType,
            'actor_label' => null,
            'action' => $action,
            'risk' => $risk->value,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => Str::limit($summary, 255, ''),
            'changed_fields' => $this->fieldNames($changedFields),
            'reason' => $reason === null ? null : Str::limit(trim($reason), 500, ''),
            'request_id' => $this->requestContext->requestId(),
            'client_channel' => $this->requestContext->channel()->value,
            /*
             * ONLY FOR HIGH-RISK ENTRIES, AND ONLY IF THE LGU TURNED IT ON.
             *
             * An IP address is personal data under RA 10173. Capturing one on every routine read
             * builds a movement log of the office's own staff — thousands of rows a day saying
             * where a clerk was sitting — which is disproportionate to what it would ever be used
             * for. On a sensitive document download it is proportionate evidence.
             *
             * Off by default: this is the DPO's decision, not a default this repository picks
             * (ADR 0034 §3).
             */
            'ip_address' => $this->networkIdentifier($risk, 'ip'),
            'user_agent' => $this->networkIdentifier($risk, 'agent'),
            'created_at' => now(),
        ]);
    }

    /**
     * Field NAMES, filtered.
     *
     * A caller that passes `['birth_date' => '1985-03-02']` — associative, values included — is
     * making the exact mistake this whole class exists to prevent, and it is an easy one: the
     * shape of a `$changes` array in an update method is already keyed by field name. So keys are
     * taken when the array is associative, and anything that does not look like a column name is
     * dropped rather than stored.
     *
     * @param  list<string>|array<string, mixed>  $fields
     */
    private function fieldNames(array $fields): ?string
    {
        if ($fields === []) {
            return null;
        }

        // Associative means the caller handed over a changeset. Take the keys; the values are
        // exactly what must not be here.
        $names = array_is_list($fields) ? $fields : array_keys($fields);

        $clean = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            // A column name, and nothing else. A date, an identifier, a sentence or an email all
            // fail this and are dropped.
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
                continue;
            }

            $clean[] = $name;
        }

        if ($clean === []) {
            return null;
        }

        return Str::limit(implode(',', array_slice(array_unique($clean), 0, self::MAX_CHANGED_FIELDS)), 512, '');
    }

    private function networkIdentifier(AuditRisk $risk, string $which): ?string
    {
        if ($risk !== AuditRisk::High || config('audit.capture_network', false) !== true) {
            return null;
        }

        $request = request();

        return $which === 'ip'
            ? $request?->ip()
            : Str::limit((string) $request?->userAgent(), 255, '');
    }
}
