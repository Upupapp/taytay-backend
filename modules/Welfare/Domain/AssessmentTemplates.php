<?php

declare(strict_types=1);

namespace Modules\Welfare\Domain;

/**
 * The versioned assessment form catalog (ADR 0017 §4).
 *
 * Reads `config/assessment.php`. Behind this class the store is a config file today and could
 * be a table tomorrow with no caller affected — the same seam `VulnerabilityRuleset` and
 * `ConfigServiceCatalogRepository` sit on.
 *
 * THE VERSION IS THE POINT. Every assessment instance pins `template_code` + `template_version`
 * at the moment it is created. Without that, editing a question silently changes what past
 * assessments appear to have asked, and their recorded answers stop meaning what they meant —
 * which matters most precisely when somebody is disputing a decision made two years ago.
 *
 * NOTHING HERE SCORES. The catalog has no weights and no totals: a form that computed an
 * eligibility number would be the automatic decision the master command forbids, wearing a
 * questionnaire's clothes.
 */
final class AssessmentTemplates
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $templates */
        $templates = config('assessment.templates', []);

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $template = $this->all()[$code] ?? null;

        return is_array($template) ? $template : null;
    }

    public function exists(string $code): bool
    {
        return $this->find($code) !== null;
    }

    /**
     * The version currently published for a template.
     *
     * Read once, when an assessment is opened, and stored on the row. Reading it again at
     * completion would let a mid-assessment config deploy change the version an in-progress
     * form claims to be.
     */
    public function currentVersion(string $code): string
    {
        return (string) ($this->find($code)['version'] ?? 'unversioned');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questions(string $code): array
    {
        /** @var list<array<string, mixed>> $questions */
        $questions = $this->find($code)['questions'] ?? [];

        return $questions;
    }

    /**
     * @return list<string>
     */
    public function questionCodes(string $code): array
    {
        return array_map(
            static fn (array $question): string => (string) $question['code'],
            $this->questions($code),
        );
    }

    /**
     * @return list<string>
     */
    public function requiredQuestionCodes(string $code): array
    {
        return array_values(array_map(
            static fn (array $question): string => (string) $question['code'],
            array_filter($this->questions($code), static fn (array $q): bool => (bool) ($q['required'] ?? false)),
        ));
    }

    /**
     * Whether an answer is acceptable for a question.
     *
     * Choice questions are checked against their list; everything else is accepted as text and
     * bounded by the column. Deliberately permissive: an assessor recording something the form
     * did not anticipate is describing reality, and a validator that refuses it teaches them to
     * pick the nearest wrong option instead.
     */
    public function accepts(string $templateCode, string $questionCode, ?string $value): bool
    {
        $question = null;

        foreach ($this->questions($templateCode) as $candidate) {
            if ((string) $candidate['code'] === $questionCode) {
                $question = $candidate;

                break;
            }
        }

        if ($question === null) {
            return false;
        }

        if (($question['type'] ?? null) !== 'choice' || $value === null) {
            return true;
        }

        /** @var list<string> $choices */
        $choices = $question['choices'] ?? [];

        return in_array($value, $choices, true);
    }
}
