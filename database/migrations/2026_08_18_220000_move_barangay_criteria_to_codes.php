<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eligibility criteria that name a barangay now name it by code, not by auto-increment key.
 *
 * ---
 *
 * `EligibilityFact::Barangay` used to be compared against `(string) $resident->barangay_id`, so a
 * criterion restricting a programme to a barangay had to be authored as `barangay is 2`. Three
 * things are wrong with that, and the third is the one that matters:
 *
 *  1. **It is unexplainable.** Every fact in that enum is required to be something a clerk can
 *     point at and explain to the person in front of them. Nobody knows which barangay is 2.
 *  2. **It could not be offered by a client.** `GET /api/v1/barangays` publishes `uuid` and `code`
 *     and deliberately refuses the integer (L-15), so no console could render a picker for it.
 *  3. **IT IS NOT STABLE ACROSS ENVIRONMENTS.** Auto-increment keys are assigned by insertion
 *     order. The same criterion authored against staging and promoted to production targets a
 *     different barangay, silently, with no error at any layer — and this criterion decides who
 *     is offered welfare assistance. This system also imports legacy records and merges duplicate
 *     residents, both of which reorder insertions.
 *
 * ── WHAT THIS MIGRATION DOES ─────────────────────────────────────────────────────────
 *
 * Rewrites the stored `value` of every `barangay` criterion from ids to codes, preserving the `|`
 * separator that `is-one-of` uses. Runs against a populated table and is forward-only, as
 * Article 6 requires.
 *
 * **A SEGMENT THAT IS NOT A KNOWN ID IS LEFT EXACTLY AS IT IS.** Two cases reach that branch and
 * both are handled by leaving it alone: a value that is already a code (this migration having run,
 * or a criterion authored after the fix), and an id whose barangay no longer exists. Rewriting the
 * second to a placeholder would invent a barangay; dropping it would silently widen the
 * programme's reach to everybody the criterion used to exclude. Left in place, it matches no
 * resident and the criterion reads `not-met` — which is what it already did, and is visible to
 * anybody who looks at the rule, unlike the alternatives.
 *
 * ── DOWN ─────────────────────────────────────────────────────────────────────────────
 *
 * The mirror: codes back to the ids **this** database holds. My first draft left it inert on the
 * grounds that surrogate ids are exactly what is being removed, and `MigrationSafetyTest` refused
 * it — correctly. That argument is about what the values *mean* across environments, and it does
 * not make the rewrite unreversible within one database, where code → id is a lookup like any
 * other. An irreversible migration makes rollback a code change decided during an incident, which
 * is a worse position than restoring a representation we know how to restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        $codesById = DB::table('barangays')->pluck('code', 'id');

        if ($codesById->isEmpty()) {
            return;
        }

        $criteria = DB::table('program_eligibility_criteria')
            ->where('fact', 'barangay')
            ->whereNotNull('value')
            ->get(['id', 'value']);

        foreach ($criteria as $criterion) {
            $segments = array_map('trim', explode('|', (string) $criterion->value));

            $rewritten = array_map(
                static function (string $segment) use ($codesById): string {
                    // Only a segment that is entirely digits can be an id. A code is never numeric,
                    // so this cannot mistake one for the other.
                    if ($segment === '' || ! ctype_digit($segment)) {
                        return $segment;
                    }

                    return (string) ($codesById[(int) $segment] ?? $segment);
                },
                $segments,
            );

            $value = implode('|', $rewritten);

            if ($value !== (string) $criterion->value) {
                DB::table('program_eligibility_criteria')
                    ->where('id', $criterion->id)
                    ->update(['value' => $value]);
            }
        }
    }

    public function down(): void
    {
        $idsByCode = DB::table('barangays')->pluck('id', 'code');

        if ($idsByCode->isEmpty()) {
            return;
        }

        $criteria = DB::table('program_eligibility_criteria')
            ->where('fact', 'barangay')
            ->whereNotNull('value')
            ->get(['id', 'value']);

        foreach ($criteria as $criterion) {
            $segments = array_map('trim', explode('|', (string) $criterion->value));

            $rewritten = array_map(
                static function (string $segment) use ($idsByCode): string {
                    // Symmetrical with up(): a segment that is not a known code is left alone,
                    // which covers a value that is already an id and a code whose barangay has
                    // gone.
                    if ($segment === '') {
                        return $segment;
                    }

                    return (string) ($idsByCode[$segment] ?? $segment);
                },
                $segments,
            );

            $value = implode('|', $rewritten);

            if ($value !== (string) $criterion->value) {
                DB::table('program_eligibility_criteria')
                    ->where('id', $criterion->id)
                    ->update(['value' => $value]);
            }
        }
    }
};
