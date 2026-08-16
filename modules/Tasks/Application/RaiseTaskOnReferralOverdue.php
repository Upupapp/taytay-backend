<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Modules\Shared\Application\ActorContext;
use Modules\Tasks\Domain\TaskPriority;
use Modules\Tasks\Domain\TaskType;
use Modules\Tasks\Infrastructure\Eloquent\Task;
use Modules\Welfare\Contracts\ReferralBecameOverdue;

/**
 * A referral this office undertook to chase has passed its date with no response.
 *
 * TAB 16 published `ReferralBecameOverdue` with no listener, deliberately: a seam built before it
 * is needed is a seam, and one built after is a refactor. This is the listener.
 *
 * IT RAISES WORK AND CHANGES NOTHING. The referral's status is untouched — it is still `sent`,
 * because nothing has actually happened to it. What has happened is that somebody now owes a
 * phone call, and that is a task (ADR 0024 §3).
 *
 * ONE OPEN TASK PER REFERRAL. The sweep runs nightly and the referral stays overdue until
 * somebody chases it, so without the automation key the queue would grow a fresh copy every
 * morning — and within a fortnight it is fourteen identical rows and nobody trusts it.
 */
final class RaiseTaskOnReferralOverdue
{
    public function __construct(private readonly TaskService $tasks) {}

    public function handle(ReferralBecameOverdue $event): ?Task
    {
        $key = 'referral-overdue:'.$event->referralUuid;

        // The unique index is the real guard; this read makes the ordinary case cheap rather than
        // a caught constraint violation on every sweep after the first.
        if (Task::query()->where('automation_key', $key)->exists()) {
            return null;
        }

        return $this->tasks->open([
            'type' => TaskType::ReferralFollowUp,
            'subject_type' => 'welfare.referral',
            'subject_id' => $event->referralUuid,
            /*
             * The REFERENCE NUMBER, never the client's name or the reason they were referred.
             * A queue title is read by everyone who can see the queue; the reference is enough to
             * find the file and discloses nothing to somebody who cannot open it.
             */
            'title' => 'Chase referral '.$event->referenceNumber.' — no response yet',
            // Whoever made the referral owes the chase.
            'assigned_to' => $event->referredBySubjectId,
            'priority' => $event->urgency === 'urgent' ? TaskPriority::High->value : TaskPriority::Normal->value,
            'due_on' => now()->toDateString(),
            'raised_by_event' => 'referral.overdue',
            'automation_key' => $key,
        ], ActorContext::system());
    }
}
