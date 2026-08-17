<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Modules\Content\Jobs\PublishScheduledPosts;
use Modules\Reporting\Jobs\PurgeExpiredExports;
use Modules\Welfare\Jobs\SweepOverdueReferrals;

/*
 * The scheduler (ADR 0036 §3).
 *
 * **BEFORE THIS FILE, TWO OF THESE JOBS EXISTED AND NOTHING RAN THEM.**
 *
 * `PublishScheduledPosts` was written in TAB 23 with a careful conditional update so a scheduled
 * post publishes at most once. `SweepOverdueReferrals` was written in TAB 16 to raise a task when
 * a referral goes unanswered. Both were tested, both worked when invoked, and neither was ever
 * invoked — this file still held Laravel's `inspire` stub.
 *
 * That is a particular kind of failure worth naming, because every test was green: a job with no
 * caller looks exactly like a job that is working. The feature tests dispatched it directly and
 * asserted the right thing happened, which proves the job is correct and says nothing about
 * whether it runs. The same shape as `feedback: foundations without callers`.
 *
 * ── WHY EVERY ENTRY IS GUARDED ────────────────────────────────────────────────────────
 *
 * `withoutOverlapping()` on all of them. A sweep that is still running when the next one fires is
 * two sweeps against the same rows, and while each is individually idempotent, two concurrent runs
 * of the publish sweep would each see the same due post — the conditional update means only one
 * wins, but the second wastes a worker on every post in the batch.
 *
 * `onOneServer()` because production will eventually have two API nodes behind a NodeBalancer
 * (ADR 0004), and both would run the scheduler. Without this the day a second node is added is the
 * day every sweep starts running twice.
 *
 * `runInBackground()` is deliberately ABSENT, and the reason is worth stating because it looks
 * like an omission. `Schedule::job()` only *dispatches* — the work happens on a queue worker, so
 * the scheduler returns immediately and there is nothing to background. Laravel refuses the call
 * outright, which is how this was discovered: the first version of this file had it, and
 * `schedule:list` would not even render.
 *
 * `Schedule::command()` entries below are different — those DO run inline — but both are fast
 * prunes and neither is worth a background process.
 */

/*
 * Every minute. A post scheduled for 09:00 that publishes at 09:04 has missed the point of being
 * scheduled — somebody chose that time because of something happening at it.
 *
 * The job is idempotent by conditional update (ADR 0028 §4), so a minute-by-minute sweep is safe
 * and a missed minute is harmless: the next run picks up whatever the last one did not.
 */
Schedule::job(new PublishScheduledPosts)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('newsfeed:publish-scheduled')
    ->description('Publishes newsfeed posts whose scheduled time has arrived.');

/*
 * Hourly. A referral going overdue is measured in days, so checking every minute would be sixty
 * times the work for a result that changes once a day — and the task it raises lands in a queue a
 * human looks at, not a screen somebody is watching.
 */
Schedule::job(new SweepOverdueReferrals)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('welfare:sweep-overdue-referrals')
    ->description('Raises a task for referrals that have gone unanswered past their due date.');

/*
 * Hourly, and this one DELETES.
 *
 * A person-level export lives 24 hours (ADR 0026 §3) and an aggregate one a week. Past that the
 * file is a copy of welfare data behind a URL somebody bookmarked, and the retention is only real
 * if something enforces it — an expiry timestamp that nothing acts on is a comment.
 *
 * Note the asymmetry with ADR 0034 §5: the *record* retention schedule is unapproved and nothing
 * purges records. This purges only the **produced file**, whose lifetime was set by the export
 * design itself rather than by a legal retention period, and the export row survives it so the
 * audit trail still says who asked for what.
 */
Schedule::job(new PurgeExpiredExports)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('reporting:purge-expired-exports')
    ->description('Removes the produced file of an export whose retention window has passed.');

/*
 * Daily. Sanctum's own command, removing tokens whose expiry has passed.
 *
 * Late in the evening rather than at midnight: midnight is when every scheduled job everybody ever
 * wrote runs at once, and this one has no reason to be in that crowd.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('23:20')
    ->onOneServer()
    ->name('identity:prune-expired-tokens')
    ->description('Removes personal access tokens that expired more than a day ago.');

/*
 * Daily. Failed jobs older than a week.
 *
 * A WEEK, NOT A DAY. The failed-jobs table is how somebody finds out why a notification never
 * arrived, and that question is usually asked several days after the fact — by a resident who
 * waited, then asked at the counter, then was told somebody would check.
 */
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('23:40')
    ->onOneServer()
    ->name('queue:prune-failed')
    ->description('Removes failed job records older than a week.');
