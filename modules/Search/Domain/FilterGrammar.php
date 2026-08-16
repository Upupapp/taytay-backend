<?php

declare(strict_types=1);

namespace Modules\Search\Domain;

use Modules\Shared\Exceptions\ApiException;
use Modules\Shared\Exceptions\ErrorCode;

/**
 * What a filter may say (ADR 0027 §3).
 *
 * THE ACCEPTANCE CRITERION: **saved filters cannot inject raw SQL.** That is held by there being
 * no path from a stored filter to a string that reaches the database. A filter is a `(field,
 * operator, value)` triple where the field and the operator are looked up in a closed table, and
 * the value goes through a parameter binding. Nothing is concatenated.
 *
 * VALIDATED ON THE WAY IN, NOT ONLY ON THE WAY OUT. A saved view is executed later, by whoever
 * loads it, possibly by somebody other than its author. A filter checked only at execution time is
 * a stored query waiting for a code path that forgets — and the code path that forgets will be the
 * one added in a hurry two years from now, by which point the row has been in the database so
 * long it looks trustworthy.
 *
 * THE FIELD LIST IS PER ENTITY AND DELIBERATELY SHORT. It is not "every column": a filterable
 * field is one a list endpoint already exposes, so a filter cannot reach a column the projection
 * would have withheld. Filtering on `case_notes.body` would be a way to ask questions of a
 * protected note without reading it — "show me cases whose notes mention 'shelter'" is a
 * disclosure by search.
 */
final class FilterGrammar
{
    /**
     * Field → allowed operators, per entity.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const FIELDS = [
        'residents' => [
            'barangay_id' => ['eq', 'in'],
            'verification_tier' => ['eq', 'in'],
            'sex' => ['eq'],
            'birth_date' => ['gte', 'lte'],
        ],
        'cases' => [
            'status' => ['eq', 'in'],
            'priority' => ['eq', 'in'],
            'type' => ['eq', 'in'],
            'barangay_id' => ['eq', 'in'],
            'program_id' => ['eq'],
            'assigned_to' => ['eq'],
            'opened_at' => ['gte', 'lte'],
        ],
        'households' => [
            'barangay_id' => ['eq', 'in'],
        ],
        'referrals' => [
            'status' => ['eq', 'in'],
            'urgency' => ['eq', 'in'],
            'destination_type' => ['eq', 'in'],
        ],
        'releases' => [
            'status' => ['eq', 'in'],
            'kind' => ['eq'],
            'program_id' => ['eq'],
            'scheduled_for' => ['gte', 'lte'],
        ],
    ];

    /**
     * Operator → SQL comparison. A closed map: an operator that is not a key here cannot reach
     * the database, whatever a stored filter says.
     *
     * @var array<string, string>
     */
    private const OPERATORS = [
        'eq' => '=',
        'gte' => '>=',
        'lte' => '<=',
        // `in` is handled separately — it binds an array, not a scalar.
        'in' => 'in',
    ];

    /**
     * Validates a filter set, or refuses it.
     *
     * @param  array<int, mixed>  $filters
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    public static function validate(string $entity, array $filters): array
    {
        $allowed = self::FIELDS[$entity] ?? null;

        if ($allowed === null) {
            throw new ApiException(ErrorCode::ValidationFailed, 'That is not a filterable entity.');
        }

        $clean = [];

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                throw new ApiException(ErrorCode::ValidationFailed, 'A filter must be an object.');
            }

            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'eq');

            /*
             * The field is looked up, never interpolated. A field name that is not a key in the
             * table above cannot become a column reference — which is the whole of the injection
             * defence, and why the table is data rather than a regex.
             */
            if (! array_key_exists($field, $allowed)) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    "`{$field}` is not a filterable field on {$entity}.",
                );
            }

            if (! in_array($operator, $allowed[$field], true)) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    "`{$operator}` is not allowed on `{$field}`.",
                );
            }

            $value = $filter['value'] ?? null;

            if ($operator === 'in') {
                if (! is_array($value) || $value === []) {
                    throw new ApiException(ErrorCode::ValidationFailed, '`in` needs a non-empty list.');
                }

                // Bounded. An `in` with ten thousand values is a way to make one request cost as
                // much as ten thousand, and no legitimate saved view needs it.
                if (count($value) > 100) {
                    throw new ApiException(ErrorCode::ValidationFailed, 'That filter has too many values.');
                }

                $value = array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
            } elseif (is_array($value) || is_object($value)) {
                // A scalar operator with a structured value is how somebody smuggles an
                // expression into a binding in frameworks that allow it. Refused outright.
                throw new ApiException(ErrorCode::ValidationFailed, 'That filter value must be a scalar.');
            } else {
                $value = (string) $value;
            }

            $clean[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $clean;
    }

    /**
     * The SQL comparison for a validated operator.
     */
    public static function comparison(string $operator): string
    {
        return self::OPERATORS[$operator] ?? '=';
    }

    /**
     * @return list<string>
     */
    public static function entities(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Published so a client can build a filter UI from the server's own list rather than a copy
     * that drifts — the same reasoning as publishing the upload limits in ADR 0020.
     *
     * @return array<string, list<string>>
     */
    public static function fieldsFor(string $entity): array
    {
        return self::FIELDS[$entity] ?? [];
    }
}
