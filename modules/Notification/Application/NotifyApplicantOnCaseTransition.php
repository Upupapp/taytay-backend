<?php

declare(strict_types=1);

namespace Modules\Notification\Application;

use Modules\Identity\Application\AccountDirectory;
use Modules\Welfare\Contracts\CaseStatusChanged;

/**
 * Tells an applicant that their own case moved (ADR 0025 §6).
 *
 * THE TEXT IS THE PROJECTED CITIZEN MESSAGE, never the internal one. `CaseStatus::citizenMessage()`
 * already exists and is already the sentence the office is willing to say to the person — it is
 * what `me/cases` returns. Composing a second sentence here would be a second place the wording
 * could drift, and the drift would show up as a notification saying something the app does not.
 *
 * NOT EVERY TRANSITION IS TOLD. A case moving between `assessment` and `endorsed` is the office
 * moving paper between desks; notifying somebody each time teaches them the notifications are
 * noise, and the one that matters arrives among fourteen that did not.
 *
 * THIS LISTENER DECIDES *HOW* TO TELL, NEVER *WHETHER THE CASE MOVED*. Welfare decided that. If
 * this throws, the case has still moved — which is why the notifier only writes a row and queues
 * a job.
 */
final class NotifyApplicantOnCaseTransition
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly AccountDirectory $accounts,
    ) {}

    public function handle(CaseStatusChanged $event): void
    {
        if (! $event->isWorthTellingTheApplicant()) {
            return;
        }

        $accounts = $this->accounts->accountIdsForResident($event->residentUuid);

        // A walk-in applicant with no account cannot be notified, and that is not an error —
        // they were told at the counter.
        if ($accounts === []) {
            return;
        }

        /*
         * Every account acting for this resident, because a daughter applying for her mother is a
         * real and common arrangement (ADR 0013 §5) — and the person who filed is the person
         * watching for the answer.
         */
        foreach ($accounts as $account) {
            $this->notifyOne($account, $event);
        }
    }

    private function notifyOne(string $account, CaseStatusChanged $event): void
    {
        $this->notifier->notify(
            $account,
            'case.status-changed',
            [
                'title' => 'Update on your request',
                // The projected sentence, taken from the domain rather than written here.
                'body' => $event->citizenMessage,
                'subject_type' => 'welfare.case',
                'subject_id' => $event->caseUuid,
                /*
                 * A scheduled release is a mandatory service notice: somebody who switched
                 * notifications off must still be told when and where to collect their money.
                 */
                'category' => $event->isMandatoryNotice ? 'mandatory' : 'optional',
            ],
            config('notification.default_channels', ['database']),
        );
    }
}
