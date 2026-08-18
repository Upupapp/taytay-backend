<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Application\AuthorizationService;
use Modules\AccessControl\Contracts\Permission;
use Modules\Reporting\Application\ExportService;
use Modules\Reporting\Application\MetricsService;
use Modules\Reporting\Application\ReportingAudit;
use Modules\Reporting\Domain\ReportCatalog;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard metrics and report exports (ADR 0026).
 *
 * AGGREGATE-FIRST. No endpoint here returns a name except the one person-level report, which
 * costs its own permission. Every metric is scoped to the caller's barangays, because an
 * aggregate is exactly the shape that hides a scope leak: a number does not look like a
 * disclosure until you realise it was counted over the whole municipality.
 *
 * AND CELLS BELOW FIVE ARE SUPPRESSED. "3 households with a safeguarding concern in Barangay
 * Dolores" is a statistic; "1 household" is an identification.
 */
final class ReportController
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly ExportService $exports,
        private readonly ReportingAudit $audit,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * The dashboard.
     */
    public function dashboard(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReportView);

        $filters = $this->filters($request);

        return ApiResponse::item([
            'filters' => $filters,
            'summary' => $this->metrics->summary($actor, $filters),
            'case_aging' => $this->metrics->caseAging($actor, $filters),
            'barangay_reach' => $this->metrics->barangayReach($actor, $filters),
            'program_utilization' => $this->metrics->programUtilization($actor, $filters),
            'referral_outcomes' => $this->metrics->referralOutcomes($actor, $filters),
            // By team, never by person — see ADR 0026 §4.
            'field_workload' => $this->metrics->fieldWorkload($actor, $filters),
            'data_completeness' => $this->metrics->dataCompleteness($actor),
            /*
             * Published so a client can label a blank cell honestly. A dashboard that silently
             * shows nothing where a number was suppressed reads as a bug, and somebody eventually
             * "fixes" it.
             */
            'suppression' => [
                'minimum_cell' => MetricsService::MINIMUM_CELL,
                'note' => 'Counts below the minimum are withheld so a small cell cannot identify a household.',
            ],
        ]);
    }

    // ── exports ───────────────────────────────────────────────────────────────────────

    /**
     * How many of a requester's own exports the history returns.
     *
     * Generous: person-level exports live 24 hours and aggregate ones a week, so a hundred covers
     * every export that still has a file behind it many times over.
     */
    private const EXPORT_HISTORY_LIMIT = 100;

    public function listExports(Request $request, ActorContext $actor): JsonResponse
    {
        $this->authorization->authorize($actor, Permission::ReportView);

        return ApiResponse::item([
            // Scoped to the caller's own requests. An export is shaped by one person's scope at
            // one moment, and another person's list is not theirs to browse.
            'exports' => ReportExport::query()
                ->where('requested_by', (string) $actor->subjectId)
                ->orderByDesc('requested_at')
                /*
                 * BOUNDED. This was an unbounded `->get()` — the only one left in the API — and a
                 * staff member's export history grows for as long as they work here (Article 4:
                 * collections are always paginated, never an unbounded list).
                 *
                 * A LIMIT RATHER THAN PAGINATION, deliberately. Moving to the `page` envelope
                 * would change this response's shape, which `CHANGELOG_API.md` classes as a
                 * breaking change requiring `/api/v2` — a disproportionate answer to a list whose
                 * rows expire in 24 hours to a week anyway (ADR 0026 §3). The most recent hundred
                 * is every export that still has a file behind it, several times over.
                 */
                ->limit(self::EXPORT_HISTORY_LIMIT)
                ->get()
                ->map(fn (ReportExport $export): array => $this->projection($export))->all(),
        ]);
    }

    /**
     * Queues an export. Never builds one inline.
     */
    public function requestExport(Request $request, ActorContext $actor): JsonResponse
    {
        $validated = $request->validate([
            'report' => ['required', 'string', 'in:'.implode(',', ReportCatalog::values())],
            'format' => ['sometimes', 'string', 'in:csv'],
            'filters' => ['sometimes', 'array'],
        ]);

        $report = ReportCatalog::from($validated['report']);

        /*
         * The permission comes from the REPORT, not the route. A person-level export costs
         * `report.export-person-level`; an aggregate costs `report.view`. One endpoint, two
         * prices, decided by what is actually being copied out of the database.
         */
        $this->authorization->authorize($actor, $report->requiredPermission());

        return ApiResponse::created($this->projection($this->exports->request(
            $report,
            $validated['format'] ?? 'csv',
            $validated['filters'] ?? [],
            $actor,
        )));
    }

    /**
     * Downloads a finished export.
     *
     * RE-AUTHORIZED HERE, not trusted from the request. An export queued on Friday and downloaded
     * on Monday belongs to whoever the requester is on Monday — somebody who lost a permission
     * over the weekend lost it for this file too.
     */
    public function download(Request $request, ActorContext $actor, string $export): Response
    {
        /** @var ReportExport|null $model */
        $model = ReportExport::query()->where('uuid', $export)->first();

        /*
         * Unknown, another person's, expired, unfinished and no-longer-permitted all answer NOT
         * FOUND. Distinguishing them would confirm that an export exists and what state it is in,
         * which is what somebody probing wants to learn (OWASP API1).
         */
        if ($model === null || ! $this->exports->mayDownload($model, $actor)) {
            throw ResourceNotFoundException::make('That export was not found.');
        }

        $disk = Storage::disk((string) config('files.disk', 'object-storage'));
        $contents = $disk->get((string) $model->stored_file_id);

        if ($contents === null) {
            throw ResourceNotFoundException::make('That export was not found.');
        }

        /*
         * Audited as its own act. An export somebody queued and never fetched is a different fact
         * from one that left the building, and after an incident the second is the one that
         * matters.
         */
        $this->audit->record(
            $actor->subjectId,
            $model->is_person_level ? 'report.person-level-export-downloaded' : 'report.export-downloaded',
            'Export downloaded: '.$model->report,
            (string) $model->uuid,
        );

        $response = new StreamedResponse(static function () use ($contents): void {
            echo $contents;
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$model->report.'.csv"');
        // No intermediary keeps a copy of a welfare export.
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(ReportExport $export): array
    {
        return [
            'id' => $export->uuid,
            'report' => $export->report,
            'format' => $export->format,
            'filters' => $export->filters,
            'status' => $export->status,
            'is_person_level' => (bool) $export->is_person_level,
            'row_count' => $export->row_count === null ? null : (int) $export->row_count,
            'requested_at' => $export->requested_at?->toIso8601ZuluString(),
            'completed_at' => $export->completed_at?->toIso8601ZuluString(),
            // Stated so a client can tell somebody their download will stop working, rather than
            // letting them discover it on the day they need it.
            'expires_at' => $export->expires_at?->toIso8601ZuluString(),
            'is_downloadable' => $export->isDownloadable(),
            'failure_reason' => $export->failure_reason,
            // Deliberately absent: the storage key. There is no path in this payload to fetch
            // from anywhere but the gated endpoint.
        ];
    }

    /**
     * The filter set the frontend dashboard sends.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'barangay_id' => $request->query('barangay_id'),
            'program_id' => $request->query('program_id'),
            'status' => $request->query('status'),
            /*
             * Filtering to ONE named caseworker is permitted — it is how a worker sees their own
             * queue and how a supervisor reviews a caseload they are responsible for.
             *
             * There is no *grouping* by caseworker anywhere, and no endpoint returning one row
             * per worker. A queue length measures the cases somebody was given, not how well they
             * work (ADR 0026 §4).
             */
            'assigned_to' => $request->query('assigned_to'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
