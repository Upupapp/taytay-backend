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

    /**
     * The trail refuses to be edited or deleted, at the model rather than by convention.
     *
     * `AuditIsAppendOnlyTest` already proves that no application code updates or deletes an entry —
     * by reading the source. That is a statement about the code that exists today. This is a
     * statement about the code that can exist tomorrow: an attempt is refused rather than merely
     * absent, which is what TAB 14 step 8 asks to be demonstrated by attempting it.
     *
     * ── The one legitimate deletion, and why it is not exempted here ──────────────────
     *
     * The retention schedule has an `audit` category, so an approved disposition will eventually
     * need to remove old entries. That is not this: it is a deliberate, dated act taken under an
     * approved schedule, and `RetentionPolicy::mayPurge()` refuses everything until the Data
     * Protection Officer approves one.
     *
     * When that day comes, the escape hatch is added **in the same change as the approval** —
     * which is the point. A purge path that already exists is a purge path somebody can reach
     * without an approval.
     */
    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new \RuntimeException(
                'An audit entry cannot be edited. The trail is append-only (Article 5.4): a record '
                .'that can be corrected after the fact is a record that proves nothing.',
            );
        });

        self::deleting(static function (): never {
            throw new \RuntimeException(
                'An audit entry cannot be deleted. Disposal happens under an approved retention '
                .'schedule, which does not exist yet — see RetentionPolicy::mayPurge().',
            );
        });
    }

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
