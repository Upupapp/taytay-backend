<?php

declare(strict_types=1);

namespace Modules\Reporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Reporting\Domain\ReportCatalog;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Shared\Application\WorkloadQueue;

/**
 * Produces the file (ADR 0026 §3).
 *
 * QUEUED, ALWAYS. The acceptance criterion is that a large export does not hold an HTTP request
 * open, and the reliable way to satisfy it is to have no inline path at all — not a size
 * threshold, which is a decision somebody eventually tunes wrong on the day the data grows.
 *
 * THE FILE GOES TO THE PRIVATE DISK. It is a copy of welfare data; it is never written to `public`
 * and never given a durable URL. Download runs through an authorization-gated endpoint that
 * re-checks permission (Article 8.5).
 *
 * IT REBUILDS THE SCOPE FROM THE SNAPSHOT, not from the requester's current permissions. The
 * export must contain what they were entitled to when they asked — a scope that widened in the
 * meantime must not retroactively widen a file, and one that narrowed must not silently produce
 * fewer rows than the request that was accepted.
 */
final class BuildReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @see WorkloadQueue — why this workload does not share a queue with the others. */
    private const QUEUE = WorkloadQueue::Exports;

    /**
     * Two attempts only. An export that fails twice is failing for a reason a third would
     * not fix, and each attempt rebuilds the whole file — so a third is mostly load.
     */
    public int $tries = 2;

    /**
     * Exponential backoff, in seconds per attempt.
     *
     * Widening gaps rather than a fixed delay: whatever made the first attempt fail is usually
     * still true a second later, and a tight retry loop turns one struggling dependency into a
     * self-inflicted denial of service against it (ADR 0036 §2).
     */
    public array $backoff = [60, 300];

    /**
     * Beyond this the job is hung rather than slow, and holding a worker helps nobody.
     *
     * Mirrors `WorkloadQueue::timeoutSeconds()`, which cannot be called from a property
     * initialiser; `QueueConventionsTest` fails the build if the two ever disagree.
     */
    public int $timeout = 900;

    public function __construct(private readonly string $exportUuid)
    {
        // Routed here rather than at every dispatch site: a job that must be queued
        // somewhere specific should not depend on each caller remembering where.
        $this->onQueue(self::QUEUE->value);
    }

    public function handle(): void
    {
        /** @var ReportExport|null $export */
        $export = ReportExport::query()->where('uuid', $this->exportUuid)->first();

        if ($export === null || $export->status !== 'queued') {
            return;
        }

        $export->forceFill(['status' => 'running'])->save();

        try {
            $report = ReportCatalog::from((string) $export->report);
            $rows = $this->rowsFor($report, (array) $export->filters, (array) $export->permission_context);

            $csv = $this->toCsv($rows);
            $key = 'exports/'.now()->format('Y/m').'/'.Str::uuid7().'.csv';

            Storage::disk((string) config('files.disk', 'object-storage'))->put($key, $csv, 'private');

            $export->forceFill([
                'status' => 'ready',
                'stored_file_id' => $key,
                'row_count' => count($rows),
                'completed_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            /*
             * The reason is recorded and the job does not keep the export in `running` forever.
             * An export stuck mid-flight is worse than a failed one: somebody waits for a file
             * that is never coming, and nothing tells them.
             */
            $export->forceFill([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 255, ''),
                'completed_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function rowsFor(ReportCatalog $report, array $filters, array $context): array
    {
        /*
         * Scope rebuilt from the SNAPSHOT taken at request time. A scope that widened since must
         * not retroactively widen this file.
         */
        $barangayIds = $context['scope']['barangay_ids'] ?? null;

        return match ($report) {
            ReportCatalog::CaseSummary => $this->caseSummary($filters, $barangayIds),
            ReportCatalog::ProgramUtilization => $this->programUtilization($filters),
            ReportCatalog::BarangayReach => $this->barangayReach($barangayIds),
            ReportCatalog::ReferralOutcomes => $this->referralOutcomes(),
            ReportCatalog::ReleaseManifest => $this->releaseManifest($filters, $barangayIds),
            ReportCatalog::EventRegistrants => $this->eventRegistrants($filters),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $barangayIds
     * @return list<array<string, mixed>>
     */
    private function caseSummary(array $filters, ?array $barangayIds): array
    {
        $query = DB::table('welfare_cases')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status');

        if ($barangayIds !== null) {
            $query->whereIn('barangay_id', $barangayIds);
        }

        return $query->get()->map(static fn (object $row): array => [
            'status' => (string) $row->status,
            'total' => (int) $row->total,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function programUtilization(array $filters): array
    {
        return DB::table('releases')
            ->whereIn('status', ['released', 'completed'])
            ->selectRaw('program_code, COUNT(*) as total, '
                .'SUM(CASE WHEN kind = ? THEN amount_centavos ELSE 0 END) as centavos', ['cash'])
            ->groupBy('program_code')
            ->get()
            ->map(static fn (object $row): array => [
                'program_code' => (string) $row->program_code,
                'releases' => (int) $row->total,
                'released_centavos' => (int) $row->centavos,
                'currency' => 'PHP',
            ])->all();
    }

    /**
     * @param  list<int>|null  $barangayIds
     * @return list<array<string, mixed>>
     */
    private function barangayReach(?array $barangayIds): array
    {
        $query = DB::table('welfare_cases')
            ->whereNull('deleted_at')
            ->selectRaw('barangay_id, COUNT(*) as total')
            ->groupBy('barangay_id');

        if ($barangayIds !== null) {
            $query->whereIn('barangay_id', $barangayIds);
        }

        return $query->get()->map(static fn (object $row): array => [
            'barangay_id' => $row->barangay_id === null ? '' : (string) $row->barangay_id,
            'total' => (int) $row->total,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function referralOutcomes(): array
    {
        return DB::table('referrals')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'total' => (int) $row->total,
            ])->all();
    }

    /**
     * The door list for one event (ADR 0031 §6).
     *
     * MINIMAL FIELDS, and the omissions are the design. A reference, a name, a status and whether
     * they turned up — everything a volunteer at a covered court needs, and nothing that turns a
     * mislaid printout into a disclosure. No address, no contact number, no barangay, no
     * household, no vulnerability factor, and no staff note: the note is written in the office's
     * voice about the person, and it must not travel on a sheet of paper that ends up in a bag.
     *
     * Scoped to one event, and an export naming none returns nothing: a request that omitted the
     * filter would otherwise produce every registrant of every event the LGU has ever run.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function eventRegistrants(array $filters): array
    {
        $eventId = $filters['event_id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            return [];
        }

        $rows = DB::table('event_registrations')
            ->join('events', 'events.id', '=', 'event_registrations.event_id')
            // Joined by UUID because `resident_id` is a cross-module reference, not a foreign key
            // (Article 2.2). A left join, so a registrant whose resident record was merged away
            // still appears — the seat was real.
            ->leftJoin('residents', 'residents.uuid', '=', 'event_registrations.resident_id')
            ->where('events.uuid', $eventId)
            ->select([
                'event_registrations.reference',
                'residents.first_name',
                'residents.last_name',
                'event_registrations.status',
                'event_registrations.attendance',
                'event_registrations.registered_at',
            ])
            // Seated first, then the queue in order — the order the list is read in at a door,
            // and stable so two copies printed an hour apart match line for line.
            ->orderByRaw("CASE event_registrations.status WHEN 'registered' THEN 0 WHEN 'waitlisted' THEN 1 ELSE 2 END")
            ->orderBy('event_registrations.id')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'reference' => $row->reference,
            'name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            'status' => $row->status,
            'attendance' => $row->attendance,
            'registered_at' => $row->registered_at,
        ])->all();
    }

    /**
     * THE ONE PERSON-LEVEL REPORT: a distribution manifest.
     *
     * It names people because a payout table needs a printed list of who is expected. It carries
     * the reference, the beneficiary identifier and the amount — and deliberately **not** the
     * case narrative, the programme's eligibility reasoning, or anything about why they qualified.
     * A manifest is a list of who is coming, not a summary of their circumstances.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $barangayIds
     * @return list<array<string, mixed>>
     */
    private function releaseManifest(array $filters, ?array $barangayIds): array
    {
        $query = DB::table('releases')
            ->join('welfare_cases', 'welfare_cases.id', '=', 'releases.welfare_case_id')
            ->select([
                'releases.reference_number',
                'releases.resident_id',
                'releases.program_code',
                'releases.amount_centavos',
                'releases.currency',
                'releases.status',
                'releases.scheduled_for',
            ])
            // Ordered by reference so two copies printed an hour apart match line for line
            // (ADR 0023 §6).
            ->orderBy('releases.reference_number');

        if ($barangayIds !== null) {
            $query->whereIn('welfare_cases.barangay_id', $barangayIds);
        }

        if (! empty($filters['batch_id'])) {
            $query->whereIn('releases.release_batch_id', function ($sub) use ($filters): void {
                $sub->select('id')->from('release_batches')->where('uuid', $filters['batch_id']);
            });
        }

        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $value): string => (string) $value, $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
