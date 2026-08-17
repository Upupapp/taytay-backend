<?php

declare(strict_types=1);

namespace Modules\Content\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\Content\Domain\PostStatus;
use Modules\Content\Infrastructure\Eloquent\NewsfeedPost;
use Modules\Shared\Application\WorkloadQueue;

/**
 * Publishes posts whose time has come (ADR 0028 §3).
 *
 * THE ACCEPTANCE CRITERION — a scheduled post publishes at most once — is held by a **conditional
 * UPDATE**, not by a lock and not by a check-then-write.
 *
 * `UPDATE ... WHERE status = 'scheduled' AND publish_at <= now` is atomic in every engine this
 * runs on: two workers racing on the same row produce one update and one no-op, because the second
 * one's `WHERE` no longer matches. There is no window between reading and writing for a second
 * worker to fit into, which is the window a `SELECT` followed by a `save()` always has.
 *
 * It is also idempotent under replay: running the sweep five times in a minute publishes each post
 * once, because after the first run the condition is false.
 *
 * A missed run is harmless — the next one catches up, and the post carries the `publish_at` it was
 * always going to have. That is why this is a sweep rather than a per-post delayed job: a delayed
 * job lost in a queue restart is an announcement that never goes out and nothing to notice it.
 */
final class PublishScheduledPosts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @see WorkloadQueue — why this workload does not share a queue with the others. */
    private const QUEUE = WorkloadQueue::ScheduledContent;

    /**
     * ONE ATTEMPT, deliberately. The sweep is idempotent and runs every minute, so the next
     * run IS the retry — and a retried sweep racing the next scheduled one is two sweeps
     * where the design assumes one.
     */
    public int $tries = 1;

    /**
     * Exponential backoff, in seconds per attempt.
     *
     * Widening gaps rather than a fixed delay: whatever made the first attempt fail is usually
     * still true a second later, and a tight retry loop turns one struggling dependency into a
     * self-inflicted denial of service against it (ADR 0036 §2).
     */
    public array $backoff = [];

    /**
     * Beyond this the job is hung rather than slow, and holding a worker helps nobody.
     *
     * Mirrors `WorkloadQueue::timeoutSeconds()`, which cannot be called from a property
     * initialiser; `QueueConventionsTest` fails the build if the two ever disagree.
     */
    public int $timeout = 120;

    public function __construct(private readonly ?string $asOf = null)
    {
        // Routed here rather than at every dispatch site: a job that must be queued
        // somewhere specific should not depend on each caller remembering where.
        $this->onQueue(self::QUEUE->value);
    }

    /**
     * @return int how many posts went live
     */
    public function handle(): int
    {
        $now = $this->asOf === null ? Carbon::now() : Carbon::parse($this->asOf);

        /*
         * `published_at` is stamped in the same statement, so a post can never be `published`
         * with no record of when it happened — which is the state a separate follow-up write
         * leaves behind when it fails.
         */
        return NewsfeedPost::query()
            ->where('status', PostStatus::Scheduled->value)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $now)
            ->update([
                'status' => PostStatus::Published->value,
                'published_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
