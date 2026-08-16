<?php

declare(strict_types=1);

namespace Modules\Notification\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Events\Contracts\EventRegistrationPromoted;
use Modules\Notification\Application\ChannelRegistry;
use Modules\Notification\Application\NotifyApplicantOnCaseTransition;
use Modules\Notification\Application\NotifyRegistrantOnWaitlistPromotion;
use Modules\Notification\Infrastructure\Channels\DatabaseChannel;
use Modules\Notification\Infrastructure\Channels\FcmChannel;
use Modules\Notification\Infrastructure\Channels\NullChannel;
use Modules\Welfare\Contracts\CaseStatusChanged;

/**
 * Notification owns outbound dispatch, delivery receipts and channel preferences.
 *
 * IT DOES NOT OWN WHY A NOTIFICATION WAS TRIGGERED. Welfare decides that a case was approved;
 * this module decides how to tell somebody. That split is why nothing here imports a case, and
 * why a provider outage cannot reach welfare work.
 *
 * The registry is bound here so an environment swaps a real provider for a null one by binding
 * rather than by editing a service — which is what makes the whole path exercisable in a test
 * suite with no SMS bill and no Firebase project.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, static fn (): ChannelRegistry => new ChannelRegistry([
            new DatabaseChannel,
            new FcmChannel,
            // Email and SMS have no provider yet. `skipped`, never `sent` — a dashboard showing
            // "delivered" for a channel that does not exist tells an operator the family was told.
            new NullChannel('email'),
            new NullChannel('sms'),
        ]));
    }

    public function boot(): void
    {
        /*
         * Registered HERE, in the module that owns the notification, so Welfare stays ignorant of
         * who cares that a case moved. Removing this one line turns notifications off entirely
         * and changes nothing else in the system.
         */
        Event::listen(CaseStatusChanged::class, NotifyApplicantOnCaseTransition::class);

        /*
         * Same inversion, same reason: Events announces that a seat opened and knows nothing
         * about whether anybody is told. A push provider outage cannot roll back a promotion.
         */
        Event::listen(EventRegistrationPromoted::class, NotifyRegistrantOnWaitlistPromotion::class);
    }
}
