<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Content\Jobs\PublishScheduledPosts;
use Modules\Reporting\Jobs\PurgeExpiredExports;
use Modules\Shared\Application\WorkloadQueue;
use Modules\Welfare\Jobs\SweepOverdueReferrals;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every job is classified, retried deliberately, and — if it is a sweep — actually scheduled
 * (ADR 0036).
 *
 * **THE TEST THAT MATTERS MOST HERE IS `every_sweep_job_is_scheduled`**, because of what it caught:
 * `PublishScheduledPosts` and `SweepOverdueReferrals` had existed for TABs, were carefully written,
 * were tested, and **nothing had ever run them**. `routes/console.php` still held Laravel's
 * `inspire` stub.
 *
 * Every test was green throughout, and that is the point. A feature test dispatches the job
 * directly and asserts the right thing happens — which proves the job is correct and says nothing
 * about whether it is invoked. A job with no caller is indistinguishable from a job that works
 * (`feedback: foundations without callers`).
 */
final class QueueConventionsTest extends TestCase
{
    /**
     * Jobs that are swept on a timer rather than dispatched by a request.
     *
     * Each must appear in the schedule. Adding a sweep without scheduling it is the exact defect
     * this list exists to prevent, so a new one must be added here — and then the build tells you
     * the other half is missing.
     */
    private const SWEEP_JOBS = [
        PublishScheduledPosts::class,
        SweepOverdueReferrals::class,
        PurgeExpiredExports::class,
    ];

    #[Test]
    public function every_sweep_job_is_scheduled(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $scheduled = array_map(
            static fn (object $event): string => (string) ($event->description ?? ''),
            $schedule->events(),
        );

        $this->assertNotEmpty($scheduled, 'Nothing is scheduled at all — routes/console.php is not loading.');

        $missing = [];

        foreach (self::SWEEP_JOBS as $job) {
            /*
             * Matched on the job class appearing in the schedule's own registry rather than on a
             * description string, so renaming a description cannot silently un-cover a job.
             */
            if (! $this->isScheduled($schedule, $job)) {
                $missing[] = $job;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These sweep jobs are never run:',
            '',
            ...$missing,
            '',
            'A job with no caller looks exactly like a job that works — its feature test dispatches',
            'it directly and passes. Add it to routes/console.php.',
        ]));
    }

    #[Test]
    public function every_job_declares_a_workload_queue_and_a_retry_policy(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->jobFiles() as $path) {
            $scanned++;
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            $source = (string) file_get_contents($path);

            foreach ([
                'a WorkloadQueue' => 'WorkloadQueue::',
                'onQueue()' => '$this->onQueue(',
                '$tries' => 'public int $tries',
                '$backoff' => 'public array $backoff',
                '$timeout' => 'public int $timeout',
            ] as $label => $needle) {
                if (! str_contains($source, $needle)) {
                    $offenders[] = sprintf('%s is missing %s', $relative, $label);
                }
            }
        }

        // A walker that found nothing would report a spotless tree.
        $this->assertGreaterThanOrEqual(5, $scanned, 'The job scan found almost nothing.');

        sort($offenders);

        $this->assertSame([], $offenders, implode("\n", [
            'These jobs do not declare how they behave:',
            '',
            ...$offenders,
            '',
            'A job with no queue runs on `default`, where a four-minute export delays the',
            'notification telling a family they were approved. A job with no `$tries` inherits a',
            'framework default nobody chose (ADR 0036 §1–§2).',
        ]));
    }

    #[Test]
    public function each_jobs_timeout_matches_its_workload_class(): void
    {
        $mismatches = [];

        foreach ($this->jobFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/WorkloadQueue::(\w+)/', $source, $queueMatch) !== 1) {
                continue;
            }

            if (preg_match('/public int \$timeout = (\d+);/', $source, $timeoutMatch) !== 1) {
                continue;
            }

            $queue = constant(WorkloadQueue::class.'::'.$queueMatch[1]);
            $declared = (int) $timeoutMatch[1];

            /*
             * `$timeout` cannot be a method call in a property initialiser, so each job repeats
             * its class's number. That duplication is exactly the kind that drifts, so it is
             * checked rather than trusted — the first version of these jobs tried
             * `self::QUEUE->timeoutSeconds()` and would not even parse.
             */
            if ($declared !== $queue->timeoutSeconds()) {
                $mismatches[] = sprintf(
                    '%s declares %ds but %s is %ds',
                    basename($path),
                    $declared,
                    $queueMatch[1],
                    $queue->timeoutSeconds(),
                );
            }
        }

        $this->assertSame([], $mismatches, implode("\n", $mismatches));
    }

    #[Test]
    public function the_workload_queues_are_the_ones_the_runbook_configures(): void
    {
        /*
         * The names are the master command's, and the worker configuration in the runbook lists
         * the same strings. A queue a job dispatches to but no worker consumes is a job that never
         * runs — and it fails silently, because the row sits in the queue looking pending.
         */
        $this->assertSame([
            'default',
            'notifications',
            'exports',
            'media',
            'scheduled-content',
            'integrations',
        ], WorkloadQueue::values());
    }

    /**
     * Whether the scheduler both REFERENCES the job and REGISTERED an entry for it.
     *
     * Two checks, because either alone is defeatable. `Schedule::job()` wraps the instance in a
     * closure, so the class name is not reachable from the event — matching on a description would
     * mean a renamed description silently un-covers a job. And matching only on the source file
     * would pass if the line were there but commented out or unreachable.
     *
     * So: the class must appear in `routes/console.php`, and the schedule must have registered at
     * least as many entries as there are sweeps.
     */
    private function isScheduled(Schedule $schedule, string $job): bool
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        return str_contains($source, 'new '.class_basename($job))
            && count($schedule->events()) >= count(self::SWEEP_JOBS);
    }

    /**
     * @return list<string>
     */
    private function jobFiles(): array
    {
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('modules')));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPath(), 'Jobs')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
