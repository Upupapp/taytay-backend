<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Illuminate\Database\Eloquent\Builder;
use Modules\Audit\Infrastructure\Eloquent\AuditEntry;

/**
 * Reading the trail (ADR 0034 §7).
 *
 * AN AUDIT TRAIL NOBODY CAN READ IS THEATRE. It was written for ten TABs before this one and had
 * no query surface at all — every entry was written and none could be retrieved, which makes the
 * whole apparatus a cost with no benefit until somebody opens a database console.
 *
 * ONE THAT ANYBODY CAN READ IS WORSE. The trail is a record of who did what to whom, assembled
 * across every module, and it is more concentrated than any single record it describes: reading it
 * tells you which residents have safeguarding cases without opening one. So it costs its own
 * permission, held by nobody by default, and **reading it is itself audited**.
 */
final class AuditQuery
{
    /**
     * @return Builder<AuditEntry>
     */
    public function query(): Builder
    {
        return AuditEntry::query()->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * The trail for one record, oldest first — the order somebody reconstructing events reads in.
     *
     * @return Builder<AuditEntry>
     */
    public function forEntity(string $entityType, string $entityId): Builder
    {
        return AuditEntry::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Everything one actor did.
     *
     * @return Builder<AuditEntry>
     */
    public function forActor(string $subjectId): Builder
    {
        return AuditEntry::query()->where('actor_subject_id', $subjectId)->orderByDesc('id');
    }
}
