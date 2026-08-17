<?php

declare(strict_types=1);

namespace Modules\Shared\Application;

/**
 * The named queues, by workload class (ADR 0036 §1).
 *
 * **EVERY JOB IN THIS SYSTEM RAN ON `default` BEFORE THIS.** Five jobs, one queue — which works
 * until the day somebody requests a large export, and then a notification telling a family their
 * assistance was approved sits behind a CSV build for four minutes.
 *
 * That is the argument for named queues, and it is not throughput. It is that these workloads have
 * **different consequences when they are late**:
 *
 *  * a **notification** that is late is a family who did not hear they were approved;
 *  * an **export** that is late is somebody refreshing a page;
 *  * **media** that is late is a post published without its picture;
 *  * **scheduled content** that is late is an advisory that went out after the event it warned
 *    about.
 *
 * A single queue makes the slowest of those the latency of all of them. Separating them lets a
 * worker fleet be shaped by what actually matters — and, more importantly, lets a stuck export
 * stop being an outage for everything else.
 *
 * THE NAMES ARE THE MASTER COMMAND'S, verbatim, so that the worker configuration in the runbook
 * and the code cannot drift into different vocabularies.
 */
enum WorkloadQueue: string
{
    /** Anything with no better home. Kept deliberately small. */
    case Default = 'default';

    /**
     * Outbound dispatch. The one queue where lateness is felt by a resident rather than by staff.
     */
    case Notifications = 'notifications';

    /**
     * Report and registrant exports. Slow by nature — a CSV of a whole barangay — and the reason
     * everything else needed to move off `default`.
     */
    case Exports = 'exports';

    /** Image derivation and upload processing. Bursty, CPU-bound, and nobody is waiting on it. */
    case Media = 'media';

    /**
     * Scheduled publication sweeps.
     *
     * Separate from `default` because a sweep that runs late publishes an advisory after the thing
     * it warned about — and because a sweep queued behind a backlog is indistinguishable from a
     * sweep that never ran.
     */
    case ScheduledContent = 'scheduled-content';

    /** Outbound calls to somebody else's system, which fail in somebody else's ways. */
    case Integrations = 'integrations';

    /**
     * How long a worker may spend on one job of this class before it is considered hung.
     *
     * Per class rather than global, because the right answer differs by an order of magnitude: a
     * push notification that has not finished in thirty seconds is stuck, while an export of ten
     * thousand rows legitimately takes minutes. One global timeout is either too short for the
     * export or useless for the notification.
     */
    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::Notifications => 30,
            self::Integrations => 60,
            self::Default, self::ScheduledContent => 120,
            self::Media => 300,
            self::Exports => 900,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $queue): string => $queue->value, self::cases());
    }
}
