<?php

declare(strict_types=1);

namespace Modules\Files\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Files\Contracts\ScanStatus;
use Modules\Files\Infrastructure\Eloquent\StoredFile;
use Modules\Shared\Application\WorkloadQueue;

/**
 * Post-upload work: malware scan, then metadata.
 *
 * QUEUED BECAUSE IT IS SLOW AND BECAUSE THE UPLOAD MUST NOT DEPEND ON IT. A resident on a weak
 * mobile connection has already paid for the transfer; making them wait for a scanner as well
 * turns a working upload into a timeout, and a timeout into a second upload of the same file.
 *
 * DISPATCHED `afterCommit`. Dispatching inside the transaction would let a worker pick the job up
 * before the row exists and fail on a file that is about to be perfectly fine.
 *
 * THE JOB CARRIES A UUID, NOT A MODEL. A serialised model in a payload is a copy of the record as
 * it was when queued, and this one is about to be mutated by the very job holding it.
 *
 * The scanner itself is deployment configuration (gap G-25). What is wired here is everything
 * around it: the state machine, the queue, the failure path and the audit consequence. Turning a
 * scanner on is then a config change, not a code change — and until one is configured the status
 * is `skipped`, which is deliberately not `clean`.
 */
final class ProcessUploadedFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @see WorkloadQueue — why this workload does not share a queue with the others. */
    private const QUEUE = WorkloadQueue::Media;

    /**
     * Scanning and metadata are both retryable, and a file that cannot be processed settles
     * at `pending` rather than `clean` — so exhausting the retries fails safe.
     */
    public int $tries = 3;

    /**
     * Exponential backoff, in seconds per attempt.
     *
     * Widening gaps rather than a fixed delay: whatever made the first attempt fail is usually
     * still true a second later, and a tight retry loop turns one struggling dependency into a
     * self-inflicted denial of service against it (ADR 0036 §2).
     */
    public array $backoff = [15, 60, 180];

    /**
     * Beyond this the job is hung rather than slow, and holding a worker helps nobody.
     *
     * Mirrors `WorkloadQueue::timeoutSeconds()`, which cannot be called from a property
     * initialiser; `QueueConventionsTest` fails the build if the two ever disagree.
     */
    public int $timeout = 300;

    public function __construct(private readonly string $fileUuid)
    {
        // Routed here rather than at every dispatch site: a job that must be queued
        // somewhere specific should not depend on each caller remembering where.
        $this->onQueue(self::QUEUE->value);
    }

    public function handle(): void
    {
        /** @var StoredFile|null $file */
        $file = StoredFile::query()->where('uuid', $this->fileUuid)->first();

        // Purged between upload and processing, or the upload was rolled back. Nothing to do,
        // and nothing wrong.
        if ($file === null || $file->purged_at !== null) {
            return;
        }

        $this->scan($file);

        // Metadata is only worth reading from a file that is not quarantined. Parsing an image
        // known to be malicious is the one thing this job must not do.
        if ($file->scan_status !== ScanStatus::Infected) {
            $this->readMetadata($file);
        }
    }

    /**
     * A file that cannot be processed must not be left looking scanned.
     *
     * After the retries are exhausted the status returns to `pending`, which the download path
     * treats as "serve internally, never share outward". Marking it clean on failure would be
     * the worst possible default; marking it infected would quarantine a file over an
     * infrastructure problem.
     */
    public function failed(\Throwable $exception): void
    {
        StoredFile::query()
            ->where('uuid', $this->fileUuid)
            ->where('scan_status', ScanStatus::Pending->value)
            ->update(['scan_detail' => 'Processing failed; awaiting a scan.']);
    }

    private function scan(StoredFile $file): void
    {
        $scanner = config('files.scanner');

        if ($scanner === null || $scanner === '' || $scanner === 'none') {
            $file->forceFill([
                'scan_status' => ScanStatus::Skipped,
                'scan_detail' => 'No scanner configured in this environment.',
                'scanned_at' => now(),
            ])->save();

            return;
        }

        /*
         * The seam. A configured scanner is invoked through a container binding so that adding
         * one is a provider registration rather than an edit here — and so that this job stays
         * testable without an antivirus daemon.
         */
        $verdict = app($scanner)->scan(
            Storage::disk((string) $file->disk)->path((string) $file->storage_key),
        );

        $file->forceFill([
            'scan_status' => $verdict === true ? ScanStatus::Clean : ScanStatus::Infected,
            'scan_detail' => $verdict === true ? null : 'Flagged by the malware scanner.',
            'scanned_at' => now(),
        ])->save();
    }

    /**
     * Dimensions for an image, page count for a PDF.
     *
     * Read from the stored bytes rather than accepted from the uploader, like everything else
     * about a file here. Failure is silent by design: metadata is a convenience for the console,
     * and an unreadable EXIF header is not a reason to fail an upload somebody already made.
     */
    private function readMetadata(StoredFile $file): void
    {
        $contents = Storage::disk((string) $file->disk)->get((string) $file->storage_key);

        if ($contents === null) {
            return;
        }

        if (str_starts_with((string) $file->mime_type, 'image/')) {
            $size = @getimagesizefromstring($contents);

            if ($size !== false) {
                $file->forceFill(['width' => $size[0], 'height' => $size[1]])->save();
            }

            return;
        }

        // Counting `/Type /Page` objects: approximate, and adequate for showing "3 pages" next
        // to a scanned form. Nothing decides anything on this number.
        $pages = preg_match_all('/\/Type\s*\/Page[^s]/', $contents);

        if ($pages > 0) {
            $file->forceFill(['page_count' => min($pages, 65535)])->save();
        }
    }
}
