<?php

declare(strict_types=1);

namespace Modules\Reporting\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Domain\ReportCatalog;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Reporting\Jobs\BuildReportExport;
use Modules\Shared\Application\ActorContext;

/**
 * Requesting and producing exports (ADR 0026 §3).
 *
 * AN EXPORT IS A COPY OF THE DATABASE THAT LEAVES THIS APPLICATION'S CONTROL. Once a spreadsheet
 * of a barangay's beneficiaries is on a laptop, none of this system's authorization applies to it
 * — no scope, no audit, no revocation. Everything here follows from that.
 *
 * THE REQUEST IS RECORDED BEFORE THE FILE EXISTS, with who asked, what they asked for, and what
 * they were allowed to see at that moment. "Why does this spreadsheet exist and who made it" is
 * the question asked after one turns up somewhere it should not — and by then the requester's
 * permissions may have changed, which is why the context is snapshotted rather than looked up.
 */
final class ExportService
{
    public function __construct(private readonly ReportingAudit $audit) {}

    /**
     * Queues an export.
     *
     * NEVER BUILDS IT INLINE. The acceptance criterion is that a large export does not hold an
     * HTTP request open, and the reliable way to satisfy that is to have no code path that could:
     * this method returns a `queued` row, always, whatever the report.
     *
     * @param  array<string, mixed>  $filters
     */
    public function request(
        ReportCatalog $report,
        string $format,
        array $filters,
        ActorContext $actor,
    ): ReportExport {
        /** @var ReportExport $export */
        $export = DB::transaction(function () use ($report, $format, $filters, $actor): ReportExport {
            /** @var ReportExport $row */
            $row = ReportExport::query()->create([
                'uuid' => (string) Str::uuid7(),
                'report' => $report->value,
                'format' => $format,
                'filters' => $filters,
                /*
                 * Snapshotted, not looked up later. A person-level export produced last March by
                 * somebody who has since moved offices must still be explicable, and their
                 * current permissions are not the answer.
                 */
                'permission_context' => [
                    'permissions' => $actor->permissions,
                    'scope' => $actor->scope->forAudit(),
                    'channel' => $actor->channel->value,
                ],
                'requested_by' => (string) $actor->subjectId,
                'requested_at' => now(),
                'status' => 'queued',
                'is_person_level' => $report->isPersonLevel(),
                'expires_at' => now()->addHours($report->retentionHours()),
            ]);

            return $row;
        });

        /*
         * A person-level export is audited as its own act, not folded into a generic
         * "report.exported". The two are different events with different consequences, and an
         * audit trail that could not tell them apart would make the interesting one unfindable.
         */
        $this->audit->record(
            $actor->subjectId,
            $report->isPersonLevel() ? 'report.person-level-export-requested' : 'report.export-requested',
            'Export requested: '.$report->value,
            (string) $export->uuid,
        );

        BuildReportExport::dispatch((string) $export->uuid)->afterCommit();

        return $export;
    }

    /**
     * Whether this caller may download this export, right now.
     *
     * RE-AUTHORIZED AT DOWNLOAD, not trusted from the request. The master command asks for it and
     * the reason is the gap between the two moments: an export queued on Friday and downloaded on
     * Monday belongs to whoever the requester is on Monday, and somebody who lost a permission
     * over the weekend lost it for this file too.
     *
     * Scoped to the requester as well. An export is a copy shaped by one person's scope at one
     * moment; handing it to a colleague with a different scope would hand them rows they could
     * not have queried themselves.
     */
    public function mayDownload(ReportExport $export, ActorContext $actor): bool
    {
        if ((string) $export->requested_by !== (string) $actor->subjectId) {
            return false;
        }

        if ($export->status !== 'ready' || $export->isExpired()) {
            return false;
        }

        return in_array(
            ReportCatalog::from((string) $export->report)->requiredPermission()->value,
            $actor->permissions,
            true,
        );
    }
}
