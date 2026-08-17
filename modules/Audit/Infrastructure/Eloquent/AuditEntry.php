<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Domain\AuditRisk;

/**
 * One audited act. READ-ONLY, structurally.
 *
 * The model has no `create`, no fill and no timestamps, and `AuditIsAppendOnlyTest` fails the
 * build if any code calls `update()`, `delete()` or `save()` on this table. Writing goes through
 * `AuditTrail` and only through it.
 *
 * That is not tidiness. Article 5.4 requires audit records to be append-only, and an Eloquent
 * model with the usual affordances is an invitation: `$entry->update(['summary' => ...])` is one
 * autocomplete away, and a corrected audit trail is not an audit trail.
 *
 * @property AuditRisk $risk
 */
final class AuditEntry extends Model
{
    protected $table = 'audit_entries';

    public $timestamps = false;

    /** Nothing is mass-assignable, because nothing here is assignable at all. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'risk' => AuditRisk::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The changed columns, as a list.
     *
     * Stored comma-separated rather than as JSON: it is a short list of identifiers, never
     * filtered or joined on, and ADR 0008 §13 keeps application state out of JSON columns.
     *
     * @return list<string>
     */
    public function changedFieldNames(): array
    {
        $raw = (string) $this->changed_fields;

        return $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
    }
}
