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

    public function __construct(private readonly ?string $asOf = null) {}

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
