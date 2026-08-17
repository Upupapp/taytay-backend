<?php

declare(strict_types=1);

namespace Modules\Reporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Infrastructure\Eloquent\ReportExport;
use Modules\Shared\Application\WorkloadQueue;

/**
 * Deletes the file an expired export produced (ADR 0036 §3).
 *
 * **THE FILE GOES; THE ROW STAYS.** That distinction is the whole design.
 *
 * A person-level export lives 24 hours and an aggregate one a week (ADR 0026 §3), because once a
 * spreadsheet of a barangay's beneficiaries is on a laptop none of this system's authorization
 * applies to it. An `expires_at` column that nothing acts on is a comment, not a retention rule.
 *
 * But the **record** of the export is evidence: who asked, what they asked for, and what they were
 * allowed to see at that moment. Deleting the row along with the file would destroy the answer to
 * *"why does this spreadsheet exist"* at exactly the moment the spreadsheet outlives the system —
 * which is the case where somebody is asking.
 *
 * So the row is marked `expired`, keeps its requester, its filters and its permission snapshot,
 * and loses only the bytes.
 *
 * NOT GOVERNED BY THE UNAPPROVED RETENTION SCHEDULE, and the difference matters. ADR 0034 §5 stops
 * every scheduled deletion of a *record* until the DPO approves a period. This deletes a derived
 * file whose lifetime was chosen by the export design itself and is already the shortest in the
 * system — waiting for approval would mean holding person-level caseload copies indefinitely,
 * which is the opposite of what the pending approval is protecting.
 */
final class PurgeExpiredExports implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const QUEUE = WorkloadQueue::ScheduledContent;

    /**
     * One attempt. The sweep is idempotent and runs hourly, so the next run is the retry.
     */
    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(self::QUEUE->value);
    }

    public function handle(): void
    {
        ReportExport::query()
            ->whereNotNull('stored_file_id')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            // Bounded per run. An hourly sweep that tried to delete a year's backlog in one pass
            // would hold a worker for its whole timeout and then be killed having finished none of
            // it; a bounded batch makes progress every hour instead.
            ->limit(500)
            ->get()
            ->each(function (ReportExport $export): void {
                $key = (string) $export->stored_file_id;

                if ($key !== '') {
                    /*
                     * Deleted before the row is updated. The other order would clear the pointer
                     * first and leave an orphaned file nobody can find — and an orphaned copy of a
                     * caseload is worse than one whose location is recorded.
                     */
                    Storage::disk((string) config('files.disk', 'object-storage'))->delete($key);
                }

                $export->forceFill([
                    'status' => 'expired',
                    // The pointer goes; everything explaining the request stays.
                    'stored_file_id' => null,
                ])->save();
            });
    }
}
