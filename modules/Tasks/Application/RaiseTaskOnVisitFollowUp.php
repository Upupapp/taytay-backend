<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Modules\Shared\Application\ActorContext;
use Modules\Tasks\Domain\TaskType;
use Modules\Tasks\Infrastructure\Eloquent\Task;
use Modules\Welfare\Contracts\VisitFollowUpDue;

/**
 * A field visit concluded with a next action and a date.
 *
 * The second seam TAB 17 published with no listener.
 *
 * THE ACTION IS THE TITLE, and it is safe to be: TAB 17 chose deliberately to put "return with the
 * barangay certificate request form" in the event and to leave the observations out. What
 * somebody must DO belongs in a queue; what a family said does not (Article 8.4).
 */
final class RaiseTaskOnVisitFollowUp
{
    public function __construct(private readonly TaskService $tasks) {}

    public function handle(VisitFollowUpDue $event): ?Task
    {
        $key = 'visit-follow-up:'.$event->visitUuid;

        if (Task::query()->where('automation_key', $key)->exists()) {
            return null;
        }

        return $this->tasks->open([
            'type' => TaskType::FieldVisit,
            'subject_type' => 'welfare.field-visit',
            'subject_id' => $event->visitUuid,
            'title' => $event->nextAction,
            // Whose visit it was, not whoever happened to write it up.
            'assigned_to' => $event->assignedToSubjectId,
            'due_on' => $event->dueOn,
            'raised_by_event' => 'visit.follow-up-due',
            'automation_key' => $key,
        ], ActorContext::system());
    }
}
