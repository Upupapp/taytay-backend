<?php

declare(strict_types=1);

namespace Modules\ServiceCatalog\Application;

use Modules\ServiceCatalog\Domain\EligibilityFact;
use Modules\ServiceCatalog\Domain\EligibilityOutcome;
use Modules\ServiceCatalog\Infrastructure\Eloquent\Program;
use Modules\ServiceCatalog\Infrastructure\Eloquent\ProgramEligibilityCriterion;

/**
 * Runs a programme's guidance against an applicant's facts (ADR 0018 §3).
 *
 * THIS ENGINE FLAGS. IT DOES NOT DECIDE. The master command permits it to surface likely
 * matches and mismatches, and forbids it becoming an opaque denial system. Three properties
 * keep that true, and each is enforced somewhere a future change would have to notice:
 *
 *  1. **Every criterion carries its own explanation**, mandatory at the schema level. A
 *     criterion nobody can explain to the person it excludes is the opaque denial itself.
 *  2. **The result is per-criterion**, with the observed value alongside it, so an outcome can
 *     be checked rather than trusted.
 *  3. **The verdict vocabulary has no `ineligible`** — see {@see EligibilityOutcome}.
 *
 * FACTS ARE PASSED IN, NOT FETCHED. This module owns programmes, not people: ResidentProfile
 * owns residents and households, and reaching for them from here would invert the dependency
 * graph and close a cycle. The caller assembles the facts it is authorised to see, which also
 * means a caller who cannot read income simply produces `unknown` for an income criterion —
 * the guidance degrades to "a human should look", which is the right failure.
 *
 * `vulnerability_score` is not an available fact and cannot be made one without an ADR
 * (ADR 0018 §3, gap G-20).
 */
final class EligibilityGuidance
{
    /**
     * @param  array<string, mixed>  $facts  keyed by {@see EligibilityFact} value
     * @return array{
     *     outcome: EligibilityOutcome,
     *     guidance_version: string,
     *     locally_determined: bool,
     *     results: list<array<string, mixed>>
     * }
     */
    public function evaluate(Program $program, array $facts): array
    {
        $version = (string) $program->eligibility_guidance_version;

        $criteria = ProgramEligibilityCriterion::query()
            ->where('program_id', $program->id)
            ->where('guidance_version', $version)
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($criteria as $criterion) {
            $results[] = $this->evaluateCriterion($criterion, $facts);
        }

        return [
            'outcome' => EligibilityOutcome::fromResults(array_map(
                static fn (array $r): array => ['result' => $r['result'], 'is_blocking' => $r['is_blocking']],
                $results,
            )),
            // Pinned by the caller onto the case, so a decision defended in two years can be
            // re-derived against the rules that actually applied (ADR 0018 §6).
            'guidance_version' => $version,
            'locally_determined' => $program->isLocallyDetermined(),
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function evaluateCriterion(ProgramEligibilityCriterion $criterion, array $facts): array
    {
        $fact = $criterion->fact;
        $observed = $facts[$fact->value] ?? null;

        $base = [
            'criterion_code' => (string) $criterion->code,
            'fact' => $fact->value,
            'explanation' => (string) $criterion->citizen_explanation,
            'is_blocking' => (bool) $criterion->is_blocking,
            'observed_value' => $this->render($observed),
        ];

        /*
         * Absence is `unknown`, never `not-met`.
         *
         * A missing income figure means nobody has asked yet, not that the applicant earns too
         * much. Treating absence as failure would turn every incomplete record into a refusal —
         * and incomplete records belong overwhelmingly to the people least able to complete
         * them.
         */
        if ($observed === null || $observed === '' || $observed === []) {
            return $base + ['result' => 'unknown'];
        }

        return $base + ['result' => $this->compare($criterion, $observed) ? 'met' : 'not-met'];
    }

    private function compare(ProgramEligibilityCriterion $criterion, mixed $observed): bool
    {
        $comparator = (string) $criterion->comparator;
        $value = $criterion->value;
        $valueMax = $criterion->value_max;

        return match ($comparator) {
            'at-least' => $this->numeric($observed) >= (float) $value,
            'at-most' => $this->numeric($observed) <= (float) $value,
            'between' => $this->numeric($observed) >= (float) $value
                && $this->numeric($observed) <= (float) $valueMax,
            'is' => $this->matchesAny($observed, [(string) $value]),
            'is-one-of' => $this->matchesAny($observed, $this->splitList((string) $value)),
            // An unrecognised comparator produces `unknown` rather than a silent pass or fail.
            // A rule the engine cannot read must reach a human, not a verdict.
            default => throw new \InvalidArgumentException('unsupported comparator'),
        };
    }

    /**
     * Whether an observed value — scalar or list — matches any of the accepted values.
     *
     * A list observation is how sector membership arrives: a resident carries several tags, and
     * "is senior-citizen" is satisfied by any one of them.
     *
     * @param  list<string>  $accepted
     */
    private function matchesAny(mixed $observed, array $accepted): bool
    {
        $values = is_array($observed)
            ? array_map(static fn (mixed $v): string => (string) $v, $observed)
            : [(string) $observed];

        foreach ($values as $value) {
            if (in_array($value, $accepted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $value)), static fn (string $v): bool => $v !== ''));
    }

    private function numeric(mixed $observed): float
    {
        return is_numeric($observed) ? (float) $observed : 0.0;
    }

    private function render(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_array($value) => implode(', ', array_map(static fn (mixed $v): string => (string) $v, $value)),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
