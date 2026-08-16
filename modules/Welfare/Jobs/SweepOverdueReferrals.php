<?php

declare(strict_types=1);

namespace Modules\Welfare\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Modules\Welfare\Application\ReferralService;
use Modules\Welfare\Contracts\ReferralBecameOverdue;
use Modules\Welfare\Infrastructure\Eloquent\Referral;
use Modules\Welfare\Infrastructure\Eloquent\WelfareCase;

/**
 * Finds referrals this office said it would chase and has not heard about.
 *
 * Runs daily. Raises {@see ReferralBecameOverdue} for each, which TAB 19's tasks and TAB 20's
 * notifications will listen for — the acceptance criterion "overdue referrals can feed
 * Tasks/Notifications", built as a seam now rather than as a coupling later.
 *
 * IT READS THE SAME QUERY THE QUEUE DOES. `ReferralService::overdueQuery()` is the single
 * definition, used by the staff filter and by this job. Two definitions would eventually
 * disagree, and the discrepancy would be read as the job being broken long before anybody
 * suspected there were two.
 *
 * THE SWEEP CHANGES NOTHING. It writes no column and moves no status — a referral is not
 * "overdue" as a state, it is overdue as a *fact about today*, and storing that would make it
 * wrong the following morning unless something recomputed it. Deriving it means the answer is
 * always current and a missed run is a missed notification rather than a corrupted record.
 */
final class SweepOverdueReferrals implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string|null  $asOf  ISO date; passed for testability rather than read from the
     *                             clock inside, so a test does not have to travel through time
     *                             to describe what it means.
     */
    public function __construct(private readonly ?string $asOf = null) {}

    public function handle(ReferralService $referrals): int
    {
        $on = $this->asOf === null ? Carbon::now() : Carbon::parse($this->asOf);
        $raised = 0;

        /*
         * Chunked. The overdue set is small on a good day and large after a holiday week or a
         * disaster response, and the run that matters most is the one after the week nobody was
         * at their desk.
         */
        $referrals->overdueQuery($on)->chunkById(200, function ($batch) use ($on, &$raised): void {
            foreach ($batch as $referral) {
                /** @var Referral $referral */
                Event::dispatch(new ReferralBecameOverdue(
                    referralUuid: (string) $referral->uuid,
                    referenceNumber: (string) $referral->reference_number,
                    referredBySubjectId: $referral->referred_by,
                    caseUuid: $referral->welfare_case_id === null
                        ? null
                        : (string) WelfareCase::query()
                            ->whereKey($referral->welfare_case_id)->value('uuid'),
                    urgency: $referral->urgency->value,
                    daysOverdue: (int) $referral->follow_up_on?->diffInDays($on),
                ));

                $raised++;
            }
        });

        return $raised;
    }
}
