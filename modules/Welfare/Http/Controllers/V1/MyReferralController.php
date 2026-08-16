<?php

declare(strict_types=1);

namespace Modules\Welfare\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\AccountDirectory;
use Modules\Shared\Application\ActorContext;
use Modules\Shared\Exceptions\ResourceNotFoundException;
use Modules\Shared\Http\ApiResponse;
use Modules\Welfare\Application\ReferralService;
use Modules\Welfare\Domain\ReferralStatus;
use Modules\Welfare\Infrastructure\Eloquent\Referral;

/**
 * What an applicant is told about their own referrals (ADR 0021 §6).
 *
 * THE NARROWEST CITIZEN PROJECTION IN THIS SYSTEM, and deliberately so. A referral record holds
 * three things an applicant must not read:
 *
 *  * **the reason**, written for a receiving office in clinical terms a family should not meet
 *    as a JSON field — "suspected neglect", "unable to manage own affairs";
 *  * **the internal notes**, which are this office talking to itself about them;
 *  * **the destination contact**, because a named officer at another agency is a person with no
 *    relationship to this applicant, and handing over their number invites a call that agency
 *    never agreed to take.
 *
 * What remains is a status and a fixed sentence. The vocabulary promises nothing this office
 * controls: the MSWDO cannot make another agency act, so `acknowledged` and `in-progress` both
 * project as "referred" — telling somebody which desk their file sits on would identify the
 * handling worker there.
 *
 * `draft` projects as "preparing" rather than being hidden. A referral being prepared is a real
 * fact about somebody's case, and concealing it entirely would make the day it appears look like
 * the day the office started.
 */
final class MyReferralController
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly AccountDirectory $accounts,
    ) {}

    public function index(Request $request, ActorContext $actor): JsonResponse
    {
        $residentId = $actor->subjectId === null
            ? null
            : $this->accounts->residentIdFor($actor->subjectId);

        if ($residentId === null) {
            throw ResourceNotFoundException::make('No resident record is linked to this account yet.');
        }

        return ApiResponse::item([
            'referrals' => $this->referrals->forResident($residentId)
                ->map(fn (Referral $referral): array => $this->projection($referral))
                ->all(),
        ]);
    }

    /**
     * Additive, like every other citizen view here (ADR 0016 §5): a field is absent until
     * somebody decides it belongs.
     *
     * @return array<string, mixed>
     */
    private function projection(Referral $referral): array
    {
        /** @var ReferralStatus $status */
        $status = $referral->status;

        return [
            // Quoted back if they telephone this office about it. It carries nothing about them.
            'reference' => $referral->reference_number,
            /*
             * The office's NAME, and nothing else about it.
             *
             * An applicant genuinely needs to know they were referred to the district hospital
             * rather than to PESO — otherwise they cannot go. The contact person, the phone
             * number and the internal `destination_type` are all withheld.
             */
            'referred_to' => $referral->destination_name,
            'status' => $status->citizenStatus(),
            'status_message' => $status->citizenMessage(),
            'referred_on' => $referral->sent_at?->toDateString(),
        ];
    }
}
